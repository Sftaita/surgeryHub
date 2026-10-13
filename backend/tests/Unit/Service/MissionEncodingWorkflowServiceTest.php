<?php

namespace App\Tests\Unit\Service;

use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Message\MissionLifecycleChangedMessage;
use App\Exception\EncodingIncompleteException;
use App\Service\AuditService;
use App\Service\EncodingCompletenessService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionEncodingWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 7 (D-070) — MissionEncodingWorkflowService covers the whole encoding lifecycle.
 * MissionEncodingGuard has no constructor dependencies, so a real instance is used rather
 * than a mock (it is declared `final`, which PHPUnit cannot double anyway).
 */
final class MissionEncodingWorkflowServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditService&MockObject           $audit;
    private MessageBusInterface&MockObject    $bus;
    private MissionEncodingWorkflowService    $service;

    /** @var MissionLifecycleChangedMessage[] captured via the bus stub below — Envelope is final and cannot be mocked/asserted-on directly. */
    private array $dispatchedMessages = [];

    protected function setUp(): void
    {
        $this->em    = $this->createMock(EntityManagerInterface::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->bus   = $this->createMock(MessageBusInterface::class);
        $this->dispatchedMessages = [];

        $this->bus->method('dispatch')->willReturnCallback(
            function (object $msg): Envelope {
                $this->dispatchedMessages[] = $msg;
                return new Envelope($msg);
            },
        );

        $this->service = new MissionEncodingWorkflowService(
            $this->em,
            new MissionEncodingGuard(),
            $this->audit,
            $this->bus,
            new EncodingCompletenessService(),
            \App\Tests\Support\CatalogueRequestServiceFactory::contentCompleteness($this->em),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeActor(array $roles = ['ROLE_INSTRUMENTIST']): User
    {
        $u = new User();
        $u->setEmail('actor@test.com');
        $u->setFirstname('Ada');
        $u->setLastname('Lovelace');
        $u->setRoles($roles);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeManager(): User
    {
        return $this->makeActor(['ROLE_MANAGER']);
    }

    /**
     * startAt defaults to one hour in the past so the instrumentist guard check passes.
     * type defaults to BLOCK — every pre-existing test in this file implicitly assumed a
     * mission that requires material encoding; MissionType::CONSULTATION is passed
     * explicitly where that exemption is the point of the test.
     */
    /** D-143 — heures réelles complètes par défaut ($withActualHours = false pour tester leur absence). */
    private function makeMission(MissionStatus $status, ?\DateTimeImmutable $startAt = null, MissionType $type = MissionType::BLOCK, bool $withActualHours = true): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType($type);
        $m->setStartAt($startAt ?? new \DateTimeImmutable('-1 hour'));
        $this->setId($m, self::$nextId++);
        if ($withActualHours) {
            $execution = (new MissionExecution())
                ->setActualStartAt(new \DateTimeImmutable('-1 hour'))
                ->setActualEndAt(new \DateTimeImmutable('now'));
            $execution->setMission($m);
            $m->setExecution($execution);
        }
        return $m;
    }

    // ── D-143 — heures réelles obligatoires ─────────────────────────────────

    public function test_complete_refuses_without_actual_hours_and_mutates_nothing(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, withActualHours: false);
        $actor   = $this->makeActor();
        $this->audit->expects(self::never())->method('record');

        try {
            $this->service->complete($mission, $actor, 'Commentaire');
            self::fail('complete() aurait dû refuser une soumission sans heures réelles');
        } catch (EncodingIncompleteException $e) {
            self::assertSame(['ACTUAL_START_TIME', 'ACTUAL_END_TIME'], array_column($e->getMissing(), 'code'));
        }

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
        self::assertNull($mission->getSubmittedAt());
        self::assertNull($mission->getExecution(), 'jamais de copie du planifié dans le réel');
        self::assertSame([], $this->dispatchedMessages);
    }

    public function test_complete_refuses_when_only_the_start_time_is_known(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, withActualHours: false);
        $execution = (new MissionExecution())->setActualStartAt(new \DateTimeImmutable('-2 hours'));
        $execution->setMission($mission);
        $mission->setExecution($execution);

        $this->expectException(EncodingIncompleteException::class);
        $this->service->complete($mission, $this->makeActor(), 'Commentaire');
    }

    public function test_validate_refuses_a_submitted_mission_without_actual_hours(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED, withActualHours: false);

        $this->expectException(EncodingIncompleteException::class);
        $this->service->validate($mission, $this->makeManager());
    }

    /** Une durée seule déclarée avant D-143 (ancienne interface) reste validable. */
    public function test_validate_accepts_a_legacy_duration_only_declaration(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED, withActualHours: false);
        $execution = (new MissionExecution())->setActualDurationMinutes(360);
        $execution->setMission($mission);
        $mission->setExecution($execution);

        $this->service->validate($mission, $this->makeManager());

        self::assertSame(MissionStatus::VALIDATED, $mission->getStatus());
    }

    // ── start() ──────────────────────────────────────────────────────────────

    public function test_start_transitions_assigned_to_encoding_in_progress(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $actor   = $this->makeActor();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_STARTED, $this->anything());
        $this->em->expects($this->once())->method('flush');

        $this->service->start($mission, $actor);

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
        self::assertNotNull($mission->getEncodingStartedAt());
        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame(MissionChangeType::ENCODING_STARTED, $this->dispatchedMessages[0]->changeType);
    }

    public function test_start_transitions_in_progress_to_encoding_in_progress(): void
    {
        $mission = $this->makeMission(MissionStatus::IN_PROGRESS);
        $actor   = $this->makeActor();

        $this->service->start($mission, $actor);

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
    }

    public function test_start_rejects_mission_not_assigned_or_in_progress(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);
        $actor   = $this->makeActor();

        $this->em->expects($this->never())->method('flush');
        $this->expectException(ConflictHttpException::class);

        $this->service->start($mission, $actor);
    }

    public function test_start_rejects_before_mission_start_time_for_instrumentist(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED, new \DateTimeImmutable('+1 hour'));
        $actor   = $this->makeActor(['ROLE_INSTRUMENTIST']);

        $this->em->expects($this->never())->method('flush');
        $this->expectException(ConflictHttpException::class);

        $this->service->start($mission, $actor);
    }

    public function test_start_allowed_for_manager_before_mission_start_time(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED, new \DateTimeImmutable('+1 hour'));
        $actor   = $this->makeManager();

        $this->service->start($mission, $actor);

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
    }

    public function test_start_rejected_when_encoding_already_locked(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        $actor = $this->makeActor();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        $this->service->start($mission, $actor);
    }

    // ── complete() ───────────────────────────────────────────────────────────

    /** @return list<array{0: MissionStatus}> */
    public static function completableStatusesProvider(): array
    {
        return [
            [MissionStatus::DECLARED],
            [MissionStatus::ASSIGNED],
            [MissionStatus::IN_PROGRESS],
            [MissionStatus::ENCODING_IN_PROGRESS],
            [MissionStatus::SUBMITTED],
        ];
    }

    /** @dataProvider completableStatusesProvider */
    public function test_complete_transitions_to_submitted(MissionStatus $from): void
    {
        $mission = $this->makeMission($from);
        $actor   = $this->makeActor();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_COMPLETED, $this->anything());

        // Aucune ligne de matériel sur cette mission en mémoire (test unitaire, pas de
        // vraie table material_line) : le commentaire est requis, voir test dédié
        // test_complete_requires_comment_when_no_material_lines ci-dessous.
        $this->service->complete($mission, $actor, 'Consultation sans matériel utilisé.');

        self::assertSame(MissionStatus::SUBMITTED, $mission->getStatus());
        self::assertNotNull($mission->getSubmittedAt());
        self::assertSame('Consultation sans matériel utilisé.', $mission->getNoMaterialComment());
        self::assertTrue($mission->isSubmittedWithoutMaterial());
    }

    public function test_complete_requires_comment_when_no_material_lines(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS);
        $actor   = $this->makeActor();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class);

        $this->service->complete($mission, $actor, null);
    }

    public function test_complete_requires_comment_to_be_non_blank_when_no_material_lines(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS);
        $actor   = $this->makeActor();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class);

        $this->service->complete($mission, $actor, '   ');
    }

    /**
     * Une mission CONSULTATION n'attend jamais de matériel — aucun commentaire ne doit
     * être exigé même avec 0 ligne, contrairement à une mission BLOCK (voir les deux
     * tests précédents).
     */
    public function test_complete_consultation_without_material_and_without_comment_succeeds(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, type: MissionType::CONSULTATION);
        $actor   = $this->makeActor();

        $this->audit->expects($this->once())->method('record');

        $this->service->complete($mission, $actor, null);

        self::assertSame(MissionStatus::SUBMITTED, $mission->getStatus());
        self::assertTrue($mission->isSubmittedWithoutMaterial());
        self::assertNull($mission->getNoMaterialComment());
    }

    /**
     * Régression — point 3 de la revue pré-déploiement : une ligne de matériel à quantité
     * nulle ou zéro ne doit pas compter comme "du matériel encodé" (rien ne l'empêche
     * d'exister en base : MaterialLineCreateRequest/MaterialLineUpdateRequest n'ont pas de
     * contrainte Assert\Positive sur quantity aujourd'hui).
     */
    public function test_complete_ignores_zero_and_negative_quantity_material_lines(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS);
        $actor   = $this->makeActor();

        $mission->getMaterialLines()->add((new MaterialLine())->setQuantity('0.00'));
        $mission->getMaterialLines()->add((new MaterialLine())->setQuantity('-1.00'));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class);

        $this->service->complete($mission, $actor, null);
    }

    /**
     * Symétrique du test précédent : une seule ligne à quantité strictement positive parmi
     * des lignes à quantité nulle suffit à considérer la mission comme ayant du matériel —
     * aucun commentaire requis, et submittedWithoutMaterial est figé à false.
     */
    public function test_complete_counts_mission_as_having_material_with_one_positive_quantity_line(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS);
        $actor   = $this->makeActor();

        $mission->getMaterialLines()->add((new MaterialLine())->setQuantity('0.00'));
        $mission->getMaterialLines()->add((new MaterialLine())->setQuantity('2.00'));

        $this->audit->expects($this->once())->method('record');

        $this->service->complete($mission, $actor, null);

        self::assertSame(MissionStatus::SUBMITTED, $mission->getStatus());
        self::assertFalse($mission->isSubmittedWithoutMaterial());
        self::assertNull($mission->getNoMaterialComment());
    }

    /**
     * REJECTED is deliberately excluded: MissionEncodingGuard blocks it earlier with its
     * own AccessDeniedHttpException (a terminal, frozen status) — see the dedicated guard
     * test below. This list only covers statuses that reach complete()'s own status check.
     *
     * @return list<array{0: MissionStatus}>
     */
    public static function nonCompletableStatusesProvider(): array
    {
        return [
            [MissionStatus::DRAFT],
            [MissionStatus::OPEN],
            [MissionStatus::VALIDATED],
            [MissionStatus::CLOSED],
            [MissionStatus::CANCELLED],
        ];
    }

    /** @dataProvider nonCompletableStatusesProvider */
    public function test_complete_rejects_other_statuses(MissionStatus $from): void
    {
        $mission = $this->makeMission($from);
        $actor   = $this->makeActor(['ROLE_MANAGER']);

        $this->expectException(ConflictHttpException::class);

        $this->service->complete($mission, $actor);
    }

    /**
     * Point 2 de la revue pré-déploiement : soumission sans matériel avec commentaire, puis
     * correction (reject par le manager, seule voie de retour en ENCODING_IN_PROGRESS depuis
     * SUBMITTED), ajout d'une ligne de matériel, puis nouvelle soumission. Décision métier :
     * le commentaire précédent reste en base pour traçabilité, mais submittedWithoutMaterial
     * repasse à false — c'est ce flag, pas la seule présence du commentaire, qui doit gouverner
     * l'affichage manager (voir MissionDetailPage). La resoumission ne doit pas exiger de
     * nouveau commentaire puisque du matériel est désormais présent.
     */
    public function test_complete_after_correction_with_material_keeps_comment_but_clears_active_flag(): void
    {
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS);
        $instrumentist = $this->makeActor();
        $manager = $this->makeManager();

        // 1. Soumission sans matériel, commentaire obligatoire fourni.
        $this->service->complete($mission, $instrumentist, 'Consultation sans matériel utilisé.');
        self::assertTrue($mission->isSubmittedWithoutMaterial());
        self::assertSame('Consultation sans matériel utilisé.', $mission->getNoMaterialComment());

        // 2. Correction : le manager rejette (seule transition SUBMITTED → ENCODING_IN_PROGRESS).
        $this->service->reject($mission, $manager, 'Merci de vérifier le matériel utilisé.');
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());

        // 3. Ajout d'une ligne de matériel réelle.
        $mission->getMaterialLines()->add((new MaterialLine())->setQuantity('1.00'));

        // 4. Nouvelle soumission, sans commentaire — ne doit pas être bloquée.
        $this->service->complete($mission, $instrumentist, null);

        self::assertSame(MissionStatus::SUBMITTED, $mission->getStatus());
        self::assertFalse($mission->isSubmittedWithoutMaterial());
        self::assertSame(
            'Consultation sans matériel utilisé.',
            $mission->getNoMaterialComment(),
            'Le commentaire précédent doit rester en base pour traçabilité, même une fois du matériel ajouté.',
        );
    }

    public function test_complete_rejects_rejected_mission_via_guard(): void
    {
        $mission = $this->makeMission(MissionStatus::REJECTED);
        $actor   = $this->makeActor(['ROLE_MANAGER']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        $this->service->complete($mission, $actor);
    }

    /**
     * Point 5 de la revue pré-déploiement : une fois la mission verrouillée
     * (encodingLockedAt, posé par validate()), plus aucune transition complete() n'est
     * possible — donc ni le commentaire ni submittedWithoutMaterial ne peuvent plus être
     * modifiés après verrouillage. C'est la même garde que celle qui bloque déjà toute
     * autre mutation d'encodage (MissionEncodingGuard), pas un mécanisme dédié.
     */
    public function test_complete_rejects_locked_mission_via_guard(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        $actor = $this->makeManager();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        $this->service->complete($mission, $actor, 'Tentative après verrouillage.');
    }

    // ── validate() ───────────────────────────────────────────────────────────

    public function test_validate_transitions_submitted_to_validated_and_locks_encoding(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $actor   = $this->makeManager();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_VALIDATED, $this->anything());

        $this->service->validate($mission, $actor);

        self::assertSame(MissionStatus::VALIDATED, $mission->getStatus());
        self::assertNotNull($mission->getEncodingLockedAt());
        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame(MissionChangeType::ENCODING_VALIDATED, $this->dispatchedMessages[0]->changeType);
    }

    /** @dataProvider nonCompletableStatusesProvider */
    public function test_validate_rejects_mission_not_submitted(MissionStatus $from): void
    {
        if ($from === MissionStatus::VALIDATED) {
            self::markTestSkipped('VALIDATED is covered by its own reopen tests, not relevant here.');
        }

        $mission = $this->makeMission($from);
        $actor   = $this->makeManager();

        $this->expectException(ConflictHttpException::class);

        $this->service->validate($mission, $actor);
    }

    // ── reject() ─────────────────────────────────────────────────────────────

    public function test_reject_transitions_submitted_to_encoding_in_progress_with_comment(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $actor   = $this->makeManager();

        $this->em->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(\App\Entity\MissionEncodingComment::class));
        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_REJECTED, $this->callback(
                fn (array $payload) => ($payload['comment'] ?? null) === 'Matériel manquant',
            ));

        $this->service->reject($mission, $actor, 'Matériel manquant');

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
        self::assertCount(1, $mission->getEncodingComments());
        self::assertSame('Matériel manquant', $mission->getEncodingComments()->first()->getComment());
    }

    public function test_reject_rejects_mission_not_submitted(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $actor   = $this->makeManager();

        $this->em->expects($this->never())->method('persist');
        $this->expectException(ConflictHttpException::class);

        $this->service->reject($mission, $actor, 'Peu importe');
    }

    // ── reopen() ─────────────────────────────────────────────────────────────

    public function test_reopen_transitions_validated_to_encoding_in_progress_and_unlocks(): void
    {
        $mission = $this->makeMission(MissionStatus::VALIDATED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        $actor = $this->makeManager();

        $this->audit->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_REOPENED, $this->anything());

        $this->service->reopen($mission, $actor, 'Quantité incorrecte');

        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $mission->getStatus());
        self::assertNull($mission->getEncodingLockedAt());
        self::assertCount(1, $mission->getEncodingComments());
        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame(MissionChangeType::ENCODING_REOPENED, $this->dispatchedMessages[0]->changeType);
    }

    /** D-148 — motif obligatoire : un texte vide ou d'espaces ne rouvre rien. */
    public function test_reopen_with_blank_reason_is_refused_and_changes_nothing(): void
    {
        $mission = $this->makeMission(MissionStatus::VALIDATED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        $actor   = $this->makeManager();

        $this->em->expects($this->never())->method('persist');
        $this->audit->expects($this->never())->method('record');

        try {
            $this->service->reopen($mission, $actor, '   ');
            self::fail('blank reason accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
        }

        self::assertSame(MissionStatus::VALIDATED, $mission->getStatus());
        self::assertNotNull($mission->getEncodingLockedAt());
        self::assertCount(0, $this->dispatchedMessages);
    }

    /** D-148 — même règle pour le renvoi en correction. */
    public function test_reject_with_blank_reason_is_refused_and_changes_nothing(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $actor   = $this->makeManager();

        $this->em->expects($this->never())->method('persist');

        try {
            $this->service->reject($mission, $actor, '');
            self::fail('blank reason accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
        }

        self::assertSame(MissionStatus::SUBMITTED, $mission->getStatus());
        self::assertCount(0, $this->dispatchedMessages);
    }

    public function test_reject_and_reopen_audit_the_previous_and_new_status(): void
    {
        $actor = $this->makeManager();
        $payloads = [];
        $this->audit->method('record')->willReturnCallback(function ($m, $a, $type, array $payload) use (&$payloads) {
            $payloads[$type->value] = $payload;
        });

        $this->service->reject($this->makeMission(MissionStatus::SUBMITTED), $actor, '  Horaires manquants  ');
        $this->service->reopen($this->makeMission(MissionStatus::VALIDATED), $actor, 'Quantité incorrecte');

        self::assertSame(['SUBMITTED', 'ENCODING_IN_PROGRESS', 'Horaires manquants'], [
            $payloads['MISSION_ENCODING_REJECTED']['fromStatus'], $payloads['MISSION_ENCODING_REJECTED']['toStatus'], $payloads['MISSION_ENCODING_REJECTED']['comment'],
        ]);
        self::assertSame(['VALIDATED', 'ENCODING_IN_PROGRESS', 'Quantité incorrecte'], [
            $payloads['MISSION_ENCODING_REOPENED']['fromStatus'], $payloads['MISSION_ENCODING_REOPENED']['toStatus'], $payloads['MISSION_ENCODING_REOPENED']['comment'],
        ]);
    }

    public function test_reopen_rejects_closed_mission_with_dedicated_message(): void
    {
        $mission = $this->makeMission(MissionStatus::CLOSED);
        $actor   = $this->makeManager();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage(\App\Service\MissionEncodingCorrectionPolicy::CLOSED_MESSAGE);

        $this->service->reopen($mission, $actor, 'Tentative');
    }

    public function test_reopen_rejects_mission_not_validated(): void
    {
        $mission = $this->makeMission(MissionStatus::SUBMITTED);
        $actor   = $this->makeManager();

        $this->expectException(ConflictHttpException::class);

        $this->service->reopen($mission, $actor, 'Tentative');
    }

    /** EPIC Exécution & Valorisation, Lot 3 (D-073) — politique de réouverture à seuil. */
    public function test_reopen_rejects_mission_with_locked_financial_calculation(): void
    {
        $mission = $this->makeMission(MissionStatus::VALIDATED);
        $actor   = $this->makeManager();

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findOneBy')->willReturn(new \App\Entity\FinancialCalculation());
        $this->em->method('getRepository')->with(\App\Entity\FinancialCalculation::class)->willReturn($repo);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage(\App\Service\MissionEncodingCorrectionPolicy::FINANCIAL_LOCK_MESSAGE);

        $this->service->reopen($mission, $actor, 'Tentative');
    }

    // ── isBillable() / findMissionsReadyForBilling() ────────────────────────

    public function test_is_billable_true_only_for_validated(): void
    {
        self::assertTrue($this->service->isBillable($this->makeMission(MissionStatus::VALIDATED)));
        self::assertFalse($this->service->isBillable($this->makeMission(MissionStatus::SUBMITTED)));
        self::assertFalse($this->service->isBillable($this->makeMission(MissionStatus::CLOSED)));
        self::assertFalse($this->service->isBillable($this->makeMission(MissionStatus::ENCODING_IN_PROGRESS)));
    }

    public function test_find_missions_ready_for_billing_queries_validated_only(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())->method('findBy')
            ->with(['status' => MissionStatus::VALIDATED])
            ->willReturn([]);

        $this->em->expects($this->once())->method('getRepository')
            ->with(Mission::class)
            ->willReturn($repository);

        self::assertSame([], $this->service->findMissionsReadyForBilling());
    }
}
