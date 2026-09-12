<?php

namespace App\Tests\Unit\Dto;

use App\Dto\EncodingTrackingSummary;
use App\Enum\EncodingState;
use PHPUnit\Framework\TestCase;

/**
 * Suivi des encodages (D-118) — verrouille l'arithmétique du résumé partagé.
 *
 * Ces totaux sont consommés par DEUX écrans (cockpit d'encodage et explication des zéros
 * de la page Statistiques). Une erreur ici produirait deux messages incohérents entre
 * eux, ce qui est précisément le défaut UX que ce chantier corrige.
 */
final class EncodingTrackingSummaryTest extends TestCase
{
    private function summary(array $counts, int $staleInProgress = 0, int $anomalies = 0): EncodingTrackingSummary
    {
        return EncodingTrackingSummary::fromStateCounts($counts, $staleInProgress, $anomalies);
    }

    public function testTotalsSumEveryState(): void
    {
        $summary = $this->summary([
            EncodingState::UPCOMING->value => 4,
            EncodingState::TO_ENCODE->value => 6,
            EncodingState::IN_PROGRESS->value => 2,
            EncodingState::SUBMITTED->value => 3,
            EncodingState::VALIDATED->value => 5,
            EncodingState::LOCKED->value => 1,
            EncodingState::NOT_APPLICABLE->value => 7,
        ]);

        self::assertSame(28, $summary->totalMissions);
        self::assertSame(21, $summary->encodingExpected, 'les 7 NOT_APPLICABLE sortent du dénominateur');
    }

    public function testMissingStateKeysCountAsZero(): void
    {
        $summary = $this->summary([EncodingState::SUBMITTED->value => 2]);

        self::assertSame(2, $summary->totalMissions);
        self::assertSame(0, $summary->toEncode);
        self::assertSame(0, $summary->notApplicable);
    }

    /**
     * Le cas central du chantier : de l'activité, mais rien de finançable. C'est ce
     * booléen qui déclenche le message explicatif au lieu de "Aucune donnée financière".
     */
    public function testPeriodWithActivityButNothingValidatedIsNotFinanciallyEligible(): void
    {
        $summary = $this->summary([
            EncodingState::TO_ENCODE->value => 6,
            EncodingState::IN_PROGRESS->value => 2,
            EncodingState::SUBMITTED->value => 3,
        ]);

        self::assertFalse($summary->hasFinanciallyEligibleMissions());
        self::assertSame(11, $summary->encodingExpected, 'il y a bien de l\'activité');
        self::assertSame(0, $summary->encoded() - $summary->submitted, 'rien au-delà de soumis');
    }

    public function testValidatedOrLockedMakesPeriodFinanciallyEligible(): void
    {
        self::assertTrue($this->summary([EncodingState::VALIDATED->value => 1])->hasFinanciallyEligibleMissions());
        self::assertTrue($this->summary([EncodingState::LOCKED->value => 1])->hasFinanciallyEligibleMissions());
    }

    public function testEncodedCountsSubmittedAndBeyond(): void
    {
        $summary = $this->summary([
            EncodingState::TO_ENCODE->value => 4,
            EncodingState::SUBMITTED->value => 3,
            EncodingState::VALIDATED->value => 5,
            EncodingState::LOCKED->value => 2,
        ]);

        self::assertSame(10, $summary->encoded());
    }

    /**
     * Un encodage entamé sur une mission déjà terminée est un retard ; sur une mission
     * du jour en cours, non. Seul le sous-ensemble "stale" compte comme manquant.
     */
    public function testMissingEncodingCountsToEncodePlusStaleInProgressOnly(): void
    {
        $summary = $this->summary(
            [EncodingState::TO_ENCODE->value => 6, EncodingState::IN_PROGRESS->value => 5],
            staleInProgress: 2,
        );

        self::assertSame(8, $summary->missingEncoding(), '6 jamais commencés + 2 commencés en retard');
        self::assertSame(5, $summary->inProgress, 'les 3 autres sont en cours normalement');
    }

    public function testToTreatAggregatesEveryActionableCategory(): void
    {
        $summary = $this->summary(
            [
                EncodingState::TO_ENCODE->value => 6,
                EncodingState::IN_PROGRESS->value => 4,
                EncodingState::SUBMITTED->value => 3,
                EncodingState::VALIDATED->value => 9,
            ],
            staleInProgress: 1,
            anomalies: 2,
        );

        // 6 à encoder + 1 en retard + 3 à valider + 2 anomalies ; les 9 validées et les
        // 3 en cours non tardifs ne réclament aucune action.
        self::assertSame(12, $summary->toTreat());
    }

    public function testQuietPeriodHasNothingToTreat(): void
    {
        $summary = $this->summary([
            EncodingState::UPCOMING->value => 8,
            EncodingState::VALIDATED->value => 4,
            EncodingState::NOT_APPLICABLE->value => 2,
        ]);

        self::assertSame(0, $summary->toTreat());
        self::assertSame(0, $summary->missingEncoding());
    }

    /** Une période vide reste cohérente (aucune division, aucun total négatif). */
    public function testEmptyPeriodIsAllZeros(): void
    {
        $summary = $this->summary([]);

        self::assertSame(0, $summary->totalMissions);
        self::assertSame(0, $summary->encodingExpected);
        self::assertSame(0, $summary->toTreat());
        self::assertFalse($summary->hasFinanciallyEligibleMissions());
    }

    /**
     * Garde-fou : si un EncodingState est ajouté à l'enum, il doit être compté dans le
     * total. Sans ça, un nouvel état disparaîtrait silencieusement des KPI.
     */
    public function testEveryEnumCaseContributesToTotal(): void
    {
        $counts = [];
        foreach (EncodingState::cases() as $state) {
            $counts[$state->value] = 1;
        }

        self::assertSame(
            count(EncodingState::cases()),
            $this->summary($counts)->totalMissions,
        );
    }
}
