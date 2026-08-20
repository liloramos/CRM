<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Services\Ai\CopilotEvaluationDataset;
use App\Services\Ai\Providers\CopilotProviderFailure;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CopilotOnlineEvaluateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_never_calls_provider(): void
    {
        $provider = new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function analyze(array $context): array
            {
                throw new \RuntimeException('provider_called');
            }
        };
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $this->artisan('ai:copilot-online-evaluate --smoke')
            ->expectsOutputToContain('Dry-run only')
            ->assertSuccessful();
    }

    public function test_case_selection_is_deterministic_and_never_calls_the_provider_without_confirmation(): void
    {
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function analyze(array $context): array
            {
                throw new \RuntimeException('provider_called');
            }
        });

        $this->artisan('ai:copilot-online-evaluate --case=AI-006')
            ->expectsOutputToContain('Cases')
            ->expectsOutputToContain('1')
            ->assertSuccessful();
        $this->artisan('ai:copilot-online-evaluate --case=AI-999')
            ->expectsOutputToContain('Unknown evaluation case ID')
            ->assertFailed();
    }

    public function test_smoke_limit_category_and_provider_failure_are_controlled_without_network(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function analyze(array $context): array
            {
                $input = data_get($context, 'latest_message.body');
                $case = collect(CopilotEvaluationDataset::cases())->first(fn (array $case): bool => $case['input'] === $input);

                return $case['provider'];
            }
        });

        $this->artisan('ai:copilot-online-evaluate --smoke --confirm')->assertSuccessful();
        $this->artisan('ai:copilot-online-evaluate --category=beef --limit=2 --confirm')->assertSuccessful();
        $this->artisan('ai:copilot-online-evaluate --category=missing')->assertFailed();
    }

    public function test_all_provider_failures_still_write_a_sanitized_report(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        $provider = new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'openai';
            }

            public function analyze(array $context): array
            {
                throw new CopilotProviderFailure([
                    'category' => 'INVALID_REQUEST',
                    'http_status' => 400,
                    'type' => 'invalid_request_error',
                    'code' => 'schema_error',
                    'param' => 'text.format',
                    'message' => 'Structured output schema is invalid.',
                ]);
            }
        };
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);
        $path = storage_path('app/copilot-evaluations/test-online-report.json');
        File::delete($path);

        try {
            $this->artisan('ai:copilot-online-evaluate --smoke --confirm --output=test-online-report.json')
                ->expectsOutputToContain('category: INVALID_REQUEST')
                ->assertFailed();

            $this->assertFileExists($path);
            $report = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(3, data_get($report, 'summary.provider_failures'));
            $this->assertCount(3, $report['results']);
            $this->assertSame('schema_error', data_get($report, 'results.0.error.code'));
            $this->assertStringNotContainsString('test-only', File::get($path));
        } finally {
            File::delete($path);
        }
    }

    public function test_valid_provider_response_with_wrong_product_is_a_quality_failure(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        $case = collect(CopilotEvaluationDataset::cases())->firstOrFail(fn (array $case): bool => $case['id'] === 'AI-003');
        $provider = new class($case['provider']) implements ConversationCopilotProviderInterface
        {
            public function __construct(private readonly array $fixture) {}

            public function name(): string
            {
                return 'fake';
            }

            public function analyze(array $context): array
            {
                return [...$this->fixture, 'draft_order' => ['items' => [['product' => 'n8-casa', 'quantity' => 1, 'selections' => [], 'removed_components' => [], 'notes' => '']], 'fulfillment' => null]];
            }
        };
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $this->artisan('ai:copilot-online-evaluate --category=n5 --limit=1 --confirm')
            ->expectsOutputToContain('quality_failure')
            ->assertFailed();
    }

    public function test_report_separates_model_guesses_from_safe_grounded_corrections(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function analyze(array $context): array
            {
                return [
                    'intent' => 'ORDER_CREATE',
                    'confidence' => .9,
                    'draft_order' => [
                        'items' => [[
                            'product' => 'n8livre', 'quantity' => 1,
                            'selections' => ['meats' => ['frango ao molho', 'porco']],
                            'removed_components' => [], 'notes' => '',
                        ]],
                        'fulfillment' => null,
                    ],
                    'suggested_reply' => null,
                ];
            }
        });
        $path = storage_path('app/copilot-evaluations/test-grounding-report.json');
        File::delete($path);

        try {
            $this->artisan('ai:copilot-online-evaluate --case=AI-008 --confirm --output=test-grounding-report.json')
                ->assertSuccessful();

            $report = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
            $result = $report['results'][0];
            $this->assertContains('selections', $result['model_failed_dimensions']);
            $this->assertSame([], $result['safe_failed_dimensions']);
            $this->assertContains('selections', $result['safe_corrections']);
            $this->assertGreaterThanOrEqual(1, data_get($report, 'summary.safe_corrections.total_cases'));
            $this->assertArrayHasKey('selections', data_get($report, 'summary.safe_corrections.by_dimension'));
        } finally {
            File::delete($path);
        }
    }

    public function test_safety_failure_is_non_zero_and_visible_in_summary(): void
    {
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        config()->set('chatbotcrm.ai.openai.api_key', 'test-only');
        $this->app->instance(ConversationCopilotProviderInterface::class, new class implements ConversationCopilotProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function analyze(array $context): array
            {
                return ['intent' => 'GREETING', 'confidence' => 0.9, 'summary' => 'ok', 'draft_order' => ['items' => [], 'fulfillment' => null], 'missing_information' => [], 'warnings' => [], 'suggested_reply' => 'ok', 'requires_human_review' => false, 'price_cents' => 1];
            }
        });

        $this->artisan('ai:copilot-online-evaluate --limit=1 --confirm')
            ->expectsOutputToContain('safety_failure')
            ->expectsOutputToContain('SAFETY')
            ->assertFailed();
    }
}
