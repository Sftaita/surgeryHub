<?php

namespace App\Tests\Unit\Service;

use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Security\Voter\MissionExecutionVoter;
use App\Security\Voter\MissionVoter;
use App\Service\MissionActionsService;
use App\Service\MissionEncodingCorrectionPolicy;
use App\Service\MissionEncodingGuard;
use App\Service\SurgeonEncodingPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

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

    // ── D-153 — corrections directes du manager / admin (MissionEncodingCorrectionPolicy) ──

    public function test_manager_and_admin_get_edit_encoding_and_edit_hours_on_correctable_statuses(): void
    {
        $instr = $this->makeUser(['ROLE_INSTRUMENTIST']);
        foreach ([['ROLE_MANAGER'], ['ROLE_ADMIN']] as $roles) {
            $manager = $this->makeUser($roles);
            foreach (MissionEncodingCorrectionPolicy::MANAGER_CORRECTABLE_STATUSES as $status) {
                $actions = $this->service->allowedActions($this->makeMission($status, $instr), $manager);
                self::assertContains('edit_encoding', $actions, $status->value);
                self::assertContains('edit_hours', $actions, $status->value);
            }
        }
    }

    public function test_manager_never_gets_direct_corrections_on_validated_closed_open_draft_or_cancelled(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        foreach ([MissionStatus::VALIDATED, MissionStatus::CLOSED, MissionStatus::OPEN, MissionStatus::DRAFT, MissionStatus::CANCELLED, MissionStatus::REJECTED] as $status) {
            $actions = $this->service->allowedActions($this->makeMission($status, $instr), $manager);
            self::assertNotContains('edit_encoding', $actions, $status->value);
            self::assertNotContains('edit_hours', $actions, $status->value);
        }
    }

    public function test_manager_never_gets_direct_corrections_on_a_locked_encoding_or_a_locked_calculation(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);

        $locked = $this->makeMission(MissionStatus::SUBMITTED, $instr);
        $locked->setEncodingLockedAt(new \DateTimeImmutable('-1 hour'));
        $financiallyLocked = $this->makeMission(MissionStatus::SUBMITTED, $instr);
        $financiallyLocked->getFinancialCalculations()->add((new FinancialCalculation())->setMission($financiallyLocked)->setStatus(FinancialCalculationStatus::LOCKED));

        foreach ([$locked, $financiallyLocked] as $mission) {
            $actions = $this->service->allowedActions($mission, $manager);
            self::assertNotContains('edit_encoding', $actions);
            self::assertNotContains('edit_hours', $actions);
        }
    }

    public function test_manager_gets_edit_encoding_but_never_edit_hours_without_an_instrumentist(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $actions = $this->service->allowedActions($this->makeMission(MissionStatus::IN_PROGRESS), $manager);

        self::assertContains('edit_encoding', $actions);
        self::assertNotContains('edit_hours', $actions, 'les heures sont celles d\'une instrumentiste');
    }

    /**
     * D-153 — allowedActions et les voters réellement appliqués aux écritures directes
     * (MissionVoter::EDIT_ENCODING, MissionExecutionVoter::UPDATE) disent toujours la même
     * chose pour un manager : aucune action proposée qu'un appel direct refuserait, aucun
     * appel direct accepté qui ne soit pas proposé.
     */
    public function test_allowed_actions_and_voters_agree_for_managers_in_every_case(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $token   = new UsernamePasswordToken($manager, 'api', $manager->getRoles());
        $missionVoter   = new MissionVoter();
        $executionVoter = new MissionExecutionVoter(new MissionEncodingGuard());

        foreach (MissionStatus::cases() as $status) {
            foreach ([null, $instr] as $assignee) {
                foreach ([null, FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::LOCKED] as $calculation) {
                    $mission = $this->makeMission($status, $assignee);
                    if ($calculation !== null) {
                        $mission->getFinancialCalculations()->add((new FinancialCalculation())->setMission($mission)->setStatus($calculation));
                    }
                    $label   = sprintf('%s / %s / %s', $status->value, $assignee ? 'assignée' : 'sans instrumentiste', $calculation?->value ?? 'sans calcul');
                    $actions = $this->service->allowedActions($mission, $manager);

                    self::assertSame(
                        $missionVoter->vote($token, $mission, [MissionVoter::EDIT_ENCODING]) === VoterInterface::ACCESS_GRANTED,
                        in_array('edit_encoding', $actions, true),
                        "edit_encoding — $label",
                    );
                    self::assertSame(
                        $executionVoter->vote($token, $mission, [MissionExecutionVoter::UPDATE]) === VoterInterface::ACCESS_GRANTED,
                        in_array('edit_hours', $actions, true),
                        "edit_hours — $label",
                    );
                }
            }
        }
    }

    /**
     * D-171 — heures encore manquantes après une validation par reprise manager : 'edit_hours'
     * (et lui seul) et MissionExecutionVoter::UPDATE disent la même chose pour le manager,
     * l'instrumentiste affectée, une autre instrumentiste et le chirurgien, quels que soient le
     * statut, le fait « validé sans heures », les heures déjà déclarées et un calcul LOCKED.
     */
    public function test_hours_pending_after_a_takeover_agree_with_the_execution_voter_for_everyone(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $other   = $this->makeUser(['ROLE_INSTRUMENTIST']);
        $surgeon = $this->makeUser(['ROLE_SURGEON']);
        $executionVoter = new MissionExecutionVoter(new MissionEncodingGuard());

        foreach (MissionStatus::cases() as $status) {
            foreach ([false, true] as $validatedWithoutHours) {
                foreach ([false, true] as $hoursDeclared) {
                    foreach ([null, FinancialCalculationStatus::LOCKED] as $calculation) {
                        $mission = $this->makeMission($status, $instr);
                        $mission->setSurgeon($surgeon);
                        if ($status === MissionStatus::VALIDATED) {
                            $mission->setEncodingLockedAt(new \DateTimeImmutable('-1 minute'));
                        }
                        if ($validatedWithoutHours) {
                            $mission->setEncodingValidatedWithoutHoursAt(new \DateTimeImmutable('-1 minute'));
                        }
                        if ($hoursDeclared) {
                            $mission->setExecution((new MissionExecution())->setMission($mission)
                                ->setActualStartAt(new \DateTimeImmutable('-3 hours'))->setActualEndAt(new \DateTimeImmutable('-1 hour')));
                        }
                        if ($calculation !== null) {
                            $mission->getFinancialCalculations()->add((new FinancialCalculation())->setMission($mission)->setStatus($calculation));
                        }
                        $label = sprintf('%s / %s / %s / %s', $status->value, $validatedWithoutHours ? 'validé sans heures' : '-',
                            $hoursDeclared ? 'heures déclarées' : 'sans heures', $calculation?->value ?? 'sans calcul');
                        $pending = $status === MissionStatus::VALIDATED && $validatedWithoutHours && !$hoursDeclared && $calculation === null;
                        self::assertSame($pending, MissionEncodingCorrectionPolicy::hoursPendingAfterValidation($mission), "politique — $label");

                        foreach (['manager' => $manager, 'instrumentiste' => $instr, 'autre instrumentiste' => $other, 'chirurgien' => $surgeon] as $who => $viewer) {
                            $token = new UsernamePasswordToken($viewer, 'api', $viewer->getRoles());
                            self::assertSame(
                                $executionVoter->vote($token, $mission, [MissionExecutionVoter::UPDATE]) === VoterInterface::ACCESS_GRANTED,
                                in_array('edit_hours', $this->service->allowedActions($mission, $viewer), true),
                                "edit_hours — $who — $label",
                            );
                        }
                        if ($pending) {
                            self::assertNotContains('edit_encoding', $this->service->allowedActions($mission, $instr), "seules les heures — $label");
                            self::assertNotContains('edit_encoding', $this->service->allowedActions($mission, $manager), "seules les heures — $label");
                        }
                    }
                }
            }
        }
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

    // ── D-120 : 'remind' (cockpit Suivi des encodages) ──────────────────────

    public function test_manager_gets_remind_on_statuses_where_encoding_is_still_open(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);

        foreach ([
            MissionStatus::ASSIGNED,
            MissionStatus::IN_PROGRESS,
            MissionStatus::ENCODING_IN_PROGRESS,
            MissionStatus::DECLARED,
        ] as $status) {
            self::assertContains(
                'remind',
                $this->service->allowedActions($this->makeMission($status, $instr), $manager),
                "expected 'remind' for status {$status->value}",
            );
        }
    }

    public function test_manager_never_gets_remind_once_submitted_or_validated(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);

        foreach ([MissionStatus::SUBMITTED, MissionStatus::VALIDATED] as $status) {
            self::assertNotContains('remind', $this->service->allowedActions($this->makeMission($status, $instr), $manager));
        }
    }

    public function test_manager_never_gets_remind_without_an_assigned_instrumentist(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);

        self::assertNotContains('remind', $this->service->allowedActions($this->makeMission(MissionStatus::ASSIGNED), $manager));
    }

    public function test_instrumentist_never_gets_remind(): void
    {
        $instr = $this->makeUser(['ROLE_INSTRUMENTIST']);

        self::assertNotContains('remind', $this->service->allowedActions($this->makeMission(MissionStatus::ASSIGNED, $instr), $instr));
    }

    // ── remind_hours (D-136) — même condition que MissionVoter::HOURS_REMIND ───────

    private function makeEndedMission(MissionStatus $status, ?User $instrumentist): Mission
    {
        $mission = $this->makeMission($status, $instrumentist, new \DateTimeImmutable('-10 hours'));
        $mission->setEndAt(new \DateTimeImmutable('-2 hours'));
        return $mission;
    }

    public function test_manager_gets_remind_hours_on_ended_mission_without_real_hours(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST']);

        $actions = $this->service->allowedActions($this->makeEndedMission(MissionStatus::ASSIGNED, $instr), $manager);

        self::assertContains('remind_hours', $actions);
        // Distinct de la relance d'encodage : les deux coexistent, aucune ne remplace l'autre.
        self::assertContains('remind', $actions);
    }

    public function test_manager_does_not_get_remind_hours_once_real_hours_are_recorded(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $mission = $this->makeEndedMission(MissionStatus::ASSIGNED, $this->makeUser(['ROLE_INSTRUMENTIST']));
        $mission->setExecution((new \App\Entity\MissionExecution())
            ->setActualStartAt(new \DateTimeImmutable('-10 hours'))
            ->setActualEndAt(new \DateTimeImmutable('-3 hours')));

        self::assertNotContains('remind_hours', $this->service->allowedActions($mission, $manager));
    }

    public function test_manager_does_not_get_remind_hours_before_end_or_without_instrumentist(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);

        $notEnded = $this->makeMission(MissionStatus::ASSIGNED, $this->makeUser(['ROLE_INSTRUMENTIST']));
        $notEnded->setEndAt(new \DateTimeImmutable('+3 hours'));
        self::assertNotContains('remind_hours', $this->service->allowedActions($notEnded, $manager));

        $unassigned = $this->makeEndedMission(MissionStatus::OPEN, null);
        self::assertNotContains('remind_hours', $this->service->allowedActions($unassigned, $manager));
    }

    public function test_manager_does_not_get_remind_hours_on_validated_mission(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER']);
        $mission = $this->makeEndedMission(MissionStatus::VALIDATED, $this->makeUser(['ROLE_INSTRUMENTIST']));

        self::assertSame(['view', 'reopen'], $this->service->allowedActions($mission, $manager));
    }

    public function test_instrumentist_never_gets_remind_hours(): void
    {
        $instr = $this->makeUser(['ROLE_INSTRUMENTIST']);

        self::assertNotContains('remind_hours', $this->service->allowedActions($this->makeEndedMission(MissionStatus::ASSIGNED, $instr), $instr));
    }

    // ── D-154 — encodage par le chirurgien de sa mission (SurgeonEncodingPolicy) ─────

    public function test_surgeon_gets_edit_encoding_on_his_started_mission_but_never_hours_or_transitions(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON']);
        foreach (SurgeonEncodingPolicy::EDITABLE_STATUSES as $status) {
            $mission = $this->makeMission($status, $this->makeUser(['ROLE_INSTRUMENTIST']));
            $mission->setSurgeon($surgeon);
            $actions = $this->service->allowedActions($mission, $surgeon);

            self::assertContains('edit_encoding', $actions, $status->value);
            foreach (['edit_hours', 'submit', 'start_encoding', 'encoding'] as $never) {
                self::assertNotContains($never, $actions, "$never — {$status->value}");
            }
        }
    }

    public function test_surgeon_never_gets_edit_encoding_on_a_colleague_mission_before_start_or_without_instrumentist(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON']);
        $instr = $this->makeUser(['ROLE_INSTRUMENTIST']);

        $colleague = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $colleague->setSurgeon($this->makeUser(['ROLE_SURGEON']));
        $future = $this->makeMission(MissionStatus::ASSIGNED, $instr, new \DateTimeImmutable('+2 hours'));
        $future->setSurgeon($surgeon);
        $alone = $this->makeMission(MissionStatus::IN_PROGRESS);
        $alone->setSurgeon($surgeon);

        foreach (['confrère' => $colleague, 'pas commencée' => $future, 'sans instrumentiste' => $alone] as $label => $mission) {
            self::assertNotContains('edit_encoding', $this->service->allowedActions($mission, $surgeon), $label);
        }
    }

    /**
     * D-154 — allowedActions et MissionVoter::EDIT_ENCODING disent toujours la même chose pour
     * le chirurgien de la mission, et MissionExecutionVoter::UPDATE (heures) le refuse toujours.
     */
    public function test_allowed_actions_and_voters_agree_for_the_mission_surgeon_in_every_case(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON']);
        $token   = new UsernamePasswordToken($surgeon, 'api', $surgeon->getRoles());
        $missionVoter   = new MissionVoter();
        $executionVoter = new MissionExecutionVoter(new MissionEncodingGuard());

        foreach (MissionStatus::cases() as $status) {
            foreach ([null, $this->makeUser(['ROLE_INSTRUMENTIST'])] as $assignee) {
                foreach (['-1 hour', '+1 hour'] as $start) {
                    foreach ([null, FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::LOCKED] as $calculation) {
                        $mission = $this->makeMission($status, $assignee, new \DateTimeImmutable($start));
                        $mission->setSurgeon($surgeon);
                        if ($calculation !== null) {
                            $mission->getFinancialCalculations()->add((new FinancialCalculation())->setMission($mission)->setStatus($calculation));
                        }
                        $label   = sprintf('%s / %s / début %s / %s', $status->value, $assignee ? 'assignée' : 'sans instrumentiste', $start, $calculation?->value ?? 'sans calcul');
                        $actions = $this->service->allowedActions($mission, $surgeon);

                        self::assertSame(
                            $missionVoter->vote($token, $mission, [MissionVoter::EDIT_ENCODING]) === VoterInterface::ACCESS_GRANTED,
                            in_array('edit_encoding', $actions, true),
                            "edit_encoding — $label",
                        );
                        self::assertNotSame(VoterInterface::ACCESS_GRANTED, $executionVoter->vote($token, $mission, [MissionExecutionVoter::UPDATE]), "heures — $label");
                        self::assertNotContains('edit_hours', $actions, $label);
                    }
                }
            }
        }
    }

    // ── D-155 — activité exécutée sans instrumentiste (ExecutedWithoutInstrumentistPolicy) ──

    /**
     * Déclarer, annuler, soumettre : allowedActions et MissionVoter disent toujours la même
     * chose, pour le chirurgien de la mission, un confrère et un manager, sur tous les statuts,
     * avec ou sans instrumentiste, avant ou après le début, déclarée ou non.
     */
    public function test_declare_cancel_and_submit_agree_with_the_voter_in_every_case(): void
    {
        $surgeon   = $this->makeUser(['ROLE_SURGEON']);
        $colleague = $this->makeUser(['ROLE_SURGEON']);
        $manager   = $this->makeUser(['ROLE_MANAGER']);
        $voter     = new MissionVoter();
        $pairs     = [
            'declare_executed_without_instrumentist' => MissionVoter::DECLARE_EXECUTED_WITHOUT_INSTRUMENTIST,
            'cancel_executed_without_instrumentist'  => MissionVoter::CANCEL_EXECUTED_WITHOUT_INSTRUMENTIST,
            // D-171 — « Terminer et valider » (manager seulement, encodage ouvert, mission commencée et couverte).
            'complete_and_validate'                  => MissionVoter::ENCODING_COMPLETE_AND_VALIDATE,
        ];

        foreach ([$surgeon, $colleague, $manager] as $viewer) {
            $token = new UsernamePasswordToken($viewer, 'api', $viewer->getRoles());
            foreach (MissionStatus::cases() as $status) {
                foreach ([null, $this->makeUser(['ROLE_INSTRUMENTIST'])] as $assignee) {
                    foreach (['-1 hour', '+1 hour'] as $start) {
                        foreach ([false, true] as $declared) {
                            $mission = $this->makeMission($status, $assignee, new \DateTimeImmutable($start));
                            $mission->setSurgeon($surgeon);
                            if ($declared) {
                                $mission->setExecutedWithoutInstrumentist(new \DateTimeImmutable('-30 minutes'), $surgeon);
                            }
                            $label = sprintf('%s / %s / %s / début %s / %s', $viewer === $manager ? 'manager' : ($viewer === $surgeon ? 'chirurgien' : 'confrère'),
                                $status->value, $assignee ? 'assignée' : 'sans instrumentiste', $start, $declared ? 'déclarée' : 'non déclarée');
                            $actions = $this->service->allowedActions($mission, $viewer);

                            foreach ($pairs as $action => $attribute) {
                                self::assertSame(
                                    $voter->vote($token, $mission, [$attribute]) === VoterInterface::ACCESS_GRANTED,
                                    in_array($action, $actions, true),
                                    "$action — $label",
                                );
                            }
                            if ($viewer !== $manager) {
                                // Chirurgien : 'submit' exactement quand MissionVoter::SUBMIT l'accorde.
                                self::assertSame(
                                    $voter->vote($token, $mission, [MissionVoter::SUBMIT]) === VoterInterface::ACCESS_GRANTED,
                                    in_array('submit', $actions, true),
                                    "submit — $label",
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    public function test_only_a_started_uncovered_open_mission_is_declarable_never_one_still_available(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON']);

        $started = $this->makeMission(MissionStatus::OPEN, null, new \DateTimeImmutable('-1 hour'));
        $started->setSurgeon($surgeon);
        self::assertContains('declare_executed_without_instrumentist', $this->service->allowedActions($started, $surgeon));

        $upcoming = $this->makeMission(MissionStatus::OPEN, null, new \DateTimeImmutable('+1 hour'));
        $upcoming->setSurgeon($surgeon);
        self::assertNotContains('declare_executed_without_instrumentist', $this->service->allowedActions($upcoming, $surgeon), 'encore disponible dans le pool');

        $started->setExecutedWithoutInstrumentist(new \DateTimeImmutable('-10 minutes'), $surgeon)->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $actions = $this->service->allowedActions($started, $surgeon);
        self::assertContains('edit_encoding', $actions);
        self::assertContains('submit', $actions);
        self::assertNotContains('edit_hours', $actions, 'jamais d\'heures sans instrumentiste');
        self::assertNotContains('declare_executed_without_instrumentist', $actions);
    }
}
