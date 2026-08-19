<?php

namespace App\Console\Commands;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Data\Ai\CopilotEvaluationResult;
use App\Models\Company;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotPipeline;
use App\Services\Ai\CopilotEvaluationDataset;
use App\Services\Ai\CopilotEvaluationScorer;
use App\Services\Ai\Providers\CopilotProviderFailure;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class CopilotOnlineEvaluateCommand extends Command
{
    protected $signature = 'ai:copilot-online-evaluate
        {--confirm : Allows real provider calls after the dry-run summary}
        {--smoke : Selects the three representative cases}
        {--case=* : Selects one or more explicit dataset case IDs}
        {--limit= : Maximum number of cases}
        {--category= : Selects one dataset category}
        {--output= : Relative JSON report path under storage/app/copilot-evaluations}
        {--diagnostic : Adds sanitized expected, normalized, and safe snapshots to the local report}
        {--fail-fast : Stops after the first provider failure}';

    protected $description = 'Runs the synthetic, read-only online copilot evaluation only after explicit confirmation.';

    public function handle(ConversationCopilotProviderInterface $provider, ConversationCopilotContextBuilder $contextBuilder, ConversationCopilotPipeline $pipeline, CopilotEvaluationScorer $scorer): int
    {
        if (app()->isProduction()) {
            $this->error('Online copilot evaluation is permanently blocked in production.');

            return self::FAILURE;
        }

        $cases = $this->selectedCases();
        if ($cases === null) {
            return self::FAILURE;
        }
        $metadata = [
            'run_id' => (string) str()->uuid(),
            'timestamp' => now()->toIso8601String(),
            'dataset_version' => CopilotEvaluationDataset::VERSION,
            'dataset_hash' => CopilotEvaluationDataset::fingerprint(),
            'provider' => $provider->name(),
            'model' => (string) config('chatbotcrm.ai.openai.model'),
            'reasoning' => (string) config('chatbotcrm.ai.openai.reasoning_effort'),
            'cases_selected' => count($cases),
            'evaluation_date' => CopilotEvaluationDataset::EVALUATION_DATE,
            'environment' => app()->environment(),
        ];
        $this->table(['Provider', 'Model', 'Reasoning', 'Dataset', 'Cases', 'Environment'], [[
            $metadata['provider'], $metadata['model'] ?: 'not configured', $metadata['reasoning'], $metadata['dataset_version'], $metadata['cases_selected'], $metadata['environment'],
        ]]);

        if (! $this->option('confirm')) {
            $this->warn('Dry-run only. Use --confirm to allow real OpenAI calls that may consume credits.');

            return self::SUCCESS;
        }
        if (! app()->environment('local', 'testing') || ! config('chatbotcrm.ai.openai.api_key')) {
            $this->error('Online evaluation requires a local/testing environment and configured provider credentials.');

            return self::FAILURE;
        }

        $results = [];
        $safeEvaluation = new CopilotEvaluationResult(count($cases));
        $modelEvaluation = new CopilotEvaluationResult(count($cases));
        $safeCorrections = [];
        $safeCorrectionCases = [];
        $company = Company::query()->where('slug', 'restaurante-sol')->first();
        if (! $company) {
            $this->error('Online evaluation requires the restaurante-sol company configuration.');

            return self::FAILURE;
        }
        foreach ($cases as $case) {
            $startedAt = microtime(true);
            try {
                $date = CarbonImmutable::parse((string) ($case['evaluation_date'] ?? CopilotEvaluationDataset::EVALUATION_DATE));
                $pipelineResult = $pipeline->analyze($company, $contextBuilder->forMessages($company, $this->syntheticMessages($case), null, $date), $date);
                $raw = $pipelineResult['raw'];
                $analysis = $pipelineResult['safe'];
                $modelScores = $scorer->score($case, $pipelineResult['normalized']);
                $scores = $scorer->score($case, $analysis);
                $providerValid = $scorer->providerValid($raw, $analysis);
                $safetyPass = $scorer->rawSafetyPass($raw) && (bool) $scores['safety'];
                $scores['safety'] = $safetyPass;
                $modelScores['selection_grounding_pass'] = $scorer->selectionGroundingPass($analysis);
                foreach ($modelScores as $metric => $score) {
                    if ($score !== null) {
                        $modelEvaluation->record($metric, $score);
                    }
                }
                foreach ($scores as $metric => $score) {
                    if ($score !== null) {
                        $safeEvaluation->record($metric, $score);
                    }
                }
                $safeEvaluation->record('provider_validity', $providerValid);
                $modelFailedDimensions = $scorer->failedDimensions($modelScores);
                $failedDimensions = $scorer->failedDimensions($scores);
                $caseCorrections = array_values(array_filter($modelFailedDimensions, fn (string $dimension): bool => ($scores[$dimension] ?? null) === true));
                foreach ($caseCorrections as $dimension) {
                    $safeCorrections[$dimension] = ($safeCorrections[$dimension] ?? 0) + 1;
                }
                if ($caseCorrections !== []) {
                    $safeCorrectionCases[$case['id']] = true;
                }
                if (! $safetyPass) {
                    $status = 'safety_failure';
                } elseif (! $providerValid || $failedDimensions !== []) {
                    $status = 'safe_quality_failure';
                } elseif ($modelFailedDimensions !== []) {
                    $status = 'model_quality_failure';
                } else {
                    $status = 'completed';
                }
                $result = ['case_id' => $case['id'], 'category' => $case['category'], 'evaluation_date' => $date->toDateString(), 'status' => $status, 'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'usage' => $raw['usage'] ?? [], 'model_failed_dimensions' => $modelFailedDimensions, 'safe_failed_dimensions' => $failedDimensions, 'safe_corrections' => $caseCorrections];
                if ($failedDimensions !== []) {
                    $result['failed_dimensions'] = $failedDimensions;
                }
                if (! $providerValid) {
                    $result['provider_validity'] = false;
                }
                if ($this->option('diagnostic')) {
                    $result['diagnostic'] = $this->diagnosticSnapshot($case, $raw, $pipelineResult['normalized'], $analysis, $scores, $modelScores, $caseCorrections);
                }
                $results[] = $result;
                $this->line($status === 'completed' ? 'PASS '.$case['id'] : ($status === 'model_quality_failure' ? 'MODEL_FAIL / SAFE_PASS '.$case['id'] : 'FAIL '.$case['id'].' '.$status));
            } catch (CopilotProviderFailure $exception) {
                $failure = $exception->details();
                $results[] = ['case_id' => $case['id'], 'status' => 'provider_failure', 'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'error' => $failure];
                $this->renderFailure($case['id'], $failure);
                if ($this->option('fail-fast')) {
                    break;
                }
            } catch (Throwable $exception) {
                $failure = [
                    'category' => 'PROVIDER_ERROR',
                    'http_status' => null,
                    'type' => null,
                    'code' => 'unexpected_provider_exception',
                    'param' => null,
                    'message' => 'The provider failed without a diagnostic response.',
                ];
                $results[] = ['case_id' => $case['id'], 'status' => 'provider_failure', 'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'error' => $failure];
                $this->renderFailure($case['id'], $failure);
                if ($this->option('fail-fast')) {
                    break;
                }
            }
        }
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $latencies = [];
        foreach ($results as $result) {
            $usage['input_tokens'] += (int) data_get($result, 'usage.input_tokens', 0);
            $usage['output_tokens'] += (int) data_get($result, 'usage.output_tokens', 0);
            $usage['total_tokens'] += (int) data_get($result, 'usage.total_tokens', data_get($result, 'usage.input_tokens', 0) + data_get($result, 'usage.output_tokens', 0));
            if ($result['status'] !== 'provider_failure') {
                $latencies[] = (int) $result['latency_ms'];
            }
        }
        $scores = $safeEvaluation->scores();
        $scores['safety_pass'] = $safeEvaluation->safetyPass();
        unset($scores['safety']);
        $modelScores = $modelEvaluation->scores();
        $completed = collect($results)->reject(fn (array $result): bool => $result['status'] === 'provider_failure');
        $report = [
            'metadata' => [...$metadata, 'cases_completed' => $completed->count()],
            'summary' => [
                'provider_failures' => collect($results)->where('status', 'provider_failure')->count(),
                'quality_failures' => collect($results)->where('status', 'safe_quality_failure')->count(),
                'model_quality_failures' => collect($results)->where('status', 'model_quality_failure')->count(),
                'safety_failures' => collect($results)->where('status', 'safety_failure')->count(),
                'completed' => $completed->count(),
                'usage' => $usage,
                'scores' => $completed->isEmpty() ? null : $scores,
                'safe_scores' => $completed->isEmpty() ? null : $scores,
                'model_scores' => $completed->isEmpty() ? null : $modelScores,
                'safe_corrections' => ['total_cases' => count($safeCorrectionCases), 'by_dimension' => $safeCorrections],
                'latency' => $this->latencySummary($latencies),
            ],
            'results' => $results,
        ];
        $this->renderSummary($report['summary']);
        if ($path = $this->option('output')) {
            $this->writeReport((string) $path, $report);
        }

        return collect($results)->contains(fn (array $result): bool => in_array($result['status'], ['provider_failure', 'safe_quality_failure', 'safety_failure'], true)) ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<int> $latencies @return array<string, int|null> */
    private function latencySummary(array $latencies): array
    {
        return [
            'average_latency_ms' => $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies)),
            'min_latency_ms' => $latencies === [] ? null : min($latencies),
            'max_latency_ms' => $latencies === [] ? null : max($latencies),
            'includes_provider_failures' => false,
        ];
    }

    /** @param array<string,mixed> $summary */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->info('ONLINE COPILOT EVALUATION');
        $this->line('Completed: '.($summary['completed'] ?? 0).' | Provider failures: '.($summary['provider_failures'] ?? 0));
        $this->line('Safe quality failures: '.($summary['quality_failures'] ?? 0).' | Model-only quality failures: '.($summary['model_quality_failures'] ?? 0).' | Safety failures: '.($summary['safety_failures'] ?? 0));
        $this->newLine();
        $this->line('MODEL QUALITY');
        foreach (['intent', 'product', 'quantity', 'selections', 'removals', 'notes', 'fulfillment', 'missing_information'] as $metric) {
            $this->line('  '.str_replace('_', ' ', ucfirst($metric)).': '.$this->formatScore($summary['model_scores'][$metric] ?? null));
        }
        $this->line('  Selection grounding: '.$this->formatScore($summary['model_scores']['selection_grounding_pass'] ?? null));
        $this->line('SAFE QUALITY');
        foreach (['intent', 'product', 'quantity', 'selections', 'removals', 'notes', 'fulfillment', 'missing_information'] as $metric) {
            $this->line('  '.str_replace('_', ' ', ucfirst($metric)).': '.$this->formatScore($summary['safe_scores'][$metric] ?? null));
        }
        $this->line('SAFE CORRECTIONS');
        $this->line('  Cases corrected: '.($summary['safe_corrections']['total_cases'] ?? 0));
        foreach ($summary['safe_corrections']['by_dimension'] ?? [] as $dimension => $count) {
            $this->line('  '.str_replace('_', ' ', ucfirst($dimension)).': '.$count);
        }
        $this->line('PROVIDER');
        $this->line('  Validity rate: '.$this->formatScore($summary['scores']['provider_validity'] ?? null));
        $this->line('SAFETY');
        $this->line('  PASS: '.(($summary['scores']['safety_pass'] ?? false) ? 'yes' : 'no'));
        $this->line('USAGE');
        foreach ($summary['usage'] as $key => $value) {
            $this->line('  '.str_replace('_', ' ', ucfirst($key)).': '.$value);
        }
        $this->line('LATENCY');
        foreach ($summary['latency'] as $key => $value) {
            $this->line('  '.str_replace('_', ' ', ucfirst($key)).': '.($value ?? 'n/a'));
        }
    }

    private function formatScore(mixed $score): string
    {
        return is_numeric($score) ? number_format((float) $score, 2).'%' : 'n/a';
    }

    /** @param array<string,mixed> $failure */
    private function renderFailure(string $caseId, array $failure): void
    {
        $this->error('FAIL '.$caseId);
        $this->line('  category: '.($failure['category'] ?? 'PROVIDER_ERROR'));
        $this->line('  http: '.($failure['http_status'] ?? 'n/a'));
        $this->line('  type: '.($failure['type'] ?? 'n/a'));
        $this->line('  code: '.($failure['code'] ?? 'n/a'));
        $this->line('  param: '.($failure['param'] ?? 'n/a'));
        $this->line('  message: '.($failure['message'] ?? 'Provider failure.'));
    }

    /** @param array<string,mixed> $report */
    private function writeReport(string $requestedPath, array $report): void
    {
        $filename = basename($requestedPath);
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'evaluation.json';
        if (! str_ends_with(strtolower($filename), '.json')) {
            $filename .= '.json';
        }

        $directory = storage_path('app/copilot-evaluations');
        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.$filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->line('Report: storage/app/copilot-evaluations/'.$filename);
    }

    /** @return list<array<string,mixed>>|null */
    private function selectedCases(): ?array
    {
        $cases = CopilotEvaluationDataset::cases();
        if ($this->option('smoke')) {
            return array_values(array_filter($cases, fn (array $case): bool => in_array($case['id'], ['AI-004', 'AI-010', 'AI-016'], true)));
        }
        $caseIds = array_values(array_filter($this->option('case'), fn (mixed $case): bool => is_string($case) && $case !== ''));
        if ($caseIds !== []) {
            $knownIds = array_column($cases, 'id');
            $unknown = array_values(array_diff($caseIds, $knownIds));
            if ($unknown !== []) {
                $this->error('Unknown evaluation case ID: '.implode(', ', $unknown));

                return null;
            }
            $cases = array_values(array_filter($cases, fn (array $case): bool => in_array($case['id'], $caseIds, true)));
        }
        if ($category = $this->option('category')) {
            $cases = array_values(array_filter($cases, fn (array $case): bool => $case['category'] === $category));
            if ($cases === []) {
                $this->error('Unknown evaluation category: '.$category);

                return null;
            }
        }
        if ($cases === []) {
            $this->error('No evaluation cases match the selected filters.');

            return null;
        }
        $limit = $this->option('limit');
        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error('The --limit option must be a positive integer.');

            return null;
        }

        return $limit === null ? $cases : array_slice($cases, 0, (int) $limit);
    }

    /** @param array<string,mixed> $case @return list<array<string,mixed>> */
    private function syntheticMessages(array $case): array
    {
        return array_map(fn (string $message): array => ['direction' => 'inbound', 'type' => 'text', 'body' => $message], $case['messages'] ?? [$case['input']]);
    }

    /** @param array<string,mixed> $case @param array<string,mixed> $raw @param array<string,mixed> $normalized @param array<string,mixed> $safe @param array<string,bool|null> $scores @param array<string,bool|null> $modelScores @param list<string> $safeCorrections @return array<string,mixed> */
    private function diagnosticSnapshot(array $case, array $raw, array $normalized, array $safe, array $scores, array $modelScores, array $safeCorrections): array
    {
        $fields = fn (array $analysis): array => [
            'intent' => $analysis['intent'] ?? null,
            'draft_order' => [
                'items' => collect(data_get($analysis, 'draft_order.items', []))->map(fn (array $item): array => [
                    'menu_item_id' => $item['menu_item_id'] ?? null,
                    'menu_item_slug' => $item['menu_item_slug'] ?? $item['product'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'selections' => $item['selections'] ?? [],
                    'removed_components' => $item['removed_components'] ?? [],
                    'item_notes' => $item['item_notes'] ?? $item['notes'] ?? null,
                ])->all(),
                'fulfillment' => data_get($analysis, 'draft_order.fulfillment'),
                'address_present' => filled(data_get($analysis, 'draft_order.address')),
            ],
            'missing_information' => array_column($analysis['missing_information'] ?? [], 'code'),
            'warnings' => array_column($analysis['warnings'] ?? [], 'code'),
        ];

        return [
            'expected' => [
                'intent' => $case['intent'],
                'product' => $case['product'],
                'quantity' => $case['expected_quantity'],
                'selections' => $case['expected_selections'],
                'removals' => $case['expected_removals'] ?? null,
                'notes' => $case['expected_notes'],
                'fulfillment' => $case['expected_fulfillment'],
                'missing_information' => $case['missing'],
            ],
            'raw' => $fields($raw),
            'normalized' => $fields($normalized),
            'safe' => $fields($safe),
            'model_failed_dimensions' => $this->failedDimensions($modelScores),
            'safe_failed_dimensions' => $this->failedDimensions($scores),
            'safe_corrections' => $safeCorrections,
        ];
    }

    /** @param array<string,bool|null> $scores @return list<string> */
    private function failedDimensions(array $scores): array
    {
        return array_keys(array_filter($scores, fn (?bool $score): bool => $score === false));
    }
}
