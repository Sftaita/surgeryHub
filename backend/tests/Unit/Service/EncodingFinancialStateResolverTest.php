<?php

namespace App\Tests\Unit\Service;

use App\Dto\MissionFinancialFacts;
use App\Enum\EncodingFinancialState;
use App\Enum\EncodingState;
use App\Service\EncodingFinancialStateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Suivi des encodages (D-118) — statut financier synthétique.
 */
final class EncodingFinancialStateResolverTest extends TestCase
{
    private EncodingFinancialStateResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EncodingFinancialStateResolver();
    }

    private function facts(
        bool $hasActiveCalculation = false,
        bool $hasIssuedDocument = false,
        bool $allIssuedDocumentsPaid = false,
        bool $hasUnresolvedCalculationFailure = false,
    ): MissionFinancialFacts {
        return new MissionFinancialFacts(
            $hasActiveCalculation,
            $hasIssuedDocument,
            $allIssuedDocumentsPaid,
            $hasUnresolvedCalculationFailure,
        );
    }

    /** Une mission encore en encodage n'est pas une anomalie : c'est le cas normal. */
    public function testMissionStillBeingEncodedIsNotCalculable(): void
    {
        foreach ([EncodingState::UPCOMING, EncodingState::TO_ENCODE, EncodingState::IN_PROGRESS, EncodingState::SUBMITTED] as $state) {
            self::assertSame(
                EncodingFinancialState::NOT_CALCULABLE,
                $this->resolver->resolve($state, MissionFinancialFacts::none()),
                $state->value,
            );
        }
    }

    /** Seul VALIDATED est facturable — et le calcul n'est jamais automatique (D-073). */
    public function testValidatedWithoutCalculationIsToCalculate(): void
    {
        self::assertSame(
            EncodingFinancialState::TO_CALCULATE,
            $this->resolver->resolve(EncodingState::VALIDATED, MissionFinancialFacts::none()),
        );
    }

    /**
     * Une mission CLOSED sans aucun artefact financier n'a rien en attente : la présenter
     * comme "à calculer" créerait une tâche fantôme qui ne disparaîtrait jamais.
     */
    public function testLockedWithoutAnyFinancialArtifactIsNotCalculable(): void
    {
        self::assertSame(
            EncodingFinancialState::NOT_CALCULABLE,
            $this->resolver->resolve(EncodingState::LOCKED, MissionFinancialFacts::none()),
        );
    }

    public function testActiveCalculationIsCalculated(): void
    {
        self::assertSame(
            EncodingFinancialState::CALCULATED,
            $this->resolver->resolve(EncodingState::VALIDATED, $this->facts(hasActiveCalculation: true)),
        );
    }

    public function testIssuedDocumentIsDocumented(): void
    {
        self::assertSame(
            EncodingFinancialState::DOCUMENTED,
            $this->resolver->resolve(EncodingState::LOCKED, $this->facts(
                hasActiveCalculation: true,
                hasIssuedDocument: true,
            )),
        );
    }

    public function testFullyPaidDocumentsArePaid(): void
    {
        self::assertSame(
            EncodingFinancialState::PAID,
            $this->resolver->resolve(EncodingState::LOCKED, $this->facts(
                hasActiveCalculation: true,
                hasIssuedDocument: true,
                allIssuedDocumentsPaid: true,
            )),
        );
    }

    /**
     * Le cas qui motive l'ordre de priorité : recalculate() qui échoue laisse l'ancien
     * calcul actif (D-073). Afficher "Calculé" masquerait le fait qu'une action a échoué.
     */
    public function testFailedRecalculationOutranksStaleActiveCalculation(): void
    {
        self::assertSame(
            EncodingFinancialState::ANOMALY,
            $this->resolver->resolve(EncodingState::VALIDATED, $this->facts(
                hasActiveCalculation: true,
                hasUnresolvedCalculationFailure: true,
            )),
        );
    }

    /** Une fois le document émis, l'avancement réel prime sur un échec antérieur. */
    public function testIssuedDocumentOutranksAnomaly(): void
    {
        self::assertSame(
            EncodingFinancialState::DOCUMENTED,
            $this->resolver->resolve(EncodingState::LOCKED, $this->facts(
                hasIssuedDocument: true,
                hasUnresolvedCalculationFailure: true,
            )),
        );
    }

    /** Seule l'anomalie réclame une action ; tout le reste est un avancement normal. */
    public function testOnlyAnomalyIsBlocking(): void
    {
        foreach (EncodingFinancialState::cases() as $state) {
            self::assertSame(
                $state === EncodingFinancialState::ANOMALY,
                $state->isBlocking(),
                $state->value,
            );
        }
    }

    /** Tout couple (état d'encodage, faits) produit un statut — jamais de trou. */
    public function testEveryEncodingStateResolvesDeterministically(): void
    {
        foreach (EncodingState::cases() as $state) {
            self::assertInstanceOf(
                EncodingFinancialState::class,
                $this->resolver->resolve($state, MissionFinancialFacts::none()),
                $state->value,
            );
        }
    }
}
