<?php

namespace Tests\Unit\Champs;

use App\Champs\Services\ChampsLeadScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChampsLeadScoringServiceTest extends TestCase
{
    public function test_score_is_zero_when_no_criterion_is_met(): void
    {
        $result = (new ChampsLeadScoringService)->calculate([
            'name' => '',
            'city' => '',
            'state' => 'MG',
        ]);

        $this->assertSame(0, $result['score']);
        $this->assertSame(ChampsLeadScoringService::CLASSIFICATION_LOW, $result['classification']);
        $this->assertSame([], $result['reasons']);
        $this->assertCount(8, $result['criteria']);
    }

    #[DataProvider('classificationBoundaries')]
    public function test_classification_boundaries(int $score, string $classification): void
    {
        $this->assertSame(
            $classification,
            (new ChampsLeadScoringService)->classificationFor($score),
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function classificationBoundaries(): iterable
    {
        yield '39 remains low' => [39, ChampsLeadScoringService::CLASSIFICATION_LOW];
        yield '40 becomes medium' => [40, ChampsLeadScoringService::CLASSIFICATION_MEDIUM];
        yield '69 remains medium' => [69, ChampsLeadScoringService::CLASSIFICATION_MEDIUM];
        yield '70 becomes good' => [70, ChampsLeadScoringService::CLASSIFICATION_GOOD];
        yield '84 remains good' => [84, ChampsLeadScoringService::CLASSIFICATION_GOOD];
        yield '85 becomes high' => [85, ChampsLeadScoringService::CLASSIFICATION_HIGH];
    }

    public function test_score_is_capped_at_one_hundred_with_every_criterion_met(): void
    {
        $result = (new ChampsLeadScoringService)->calculate([
            'name' => 'Empresa Horizonte Fictícia',
            'city' => 'São Paulo',
            'state' => 'SP',
            'website' => 'https://horizonte-ficticia.example',
            'phone' => '(11) 5555-0100',
            'email' => 'contato@horizonte-ficticia.example',
            'instagram_is_professional' => true,
            'instagram_media_count' => 6,
            'instagram_followers_count' => 5000,
        ]);

        $this->assertSame(100, $result['score']);
        $this->assertSame(ChampsLeadScoringService::CLASSIFICATION_HIGH, $result['classification']);
        $this->assertCount(8, $result['reasons']);

        foreach ($result['criteria'] as $criterion) {
            $this->assertTrue($criterion['met']);
            $this->assertSame($criterion['maximum_points'], $criterion['points']);
        }
    }

    public function test_email_adds_ten_points_only_when_present(): void
    {
        $scoring = new ChampsLeadScoringService;
        $withoutEmail = $scoring->calculate([
            'name' => '',
            'city' => '',
            'state' => 'MG',
        ]);
        $withEmail = $scoring->calculate([
            'name' => '',
            'city' => '',
            'state' => 'MG',
            'email' => 'contato@empresa-ficticia.example',
        ]);

        $this->assertSame(0, $withoutEmail['score']);
        $this->assertFalse($withoutEmail['criteria']['email']['met']);
        $this->assertSame(0, $withoutEmail['criteria']['email']['points']);
        $this->assertSame(10, $withEmail['score']);
        $this->assertTrue($withEmail['criteria']['email']['met']);
        $this->assertSame(10, $withEmail['criteria']['email']['points']);
        $this->assertContains('E-mail informado', $withEmail['reasons']);
    }
}
