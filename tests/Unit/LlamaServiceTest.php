<?php

namespace Tests\Unit;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Services\AI\LlamaService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class LlamaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.routeway.api_key' => 'test-key',
            'services.routeway.model' => 'llama-3.3-70b-instruct',
            'services.routeway.fallback_model' => null, // opt in per-test
            'services.routeway.max_retries' => 2,
            'services.routeway.retry_delay_ms' => 1, // keep tests fast
        ]);
    }

    private function serviceWithQueue(array $queue): LlamaService
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.routeway.ai/v1/']);

        return new LlamaService($client);
    }

    private function successResponse(string $content): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content]]],
        ]));
    }

    public function test_succeeds_on_first_try(): void
    {
        $service = $this->serviceWithQueue([$this->successResponse('hello')]);

        $result = $service->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('hello', $result['content']);
        $this->assertSame('llama-3.3-70b-instruct', $result['model']);
    }

    public function test_retries_on_502_then_succeeds(): void
    {
        $service = $this->serviceWithQueue([
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            $this->successResponse('recovered'),
        ]);

        $result = $service->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('recovered', $result['content']);
        $this->assertSame('llama-3.3-70b-instruct', $result['model']);
    }

    public function test_exhausts_retries_and_throws_when_no_fallback_configured(): void
    {
        $service = $this->serviceWithQueue([
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
        ]);

        $this->expectException(LlamaApiException::class);
        $service->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_does_not_retry_on_client_error(): void
    {
        // Only one response queued — if the service retried on a 401, the
        // MockHandler would throw "no more responses" instead of our exception.
        $service = $this->serviceWithQueue([
            new Response(401, [], 'unauthorized'),
        ]);

        $this->expectException(LlamaApiException::class);
        $service->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_retries_on_connection_exception(): void
    {
        $service = $this->serviceWithQueue([
            new ConnectException('Could not connect', new Psr7Request('POST', 'chat/completions')),
            $this->successResponse('ok'),
        ]);

        $result = $service->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('ok', $result['content']);
    }

    public function test_falls_back_to_secondary_model_when_primary_exhausted(): void
    {
        config(['services.routeway.fallback_model' => 'deepseek-v4-flash:free']);

        $service = $this->serviceWithQueue([
            // Primary model (llama) exhausts all 3 attempts.
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            // Fallback model succeeds on its first attempt.
            $this->successResponse('deepseek to the rescue'),
        ]);

        $result = $service->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('deepseek to the rescue', $result['content']);
        $this->assertSame('deepseek-v4-flash:free', $result['model']);
    }

    public function test_throws_when_both_primary_and_fallback_fail(): void
    {
        config(['services.routeway.fallback_model' => 'deepseek-v4-flash:free']);

        $service = $this->serviceWithQueue([
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
        ]);

        $this->expectException(LlamaApiException::class);
        $service->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_does_not_use_fallback_when_primary_succeeds(): void
    {
        config(['services.routeway.fallback_model' => 'deepseek-v4-flash:free']);

        // Only one response queued — if the fallback were consulted despite
        // the primary succeeding, there'd be nothing left for it to consume.
        $service = $this->serviceWithQueue([$this->successResponse('primary worked')]);

        $result = $service->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('primary worked', $result['content']);
        $this->assertSame('llama-3.3-70b-instruct', $result['model']);
    }

    public function test_concurrent_requests_retry_only_failures_on_the_fallback_and_keep_order(): void
    {
        config(['services.routeway.fallback_model' => 'deepseek-v4-flash']);

        $service = $this->serviceWithQueue([
            $this->successResponse('BAD'),                 // a: rejected by $parse
            $this->successResponse('b from llama'),
            new Response(502, [], 'error code: 502'),       // c: unreachable
            $this->successResponse('a from deepseek'),
            $this->successResponse('c from deepseek'),
        ]);

        $results = $service->chatConcurrent(
            ['a' => [['role' => 'user', 'content' => 'a']], 'b' => [['role' => 'user', 'content' => 'b']], 'c' => [['role' => 'user', 'content' => 'c']]],
            [],
            function (string $content, string $key) {
                if ($content === 'BAD') {
                    throw new InvalidAiResponseException('rejected');
                }

                return strtoupper($key).': '.$content;
            },
        );

        $this->assertSame(['a', 'b', 'c'], array_keys($results));
        $this->assertSame(['value' => 'A: a from deepseek', 'model' => 'deepseek-v4-flash'], $results['a']);
        $this->assertSame(['value' => 'B: b from llama', 'model' => 'llama-3.3-70b-instruct'], $results['b']);
        $this->assertSame('deepseek-v4-flash', $results['c']['model']);
    }

    public function test_concurrent_request_failing_on_every_model_throws_its_last_error(): void
    {
        config(['services.routeway.fallback_model' => 'deepseek-v4-flash']);

        $service = $this->serviceWithQueue([$this->successResponse('BAD'), $this->successResponse('BAD')]);

        $this->expectException(InvalidAiResponseException::class);
        $service->chatConcurrent([[['role' => 'user', 'content' => 'x']]], [], function (string $content) {
            throw new InvalidAiResponseException('rejected');
        });
    }

    public function test_fallback_only_skips_the_primary_model(): void
    {
        config(['services.routeway.fallback_model' => 'deepseek-v4-flash']);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([$this->successResponse('from deepseek')]));
        $stack->push(Middleware::history($history));
        $service = new LlamaService(new Client(['handler' => $stack, 'base_uri' => 'https://api.routeway.ai/v1/']));

        $result = $service->chat([['role' => 'user', 'content' => 'hi']], ['fallback_only' => 1, 'fallback_timeout' => 90]);

        $this->assertSame('from deepseek', $result['content']);
        $this->assertSame('deepseek-v4-flash', $result['model']);
        $this->assertCount(1, $history);
        $this->assertSame(90, $history[0]['options']['timeout']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('deepseek-v4-flash', $body['model']);
        $this->assertArrayNotHasKey('fallback_only', $body);
    }

    public function test_fallback_only_without_a_fallback_model_throws(): void
    {
        $service = $this->serviceWithQueue([]);

        $this->expectException(LlamaApiException::class);
        $service->chat([['role' => 'user', 'content' => 'hi']], ['fallback_only' => 1]);
    }

    public function test_per_call_limits_hand_a_timed_out_primary_to_the_fallback_quickly(): void
    {
        config(['services.routeway.fallback_model' => 'deepseek-v4-flash']);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            // Primary times out once — with max_retries 0 it is not retried.
            new ConnectException('timed out', new Psr7Request('POST', 'chat/completions')),
            $this->successResponse('{"insights":[]}'),
        ]));
        $stack->push(Middleware::history($history));
        $service = new LlamaService(new Client(['handler' => $stack, 'base_uri' => 'https://api.routeway.ai/v1/']));

        $result = $service->chat([['role' => 'user', 'content' => 'hi']], [
            'timeout' => 8,
            'max_retries' => 0,
            'fallback_timeout' => 15,
            'fallback_max_retries' => 0,
            'max_tokens' => 600,
        ]);

        $this->assertSame('deepseek-v4-flash', $result['model']);
        $this->assertCount(2, $history);
        $this->assertSame(8, $history[0]['options']['timeout']);
        $this->assertSame(15, $history[1]['options']['timeout']);

        // Limits are consumed locally; other options still reach the API body.
        $body = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('deepseek-v4-flash', $body['model']);
        $this->assertSame(600, $body['max_tokens']);
        $this->assertArrayNotHasKey('timeout', $body);
        $this->assertArrayNotHasKey('fallback_max_retries', $body);
    }
}
