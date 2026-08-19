<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Providers\CopilotProviderFailure;
use App\Services\Ai\Providers\OpenAiConversationCopilotProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiConversationCopilotProviderTest extends TestCase
{
    public function test_copilot_schema_is_strict_at_every_object_depth(): void
    {
        $schema = app(OpenAiConversationCopilotProvider::class)->schema();
        $objectCount = 0;
        $walk = function (mixed $node) use (&$walk, &$objectCount): void {
            if (! is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'object') {
                $objectCount++;
                $this->assertFalse($node['additionalProperties'] ?? true);
                $this->assertSame(array_keys($node['properties'] ?? []), $node['required'] ?? []);
            }
            foreach (['properties', 'items', 'anyOf', 'oneOf', '$defs', 'definitions'] as $key) {
                $value = $node[$key] ?? null;
                if ($key === 'properties' || $key === '$defs' || $key === 'definitions') {
                    foreach (is_array($value) ? $value : [] as $child) {
                        $walk($child);
                    }
                } elseif ($key === 'items') {
                    $walk($value);
                } else {
                    foreach (is_array($value) ? $value : [] as $child) {
                        $walk($child);
                    }
                }
            }
        };
        $walk($schema);

        $this->assertSame(6, $objectCount);
        $this->assertFalse($schema['properties']['draft_order']['additionalProperties']);
        $this->assertFalse($schema['properties']['draft_order']['properties']['items']['items']['additionalProperties']);
        $selections = $schema['properties']['draft_order']['properties']['items']['items']['properties']['selections'];
        $this->assertFalse($selections['additionalProperties']);
        $this->assertArrayNotHasKey('admin_override', $selections['properties']);
    }

    public function test_request_uses_strict_responses_json_schema_without_network(): void
    {
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        $provider = app(OpenAiConversationCopilotProvider::class);
        $output = [
            'intent' => 'GREETING',
            'confidence' => 0.5,
            'summary' => null,
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => null, 'payment_method' => null],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => null,
            'requires_human_review' => true,
        ];
        Http::fake(['https://api.openai.com/*' => Http::response([
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($output, JSON_THROW_ON_ERROR)]],
            ]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], 200)]);

        $result = $provider->analyze(['latest_message' => ['body' => 'oi']]);

        $this->assertSame('GREETING', $result['intent']);

        Http::assertSent(function (Request $request) use ($provider): bool {
            $data = $request->data();
            $format = data_get($data, 'text.format');
            $instruction = (string) data_get($data, 'input.0.content.0.text');

            return $request->url() === 'https://api.openai.com/v1/responses'
                && data_get($format, 'type') === 'json_schema'
                && data_get($format, 'strict') === true
                && data_get($format, 'schema') === $provider->schema()
                && str_contains($instruction, 'must not create a draft item');
        });
    }

    public function test_response_parser_ignores_reasoning_and_finds_the_message_output_text(): void
    {
        $result = $this->analyzeFakeResponse([
            'output' => [
                ['type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 'private']]],
                ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($this->validOutput('ORDER_CREATE'), JSON_THROW_ON_ERROR)]]],
            ],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 20, 'total_tokens' => 32, 'output_tokens_details' => ['reasoning_tokens' => 7]],
        ]);

        $this->assertSame('ORDER_CREATE', $result['intent']);
        $this->assertSame(7, $result['usage']['reasoning_tokens']);
        $this->assertSame(32, $result['usage']['total_tokens']);
        $this->assertArrayNotHasKey('response_item_types', $result);
    }

    public function test_response_parser_skips_non_message_items_before_the_message(): void
    {
        $result = $this->analyzeFakeResponse([
            'output' => [
                ['type' => 'reasoning'],
                ['type' => 'function_call', 'name' => 'ignored'],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($this->validOutput('MENU_REQUEST'), JSON_THROW_ON_ERROR)]]],
            ],
        ]);

        $this->assertSame('MENU_REQUEST', $result['intent']);
    }

    public function test_response_parser_finds_output_text_by_type_inside_message_content(): void
    {
        $result = $this->analyzeFakeResponse([
            'output' => [[
                'type' => 'message',
                'content' => [
                    ['type' => 'output_image', 'image_url' => 'ignored'],
                    ['type' => 'output_text', 'text' => json_encode($this->validOutput('GREETING'), JSON_THROW_ON_ERROR)],
                ],
            ]],
        ]);

        $this->assertSame('GREETING', $result['intent']);
    }

    public function test_response_refusal_is_not_silently_converted_to_an_empty_dto(): void
    {
        $this->assertInvalidOutputFailure([
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Cannot comply.']]]],
        ], 'response_refusal');
    }

    public function test_response_without_output_text_is_not_silently_converted_to_an_empty_dto(): void
    {
        $this->assertInvalidOutputFailure([
            'output' => [['type' => 'reasoning']],
        ], 'no_output_text');
    }

    public function test_non_object_response_is_rejected_as_invalid_provider_output(): void
    {
        $this->assertInvalidOutputFailure('"not-an-object"', 'invalid_response_shape');
    }

    public function test_incomplete_response_is_not_accepted_as_structured_output(): void
    {
        $this->assertInvalidOutputFailure([
            'status' => 'incomplete',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($this->validOutput(), JSON_THROW_ON_ERROR)]]]],
        ], 'response_incomplete');
    }

    public function test_malformed_output_text_remains_a_sanitized_invalid_provider_output(): void
    {
        $this->assertInvalidOutputFailure([
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{not-json}']]]],
        ], 'invalid_json_output');
    }

    #[DataProvider('providerErrorCases')]
    public function test_http_errors_are_classified_without_network(int $status, array $error, string $category): void
    {
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => $error], $status)]);

        try {
            app(OpenAiConversationCopilotProvider::class)->analyze(['latest_message' => ['body' => 'synthetic']]);
            $this->fail('Expected a sanitized provider failure.');
        } catch (CopilotProviderFailure $exception) {
            $details = $exception->details();
            $this->assertSame($category, $details['category']);
            $this->assertSame($status, $details['http_status']);
            $this->assertSame($error['code'], $details['code']);
            $this->assertSame($error['param'] ?? null, $details['param']);
            $this->assertStringNotContainsString('test-only', json_encode($details));
        }
    }

    public static function providerErrorCases(): array
    {
        return [
            'bad request' => [400, ['type' => 'invalid_request_error', 'code' => 'schema_error', 'param' => 'text.format', 'message' => 'Invalid schema.'], 'INVALID_REQUEST'],
            'authentication' => [401, ['type' => 'invalid_api_key', 'code' => 'invalid_api_key', 'message' => 'Invalid key.'], 'AUTHENTICATION_ERROR'],
            'permission' => [403, ['type' => 'permission_error', 'code' => 'model_not_allowed', 'message' => 'Forbidden.'], 'PERMISSION_ERROR'],
            'model' => [404, ['type' => 'invalid_request_error', 'code' => 'model_not_found', 'message' => 'Missing model.'], 'MODEL_NOT_FOUND'],
            'rate limit' => [429, ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'Slow down.'], 'RATE_LIMITED'],
            'quota' => [429, ['type' => 'insufficient_quota', 'code' => 'insufficient_quota', 'message' => 'Quota exceeded.'], 'QUOTA_EXCEEDED'],
            'provider' => [500, ['type' => 'server_error', 'code' => 'internal_error', 'message' => 'Server error.'], 'PROVIDER_ERROR'],
        ];
    }

    public function test_transport_timeout_is_classified_without_network(): void
    {
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        Http::fake(fn (): never => throw new ConnectionException('Operation timed out'));

        $this->expectException(CopilotProviderFailure::class);
        try {
            app(OpenAiConversationCopilotProvider::class)->analyze([]);
        } catch (CopilotProviderFailure $exception) {
            $this->assertSame('TIMEOUT', $exception->details()['category']);
            throw $exception;
        }
    }

    public function test_transport_network_failure_is_classified_without_network(): void
    {
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        Http::fake(fn (): never => throw new ConnectionException('Could not resolve host'));

        try {
            app(OpenAiConversationCopilotProvider::class)->analyze([]);
            $this->fail('Expected a sanitized provider failure.');
        } catch (CopilotProviderFailure $exception) {
            $this->assertSame('NETWORK_ERROR', $exception->details()['category']);
            $this->assertSame('transport_failure', $exception->details()['code']);
        }
    }

    /** @param array<string, mixed>|string $response */
    private function analyzeFakeResponse(array|string $response): array
    {
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        Http::fake(['https://api.openai.com/*' => Http::response($response, 200, ['Content-Type' => 'application/json'])]);

        return app(OpenAiConversationCopilotProvider::class)->analyze(['latest_message' => ['body' => 'synthetic']]);
    }

    /** @param array<string, mixed>|string $response */
    private function assertInvalidOutputFailure(array|string $response, string $code): void
    {
        try {
            $this->analyzeFakeResponse($response);
            $this->fail('Expected a sanitized invalid provider output failure.');
        } catch (CopilotProviderFailure $exception) {
            $details = $exception->details();
            $this->assertSame('INVALID_PROVIDER_OUTPUT', $details['category']);
            $this->assertSame($code, $details['code']);
            $this->assertStringNotContainsString('test-only', json_encode($details));
        }
    }

    /** @return array<string, mixed> */
    private function validOutput(string $intent = 'GREETING'): array
    {
        return [
            'intent' => $intent,
            'confidence' => 0.8,
            'summary' => null,
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => null, 'payment_method' => null],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => null,
            'requires_human_review' => true,
        ];
    }
}
