<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Service\MissionActionsService;
use App\Service\MissionEncodingGuard;
use PHPUnit\Framework\TestCase;

/**
 * MissionActionsService::allowedActions() — correction 'edit_hours' : cette action était
 * exposée uniquement pour DECLARED (depuis D-013), jamais étendue aux autres statuts
 * opérationnels/encodage introduits par Lot 7 (D-070), alors que l'endpoint réel
 * (PATCH /api/missions/{id}/service, ServiceController::updateService(), gardé par
 * MissionExecutionVoter::UPDATE) autorise déjà l'instrumentiste assigné sans aucune
 * restriction de statut. allowedActions() sous-déclarait donc une capacité déjà réelle
 * côté serveur — voir ServiceControllerTest pour la preuve côté endpoint (assigné accepté,
 * étranger refusé), citée ici plutôt que dupliquée.
 *
 * Note documentée, hors périmètre de cette correction : MissionExecutionVoter::UPDATE
 * n'a par ailleurs aucune restriction de statut du tout (même sur un statut terminal type
 * CLOSED/CANCELLED/VALIDATED) — allowedActions() reste, lui, volontairement plus
 * conservateur (n'expose 'edit_hours' que sur les statuts opérationnels/encodage/DECLARED),
 * ce qui est sans risque (l'UI cache simplement l'action, l'endpoint reste par ailleurs
 * inchangé) mais mériterait un lot dédié si le Voter doit un jour être resserré.
 */
final class MissionActionsServiceTest extends TestCase
{
    private MissionActionsService $service;

    protected function setUp(): void
    {
        $this->service = new MissionActionsService(new MissionEncodingGuard());
    }

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeUser(array $roles): User
    {
        $u = new User();
        $u->setEmail('actions-' . self::$nextId . '@test.com');
        $u->setFirstname('Ada');
        $u->setLastname('Lovelace');
        $u->setRoles($roles);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    /** startAt defaults to one hour in the past so the instrumentist encoding guard passes. */
    private function makeMission(MissionStatus $status, ?User $instrumentist = null, ?\DateTimeImmutable $startAt = null): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setStartAt($startAt ?? new \DateTimeImmutable('-1 hour'));
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);
        return $m;
    }

    // ── edit_hours accordé à l'instrumentiste assigné sur les statuts compatibles ──

    public function test_assigned_instrumentist_gets_edit_hours_on_assigned(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::ASSIGNED, $instr);

        self::assertContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_assigned_instrumentist_gets_edit_hours_on_in_progress(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::IN_PROGRESS, $instr);

        self::assertContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_assigned_instrumentist_gets_edit_hours_on_encoding_in_progress(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, $instr);

        self::assertContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_owner_instrumentist_gets_edit_hours_on_declared(): void
    {
        $owner   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::DECLARED, $owner);

        self::assertContains('edit_hours', $this->service->allowedActions($mission, $owner));
    }

    // ── refus : autre instrumentiste (non assigné / non propriétaire) ──────────────

    public function test_other_instrumentist_never_gets_edit_hours_on_assigned(): void
    {
        $assigned = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $other    = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission  = $this->makeMission(MissionStatus::ASSIGNED, $assigned);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $other));
    }

    /** DECLARED : instrumentist === createdBy à la déclaration (MissionService::declare()) — "propriétaire". */
    public function test_declared_mission_edit_hours_refused_to_non_owner_instrumentist(): void
    {
        $owner   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $other   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::DECLARED, $owner);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $other));
    }

    // ── refus : manager/admin jamais présentés comme instrumentiste autorisé ───────

    public function test_manager_never_gets_edit_hours(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::ASSIGNED, $instr);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $manager));
    }

    public function test_admin_never_gets_edit_hours(): void
    {
        $admin   = $this->makeUser(['ROLE_ADMIN']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::ASSIGNED, $instr);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $admin));
    }

    // ── statuts refusés (règle réelle déjà en place, inchangée par ce correctif) ───

    public function test_rejected_mission_is_read_only(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::REJECTED, $instr);

        self::assertSame(['view'], $this->service->allowedActions($mission, $instr));
    }

    public function test_submitted_mission_refuses_edit_hours_for_instrumentist(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::SUBMITTED, $instr);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_validated_mission_refuses_edit_hours(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::VALIDATED, $instr);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_closed_mission_refuses_edit_hours(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::CLOSED, $instr);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_cancelled_mission_refuses_edit_hours(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::CANCELLED, $instr);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_open_mission_without_instrumentist_refuses_edit_hours(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::OPEN, null);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    public function test_draft_mission_refuses_edit_hours(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission(MissionStatus::DRAFT, $instr);

        self::assertNotContains('edit_hours', $this->service->allowedActions($mission, $instr));
    }

    /**
     * Point 5 (audit UX manager) — MissionPostDeployService::cancel() accepte déjà
     * DRAFT|OPEN|ASSIGNED (D-090) mais allowedActions() n'exposait 'cancel' que pour
     * OPEN/ASSIGNED : un manager n'avait donc aucun moyen d'abandonner un brouillon
     * jamais publié. Purement additif — edit/publish restent inchangés.
     */
    public function test_manager_gets_cancel_on_draft(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $mission = $this->makeMission(MissionStatus::DRAFT);

        $actions = $this->service->allowedActions($mission, $manager);
        self::assertContains('cancel', $actions);
        self::assertContains('edit', $actions);
        self::assertContains('publish', $actions);
    }

    public function test_manager_gets_cancel_on_open_and_assigned(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);

        self::assertContains('cancel', $this->service->allowedActions($this->makeMission(MissionStatus::OPEN), $manager));
        self::assertContains('cancel', $this->service->allowedActions($this->makeMission(MissionStatus::ASSIGNED, $instr), $manager));
    }

    public function test_manager_never_gets_cancel_on_submitted_validated_declared(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);

        foreach ([MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::DECLARED] as $status) {
            self::assertNotContains('cancel', $this->service->allowedActions($this->makeMission($status), $manager));
        }
    }
}
