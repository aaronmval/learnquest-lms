<?php

namespace Tests\Unit;

use App\Exceptions\AI\LlamaApiException;
use App\Services\AI\LlamaService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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
}
