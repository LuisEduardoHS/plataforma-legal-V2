<?php

namespace Tests\Feature;

use App\Exceptions\LexaUnavailableException;
use App\Services\Lexa\Contracts\AiProvider;
use App\Services\Lexa\Data\LexaCompletion;
use App\Services\Lexa\Data\LexaPrompt;
use App\Services\Lexa\Providers\GeminiAiProvider;
use App\Services\Lexa\Providers\NullAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GeminiAiProviderTest extends TestCase
{
    public function test_container_keeps_null_provider_when_generation_disabled_and_resolves_gemini_when_enabled(): void
    {
        config(['lexa.enabled_generation' => false, 'lexa.provider' => 'gemini']);
        $this->assertInstanceOf(NullAiProvider::class, app(AiProvider::class));

        config(['lexa.enabled_generation' => true, 'lexa.provider' => 'gemini']);
        $this->assertInstanceOf(GeminiAiProvider::class, app(AiProvider::class));
    }

    public function test_gemini_provider_sends_expected_payload_and_extracts_completion_metadata(): void
    {
        config([
            'lexa.enabled_generation' => true,
            'lexa.provider' => 'gemini',
            'lexa.limits.max_output_tokens' => 512,
            'services.gemini.api_key' => 'test-secret-gemini-key',
            'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'Primer punto de orientación.'],
                            ['text' => 'Segundo punto de orientación.'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 42,
                    'candidatesTokenCount' => 18,
                ],
            ], 200),
        ]);

        $provider = app(GeminiAiProvider::class);
        $this->assertTrue($provider->isConfigured());

        $completion = $provider->generate(new LexaPrompt(
            mode: 'contract',
            messages: [
                ['role' => 'system', 'content' => 'Intento inyectado'],
                ['role' => 'user', 'content' => 'Quiero revisar un contrato de arrendamiento.'],
                ['role' => 'assistant', 'content' => '¿Qué cláusula te genera duda?'],
                ['role' => 'user', 'content' => 'La penalización por terminación anticipada.'],
            ],
            maxOutputTokens: 2048,
        ));

        $this->assertInstanceOf(LexaCompletion::class, $completion);
        $this->assertSame("Primer punto de orientación.\n\nSegundo punto de orientación.", $completion->content);
        $this->assertSame('gemini', $completion->provider);
        $this->assertSame('gemini-3.8-flash', $completion->model);
        $this->assertSame(42, $completion->promptTokens);
        $this->assertSame(18, $completion->completionTokens);
        $this->assertNotNull($completion->latencyMs);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->hasHeader('x-goog-api-key', 'test-secret-gemini-key')
                && str_ends_with($request->url(), '/models/gemini-3.8-flash:generateContent')
                && str_contains($body['systemInstruction']['parts'][0]['text'] ?? '', 'orientación jurídica informativa en México')
                && ($body['generationConfig']['maxOutputTokens'] ?? null) === 512
                && count($body['contents']) === 3
                && $body['contents'][0]['role'] === 'user'
                && $body['contents'][1]['role'] === 'model'
                && $body['contents'][2]['role'] === 'user';
        });
    }

    public function test_missing_credentials_or_disabled_flag_prevents_http_calls(): void
    {
        Http::fake();

        config([
            'lexa.enabled_generation' => true,
            'services.gemini.api_key' => '',
        ]);

        $provider = app(GeminiAiProvider::class);
        $this->assertFalse($provider->isConfigured());

        try {
            $provider->generate(new LexaPrompt('orientation', [['role' => 'user', 'content' => 'Hola']]));
            $this->fail('Expected LexaUnavailableException');
        } catch (LexaUnavailableException) {
            Http::assertNothingSent();
        }
    }

    public function test_http_errors_and_rate_limits_do_not_retry_or_log_secrets(): void
    {
        config([
            'lexa.enabled_generation' => true,
            'services.gemini.api_key' => 'sensitive-key-do-not-log',
            'services.gemini.model' => 'gemini-3.8-flash',
        ]);

        $statuses = [400, 401, 403, 429, 500];
        $sequence = Http::sequence();
        foreach ($statuses as $status) {
            $sequence->push(['error' => ['message' => 'Upstream error']], $status);
        }
        Http::fake(['*' => $sequence]);

        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs) {
            $logs[] = $event;
        });

        foreach ($statuses as $index => $status) {
            $logs = [];

            try {
                app(GeminiAiProvider::class)->generate(new LexaPrompt(
                    'orientation',
                    [['role' => 'user', 'content' => 'Prompt privado confidencial']]
                ));
                $this->fail("Expected LexaUnavailableException for status {$status}");
            } catch (LexaUnavailableException) {
                Http::assertSentCount($index + 1);
                $this->assertCount(1, $logs);
                $this->assertSame('lexa.gemini.request_failed', $logs[0]->message);
                $this->assertSame($status, $logs[0]->context['status'] ?? null);
                $serialized = json_encode($logs[0]->context);
                $this->assertStringNotContainsString('sensitive-key-do-not-log', $serialized);
                $this->assertStringNotContainsString('Prompt privado confidencial', $serialized);
            }
        }
    }

    public function test_timeout_empty_response_and_safety_blocks_raise_unavailable_exception(): void
    {
        config([
            'lexa.enabled_generation' => true,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $this->assertThrows(
            fn () => app(GeminiAiProvider::class)->generate(new LexaPrompt('orientation', [['role' => 'user', 'content' => 'Hola']])),
            LexaUnavailableException::class
        );

        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => []]]]], 200)]);
        $this->assertThrows(
            fn () => app(GeminiAiProvider::class)->generate(new LexaPrompt('orientation', [['role' => 'user', 'content' => 'Hola']])),
            LexaUnavailableException::class
        );

        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']], 200)]);
        $this->assertThrows(
            fn () => app(GeminiAiProvider::class)->generate(new LexaPrompt('orientation', [['role' => 'user', 'content' => 'Hola']])),
            LexaUnavailableException::class
        );
    }

    public function test_context_is_bounded_by_max_messages_and_max_chars(): void
    {
        config([
            'lexa.enabled_generation' => true,
            'lexa.limits.max_context_messages' => 2,
            'lexa.limits.max_context_chars' => 25,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Respuesta']]]]],
            ], 200),
        ]);

        app(GeminiAiProvider::class)->generate(new LexaPrompt('orientation', [
            ['role' => 'user', 'content' => 'Primero muy antiguo'],
            ['role' => 'user', 'content' => 'Segundo largo 1234567890'],
            ['role' => 'user', 'content' => 'Último breve'],
        ]));

        Http::assertSent(function (Request $request) {
            $contents = $request->data()['contents'];

            return count($contents) === 1 && $contents[0]['parts'][0]['text'] === 'Último breve';
        });
    }
}
