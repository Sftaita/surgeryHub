<?php

namespace App\Tests\Unit\Service;

use App\Dto\EncodingStateFacts;
use App\Enum\EncodingState;
use App\Enum\MissionStatus;
use App\Service\EncodingStateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Suivi des encodages (D-092) — verrouille la définition canonique de EncodingState.
 *
 * Ces tests sont le contrat : toute évolution de la dérivation doit passer ici d'abord.
 * Le test d'exhaustivité en fin de fichier garantit qu'un MissionStatus ajouté à l'enum
 * ne peut pas être oublié silencieusement.
 */
final class EncodingStateResolverTest extends TestCase
{
    private const NOW = '2026-09-11 12:00:00';

    private EncodingStateResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EncodingStateResolver();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function facts(
        MissionStatus $status,
        string $endAt = '2026-09-11 10:00:00',
        ?string $encodingStartedAt = null,
        ?string $invoiceGeneratedAt = null,
        int $interventionCount = 0,
        int $activeMaterialLineCount = 0,
        bool $hasExecutionActuals = false,
    ): EncodingStateFacts {
        return new EncodingStateFacts(
            status: $status,
            endAt: new \DateTimeImmutable($endAt),
            encodingStartedAt: $encodingStartedAt !== null ? new \DateTimeImmutable($encodingStartedAt) : null,
            invoiceGeneratedAt: $invoiceGeneratedAt !== null ? new \DateTimeImmutable($invoiceGeneratedAt) : null,
            interventionCount: $interventionCount,
            activeMaterialLineCount: $activeMaterialLineCount,
            hasExecutionActuals: $hasExecutionActuals,
        );
    }

    private function resolve(EncodingStateFacts $facts): EncodingState
    {
        return $this->resolver->resolve($facts, $this->now());
    }

    /** Cas 1 — mission future, rien de saisi. */
    public function testFutureMissionWithoutEncodingIsUpcoming(): void
    {
        $facts = $this->facts(MissionStatus::ASSIGNED, endAt: '2026-09-12 18:00:00');

        self::assertSame(EncodingState::UPCOMING, $this->resolve($facts));
    }

    /** Cas 2 — mission terminée, aucun encodage : c'est le manquement à repérer. */
    public function testFinishedMissionWithoutEncodingIsToEncode(): void
    {
        $facts = $this->facts(MissionStatus::ASSIGNED, endAt: '2026-09-11 10:00:00');

        self::assertSame(EncodingState::TO_ENCODE, $this->resolve($facts));
    }

    /** Borne stricte : une mission qui se termine à l'instant exact du calcul est terminée. */
    public function testMissionEndingExactlyNowIsToEncode(): void
    {
        $facts = $this->facts(MissionStatus::ASSIGNED, endAt: self::NOW);

        self::assertSame(EncodingState::TO_ENCODE, $this->resolve($facts));
    }

    /** IN_PROGRESS (D-064, fenêtre horaire) n'est PAS un encodage commencé. */
    public function testTemporalInProgressWithoutEncodingIsNotEncodingInProgress(): void
    {
        $facts = $this->facts(MissionStatus::IN_PROGRESS, endAt: '2026-09-11 18:00:00');

        self::assertSame(EncodingState::UPCOMING, $this->resolve($facts));
    }

    public function testExplicitEncodingInProgressStatusIsInProgress(): void
    {
        $facts = $this->facts(MissionStatus::ENCODING_IN_PROGRESS);

        self::assertSame(EncodingState::IN_PROGRESS, $this->resolve($facts));
    }

    /**
     * Cas 3 — start() est optionnel (D-070) : des données saisies depuis ASSIGNED
     * suffisent à sortir de TO_ENCODE, sinon on afficherait "à encoder" sur une mission
     * déjà largement encodée.
     */
    public function testEncodingEvidenceWithoutExplicitStartIsInProgress(): void
    {
        self::assertSame(EncodingState::IN_PROGRESS, $this->resolve(
            $this->facts(MissionStatus::ASSIGNED, interventionCount: 1),
        ), 'une intervention saisie est une preuve d\'encodage');

        self::assertSame(EncodingState::IN_PROGRESS, $this->resolve(
            $this->facts(MissionStatus::ASSIGNED, activeMaterialLineCount: 1),
        ), 'une ligne de matériel active est une preuve d\'encodage');

        self::assertSame(EncodingState::IN_PROGRESS, $this->resolve(
            $this->facts(MissionStatus::ASSIGNED, hasExecutionActuals: true),
        ), 'des heures réelles sont une preuve d\'encodage');

        self::assertSame(EncodingState::IN_PROGRESS, $this->resolve(
            $this->facts(MissionStatus::ASSIGNED, encodingStartedAt: '2026-09-11 11:00:00'),
        ), 'un start() explicite est une preuve d\'encodage');
    }

    /** Une mission future dont l'encodage a déjà commencé n'est plus "à venir". */
    public function testFutureMissionWithEncodingEvidenceIsInProgress(): void
    {
        $facts = $this->facts(MissionStatus::ASSIGNED, endAt: '2026-09-12 18:00:00', interventionCount: 2);

        self::assertSame(EncodingState::IN_PROGRESS, $this->resolve($facts));
    }

    /** Cas 4 — SUBMITTED : action manager attendue, jamais un verrouillage. */
    public function testSubmittedMissionIsSubmitted(): void
    {
        $facts = $this->facts(MissionStatus::SUBMITTED, interventionCount: 3, activeMaterialLineCount: 5);

        self::assertSame(EncodingState::SUBMITTED, $this->resolve($facts));
    }

    /**
     * Cas 5 — VALIDATED reste distinct de LOCKED bien que validate() pose
     * encodingLockedAt : la mission est encore réouvrable (reopen()).
     */
    public function testValidatedMissionIsValidatedNotLocked(): void
    {
        $facts = $this->facts(MissionStatus::VALIDATED, interventionCount: 1);

        self::assertSame(EncodingState::VALIDATED, $this->resolve($facts));
    }

    /** Cas 6 — CLOSED est terminal (reopen() le refuse) donc verrouillé. */
    public function testClosedMissionIsLocked(): void
    {
        $facts = $this->facts(MissionStatus::CLOSED, interventionCount: 1);

        self::assertSame(EncodingState::LOCKED, $this->resolve($facts));
    }

    /** Cas 7 — une facture émise verrouille comptablement, même statut VALIDATED. */
    public function testInvoiceGeneratedTakesPrecedenceOverValidated(): void
    {
        $facts = $this->facts(MissionStatus::VALIDATED, invoiceGeneratedAt: '2026-09-11 09:00:00');

        self::assertSame(EncodingState::LOCKED, $this->resolve($facts));
    }

    /** Cas 8 — DECLARED participe au cycle (complete() l'accepte comme source, D-070). */
    public function testDeclaredMissionParticipatesInEncodingCycle(): void
    {
        self::assertSame(EncodingState::TO_ENCODE, $this->resolve(
            $this->facts(MissionStatus::DECLARED, endAt: '2026-09-11 10:00:00'),
        ));

        self::assertSame(EncodingState::IN_PROGRESS, $this->resolve(
            $this->facts(MissionStatus::DECLARED, interventionCount: 1),
        ));
    }

    /**
     * Cas 9 — une mission annulée ne doit JAMAIS apparaître comme un retard d'encodage,
     * même si l'instrumentiste avait commencé à saisir avant l'annulation.
     */
    public function testCancelledMissionIsNotApplicableEvenWithEncodingEvidence(): void
    {
        $facts = $this->facts(
            MissionStatus::CANCELLED,
            interventionCount: 4,
            activeMaterialLineCount: 9,
            hasExecutionActuals: true,
        );

        self::assertSame(EncodingState::NOT_APPLICABLE, $this->resolve($facts));
    }

    /** DRAFT/OPEN/REJECTED sont hors cycle au même titre que CANCELLED. */
    public function testNonEncodableStatusesAreNotApplicable(): void
    {
        foreach ([MissionStatus::DRAFT, MissionStatus::OPEN, MissionStatus::REJECTED] as $status) {
            self::assertSame(
                EncodingState::NOT_APPLICABLE,
                $this->resolve($this->facts($status)),
                sprintf('%s ne participe pas au cycle d\'encodage', $status->value),
            );
        }
    }

    /**
     * Une mission NOT_APPLICABLE n'est jamais un manquement : c'est ce qui empêche les
     * missions annulées de polluer éternellement le KPI "à encoder".
     */
    public function testOnlyNotApplicableIsExcludedFromExpectedEncoding(): void
    {
        self::assertFalse(EncodingState::NOT_APPLICABLE->isEncodingExpected());

        foreach (EncodingState::cases() as $state) {
            if ($state !== EncodingState::NOT_APPLICABLE) {
                self::assertTrue($state->isEncodingExpected(), $state->value);
            }
        }
    }

    public function testActionAttributionIsMutuallyExclusive(): void
    {
        self::assertTrue(EncodingState::SUBMITTED->requiresManagerAction());
        self::assertTrue(EncodingState::TO_ENCODE->requiresInstrumentistAction());
        self::assertTrue(EncodingState::IN_PROGRESS->requiresInstrumentistAction());

        foreach (EncodingState::cases() as $state) {
            self::assertFalse(
                $state->requiresManagerAction() && $state->requiresInstrumentistAction(),
                sprintf('%s ne peut pas attendre les deux acteurs à la fois', $state->value),
            );
        }
    }

    /**
     * Garde-fou d'exhaustivité : tout MissionStatus doit produire un EncodingState
     * déterministe. Si un statut est ajouté à l'enum sans décision consciente dans le
     * resolver, ce test échoue — c'est exactement son intérêt.
     */
    public function testEveryMissionStatusResolvesDeterministically(): void
    {
        foreach (MissionStatus::cases() as $status) {
            $state = $this->resolve($this->facts($status));

            self::assertInstanceOf(
                EncodingState::class,
                $state,
                sprintf('%s ne produit aucun EncodingState', $status->value),
            );

            // Idempotence : deux résolutions des mêmes faits au même instant sont égales.
            self::assertSame($state, $this->resolve($this->facts($status)), $status->value);
        }
    }

    /** Une mission sans endAt ne peut pas être déclarée en retard. */
    public function testMissingEndAtFallsBackToUpcoming(): void
    {
        $facts = new EncodingStateFacts(
            status: MissionStatus::ASSIGNED,
            endAt: null,
            encodingStartedAt: null,
            invoiceGeneratedAt: null,
            interventionCount: 0,
            activeMaterialLineCount: 0,
            hasExecutionActuals: false,
        );

        self::assertSame(EncodingState::UPCOMING, $this->resolve($facts));
    }
}
