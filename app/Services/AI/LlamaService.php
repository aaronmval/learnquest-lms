<?php

namespace App\Services\AI;

use App\Exceptions\AI\LlamaApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

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
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string}
     *
     * @throws LlamaApiException
     */
    public function chat(array $messages, array $options = []): array
    {
        $requestId = (string) Str::uuid();
        $log = Log::channel('ai');

        $apiKey = config('services.routeway.api_key');
        $primaryModel = config('services.routeway.model');
        $fallbackModel = config('services.routeway.fallback_model');

        if (empty($apiKey)) {
            $log->error('[llama] Aborting: LLAMA_API_KEY is not set.', ['request_id' => $requestId]);

            throw new LlamaApiException('Llama API key is not configured.');
        }

        try {
            $content = $this->attemptWithRetries($primaryModel, $messages, $options, $apiKey, $requestId, $log);

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
                $content = $this->attemptWithRetries($fallbackModel, $messages, $options, $apiKey, $requestId, $log);

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
     * Run one model through the retry loop and return its reply text.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws LlamaApiException
     */
    private function attemptWithRetries(
        string $model,
        array $messages,
        array $options,
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

        $maxAttempts = max(1, (int) config('services.routeway.max_retries', 2) + 1);
        $baseDelayMs = (int) config('services.routeway.retry_delay_ms', 500);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startedAt = microtime(true);

            try {
                $response = $this->client->post('chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Accept' => 'application/json',
                    ],
                    'json' => array_merge([
                        'model' => $model,
                        'messages' => $messages,
                        'temperature' => 0.4,
                    ], $options),
                ]);
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
            $content = $decoded['choices'][0]['message']['content'] ?? null;

            if (! is_string($content) || trim($content) === '') {
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
