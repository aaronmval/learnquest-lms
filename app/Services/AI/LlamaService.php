<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Low-level client for chat-completions via the Routeway OpenAI-compatible
 * API. Handles auth, transport, retries, and a same-account fallback model
 * — no application-specific prompt or business logic belongs here.
 */
class LlamaService
{
    /** Max characters of a request/response body kept in a single log line. */
    private const LOG_PREVIEW_CHARS = 1500;

    /** Gateway/transient statuses worth retrying — never 4xx (retrying won't fix a bad request or bad key). */
    private const RETRYABLE_STATUSES = [502, 503, 504];

    /** Per-call limit options consumed here — never sent to the API. */
    private const LIMIT_OPTIONS = ['timeout', 'max_retries', 'fallback_timeout', 'fallback_max_retries', 'fallback_only'];

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim(config('services.routeway.base_url'), '/').'/',
            'timeout' => config('services.routeway.timeout', 60),
        ]);
    }

    /**
     * Send a chat-completion request. Tries the configured primary model
     * (with retries on transient failures), then falls back to a secondary
     * model on the same Routeway account if the primary is unavailable.
     *
     * Latency-sensitive callers can tighten the limits per call with
     * `timeout` / `max_retries` (primary) and `fallback_timeout` /
     * `fallback_max_retries` (fallback), so a slow primary hands over to the
     * fallback quickly. `fallback_only` skips the primary and asks the
     * fallback model directly — for callers whose primary reply arrived but
     * failed their own validation. Any other option is passed through to
     * the API body.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string}
     *
     * @throws LlamaApiException
     */
    public function chat(array $messages, array $options = []): array
    {
        $requestId = (string) Str::uuid();
        $log = Log::channel('ai');

        $limits = array_intersect_key($options, array_flip(self::LIMIT_OPTIONS));
        $options = array_diff_key($options, $limits);
        $primaryLimits = ['timeout' => $limits['timeout'] ?? null, 'max_retries' => $limits['max_retries'] ?? null];
        $fallbackLimits = ['timeout' => $limits['fallback_timeout'] ?? null, 'max_retries' => $limits['fallback_max_retries'] ?? null];

        $apiKey = config('services.routeway.api_key');
        $primaryModel = config('services.routeway.model');
        $fallbackModel = config('services.routeway.fallback_model');

        if (empty($apiKey)) {
            $log->error('[llama] Aborting: LLAMA_API_KEY is not set.', ['request_id' => $requestId]);

            throw new LlamaApiException('Llama API key is not configured.');
        }

        if (! empty($limits['fallback_only'])) {
            if (empty($fallbackModel)) {
                throw new LlamaApiException('No fallback AI model is configured.');
            }

            $log->info('[llama] Using fallback model directly.', [
                'request_id' => $requestId,
                'fallback_model' => $fallbackModel,
            ]);

            $content = $this->attemptWithRetries($fallbackModel, $messages, $options, $fallbackLimits, $apiKey, $requestId, $log);

            return ['content' => $content, 'model' => $fallbackModel];
        }

        try {
            $content = $this->attemptWithRetries($primaryModel, $messages, $options, $primaryLimits, $apiKey, $requestId, $log);

            return ['content' => $content, 'model' => $primaryModel];
        } catch (LlamaApiException $primaryError) {
            if (empty($fallbackModel)) {
                throw $primaryError;
            }

            $log->warning('[llama] Primary model failed — trying fallback model.', [
                'request_id' => $requestId,
                'primary_model' => $primaryModel,
                'fallback_model' => $fallbackModel,
                'primary_error' => $primaryError->getMessage(),
            ]);

            try {
                $content = $this->attemptWithRetries($fallbackModel, $messages, $options, $fallbackLimits, $apiKey, $requestId, $log);

                return ['content' => $content, 'model' => $fallbackModel];
            } catch (LlamaApiException $fallbackError) {
                $log->error('[llama] Fallback model also failed — giving up.', [
                    'request_id' => $requestId,
                    'fallback_model' => $fallbackModel,
                    'error' => $fallbackError->getMessage(),
                ]);

                throw new LlamaApiException(
                    'AI service unavailable (primary and fallback models both failed).',
                    0,
                    $fallbackError,
                );
            }
        }
    }

    /**
     * Send several independent chat requests in parallel, so a large job
     * split into parts takes about as long as one part. Every request gets
     * one attempt on the primary model; any that fail — or whose reply
     * `$parse` rejects by throwing — get one attempt on the fallback model.
     * There are no retries here, so per-call timeouts bound the total wait.
     *
     * @param  array<array-key, array<int, array{role: string, content: string}>>  $conversations
     * @param  array  $options  same limit/body options as chat()
     * @param  ?callable(string, array-key): mixed  $parse  turns (reply text, request key) into a value; throw to reject it
     * @return array<array-key, array{value: mixed, model: string}>  in the order of $conversations
     *
     * @throws LlamaApiException when a request fails on every model (or InvalidAiResponseException
     *                           when its last reply was rejected by `$parse`)
     */
    public function chatConcurrent(array $conversations, array $options = [], ?callable $parse = null): array
    {
        $requestId = (string) Str::uuid();
        $log = Log::channel('ai');

        $limits = array_intersect_key($options, array_flip(self::LIMIT_OPTIONS));
        $options = array_diff_key($options, $limits);

        $apiKey = config('services.routeway.api_key');

        if (empty($apiKey)) {
            $log->error('[llama] Aborting: LLAMA_API_KEY is not set.', ['request_id' => $requestId]);

            throw new LlamaApiException('Llama API key is not configured.');
        }

        $rounds = array_filter([
            empty($limits['fallback_only']) ? [config('services.routeway.model'), $limits['timeout'] ?? null] : null,
            [config('services.routeway.fallback_model'), $limits['fallback_timeout'] ?? null],
        ], fn ($round) => $round !== null && ! empty($round[0]));

        $pending = array_keys($conversations);
        $results = [];
        $errors = [];

        foreach ($rounds as [$model, $timeout]) {
            if (empty($pending)) {
                break;
            }

            $log->debug('[llama] Sending concurrent chat completion requests.', [
                'request_id' => $requestId,
                'model' => $model,
                'count' => count($pending),
            ]);

            $promises = [];
            foreach ($pending as $key) {
                $promises[$key] = $this->client->postAsync(
                    'chat/completions',
                    $this->requestOptions($model, $conversations[$key], $options, $timeout, $apiKey),
                );
            }

            $startedAt = microtime(true);
            $failed = [];

            foreach (Utils::settle($promises)->wait() as $key => $outcome) {
                try {
                    if ($outcome['state'] !== PromiseInterface::FULFILLED) {
                        throw new LlamaApiException("Unable to reach the AI service ({$model}).", 0,
                            $outcome['reason'] instanceof Throwable ? $outcome['reason'] : null);
                    }

                    $content = $this->extractContent((string) $outcome['value']->getBody());

                    if ($content === null) {
                        throw new LlamaApiException("AI service returned an empty response ({$model}).");
                    }

                    $results[$key] = ['value' => $parse ? $parse($content, $key) : $content, 'model' => $model];
                } catch (Throwable $e) {
                    $errors[$key] = $e;
                    $failed[] = $key;

                    $log->warning('[llama] Concurrent request failed.', [
                        'request_id' => $requestId,
                        'model' => $model,
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $log->info('[llama] Concurrent requests settled.', [
                'request_id' => $requestId,
                'model' => $model,
                'succeeded' => count($pending) - count($failed),
                'failed' => count($failed),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            $pending = $failed;
        }

        if (! empty($pending)) {
            $error = $errors[$pending[0]] ?? null;

            if ($error instanceof LlamaApiException || $error instanceof InvalidAiResponseException) {
                throw $error;
            }

            throw new LlamaApiException('AI service unavailable (all models failed).', 0, $error);
        }

        return array_replace(array_flip(array_keys($conversations)), $results);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function requestOptions(string $model, array $messages, array $options, ?int $timeout, string $apiKey): array
    {
        return ($timeout ? ['timeout' => $timeout] : []) + [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ],
            'json' => array_merge([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.4,
            ], $options),
        ];
    }

    /** The reply text, or null when the response carries none. */
    private function extractContent(string $body): ?string
    {
        $content = json_decode($body, true)['choices'][0]['message']['content'] ?? null;

        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    /**
     * Run one model through the retry loop and return its reply text.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{timeout: ?int, max_retries: ?int}  $limits  null = configured default
     *
     * @throws LlamaApiException
     */
    private function attemptWithRetries(
        string $model,
        array $messages,
        array $options,
        array $limits,
        string $apiKey,
        string $requestId,
        LoggerInterface $log,
    ): string {
        $log->debug('[llama] Sending chat completion request.', [
            'request_id' => $requestId,
            'model' => $model,
            'message_count' => count($messages),
            'total_chars' => array_sum(array_map(fn ($m) => strlen($m['content'] ?? ''), $messages)),
            'last_message_preview' => $this->preview(end($messages)['content'] ?? ''),
        ]);

        $maxAttempts = max(1, (int) ($limits['max_retries'] ?? config('services.routeway.max_retries', 2)) + 1);
        $baseDelayMs = (int) config('services.routeway.retry_delay_ms', 500);
        $timeout = $limits['timeout'] ?? null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startedAt = microtime(true);

            try {
                $response = $this->client->post('chat/completions', $this->requestOptions($model, $messages, $options, $timeout, $apiKey));
            } catch (GuzzleException $e) {
                $status = $this->statusFromException($e);
                $willRetry = $attempt < $maxAttempts && $this->isRetryable($status);

                $log->{$willRetry ? 'warning' : 'error'}(
                    $willRetry ? '[llama] Attempt failed, retrying.' : '[llama] Request failed — giving up.',
                    [
                        'request_id' => $requestId,
                        'model' => $model,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'status' => $status,
                        'duration_ms' => $this->durationMs($startedAt),
                        'error' => $e->getMessage(),
                    ],
                );

                if (! $willRetry) {
                    throw new LlamaApiException("Unable to reach the AI service ({$model}).", 0, $e);
                }

                usleep($this->backoffDelayMs($baseDelayMs, $attempt) * 1000);

                continue;
            }

            $durationMs = $this->durationMs($startedAt);
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);
            $content = $this->extractContent($body);

            if ($content === null) {
                $log->error('[llama] Response had no usable message content.', [
                    'request_id' => $requestId,
                    'model' => $model,
                    'attempt' => $attempt,
                    'duration_ms' => $durationMs,
                    'body_preview' => $this->preview($body),
                ]);

                throw new LlamaApiException("AI service returned an empty response ({$model}).");
            }

            $log->info('[llama] Received a response.', [
                'request_id' => $requestId,
                'model' => $model,
                'attempt' => $attempt,
                'duration_ms' => $durationMs,
                'usage' => $decoded['usage'] ?? null,
                'content_length' => strlen($content),
                'content_preview' => $this->preview($content),
            ]);

            return $content;
        }

        // Unreachable: the loop above always either returns or throws.
        throw new LlamaApiException("Unable to reach the AI service ({$model}).");
    }

    private function statusFromException(GuzzleException $e): ?int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    /** Null status = connection-level failure (timeout, DNS, refused) — also worth retrying. */
    private function isRetryable(?int $status): bool
    {
        return $status === null || in_array($status, self::RETRYABLE_STATUSES, true);
    }

    private function backoffDelayMs(int $baseDelayMs, int $attempt): int
    {
        return $baseDelayMs * (2 ** ($attempt - 1));
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function preview(string $text): string
    {
        return Str::limit(str_replace(["\r", "\n"], ' ', $text), self::LOG_PREVIEW_CHARS);
    }
}
