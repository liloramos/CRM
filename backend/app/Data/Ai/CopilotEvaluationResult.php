<?php

namespace App\Data\Ai;

final class CopilotEvaluationResult
{
    public const DATASET_VERSION = 1;

    /** @var array<string, array{correct:int,total:int}> */
    private array $metrics = [];

    public function __construct(public readonly int $totalCases) {}

    public function record(string $metric, bool $correct): void
    {
        $current = $this->metrics[$metric] ?? ['correct' => 0, 'total' => 0];
        $this->metrics[$metric] = [
            'correct' => $current['correct'] + ($correct ? 1 : 0),
            'total' => $current['total'] + 1,
        ];
    }

    public function score(string $metric): float
    {
        $result = $this->metrics[$metric] ?? null;

        return ! $result || $result['total'] === 0 ? 100.0 : round(($result['correct'] / $result['total']) * 100, 2);
    }

    /** @return array<string, float> */
    public function scores(): array
    {
        return collect(array_keys($this->metrics))
            ->mapWithKeys(fn (string $metric): array => [$metric => $this->score($metric)])
            ->all();
    }

    /** @param array<string, float> $thresholds */
    public function meets(array $thresholds): bool
    {
        foreach ($thresholds as $metric => $minimum) {
            if ($this->score($metric) < $minimum) {
                return false;
            }
        }

        return true;
    }
}
