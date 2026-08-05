<?php

namespace App\Champs\Services;

use App\Models\ChampsLead;

final class ChampsLeadScoringService
{
    public const CLASSIFICATION_LOW = 'Baixo potencial';

    public const CLASSIFICATION_MEDIUM = 'Potencial médio';

    public const CLASSIFICATION_GOOD = 'Bom potencial';

    public const CLASSIFICATION_HIGH = 'Alta prioridade';

    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_LOW,
        self::CLASSIFICATION_MEDIUM,
        self::CLASSIFICATION_GOOD,
        self::CLASSIFICATION_HIGH,
    ];

    /**
     * @param  ChampsLead|array<string, mixed>  $lead
     * @return array{
     *     score: int,
     *     classification: string,
     *     reasons: list<string>,
     *     criteria: array<string, array{met: bool, points: int, maximum_points: int}>
     * }
     */
    public function calculate(ChampsLead|array $lead): array
    {
        $state = mb_strtoupper(trim((string) data_get($lead, 'state', '')));

        $definitions = [
            'priority_state' => [
                'met' => in_array($state, ['SP', 'RJ'], true),
                'points' => 25,
                'reason' => 'Localização prioritária em SP ou RJ',
            ],
            'website' => [
                'met' => $this->hasText(data_get($lead, 'website')),
                'points' => 15,
                'reason' => 'Website informado',
            ],
            'phone' => [
                'met' => $this->hasText(data_get($lead, 'phone')),
                'points' => 10,
                'reason' => 'Telefone ou WhatsApp informado',
            ],
            'email' => [
                'met' => $this->hasText(data_get($lead, 'email')),
                'points' => 10,
                'reason' => 'E-mail informado',
            ],
            'professional_instagram' => [
                'met' => (bool) data_get($lead, 'instagram_is_professional', false),
                'points' => 10,
                'reason' => 'Perfil profissional no Instagram confirmado',
            ],
            'recent_instagram_posts' => [
                'met' => (int) data_get($lead, 'instagram_media_count', 0) >= 6,
                'points' => 10,
                'reason' => 'Pelo menos 6 publicações no Instagram',
            ],
            'instagram_followers' => [
                'met' => (int) data_get($lead, 'instagram_followers_count', 0) >= 5000,
                'points' => 10,
                'reason' => 'Pelo menos 5.000 seguidores no Instagram',
            ],
            'name_and_city' => [
                'met' => $this->hasText(data_get($lead, 'name'))
                    && $this->hasText(data_get($lead, 'city')),
                'points' => 10,
                'reason' => 'Nome e cidade preenchidos',
            ],
        ];

        $score = 0;
        $reasons = [];
        $criteria = [];

        foreach ($definitions as $key => $definition) {
            $met = (bool) $definition['met'];
            $earnedPoints = $met ? (int) $definition['points'] : 0;
            $score += $earnedPoints;

            $criteria[$key] = [
                'met' => $met,
                'points' => $earnedPoints,
                'maximum_points' => (int) $definition['points'],
            ];

            if ($met) {
                $reasons[] = $definition['reason'];
            }
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'classification' => $this->classificationFor($score),
            'reasons' => $reasons,
            'criteria' => $criteria,
        ];
    }

    public function classificationFor(int $score): string
    {
        return match (true) {
            $score >= 85 => self::CLASSIFICATION_HIGH,
            $score >= 70 => self::CLASSIFICATION_GOOD,
            $score >= 40 => self::CLASSIFICATION_MEDIUM,
            default => self::CLASSIFICATION_LOW,
        };
    }

    private function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
