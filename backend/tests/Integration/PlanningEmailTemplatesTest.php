<?php

namespace App\Tests\Integration;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Batch 12 regression — SendBillingEmailMessageHandler renders `$message->htmlTemplate`
 * by a plain string path with no compile-time reference, so a missing/renamed Twig file
 * is invisible to static analysis and to PlanningDeployPdfsHandlerTest (which mocks the
 * mailer/twig layer entirely). It only surfaces at runtime, as a silently-retried-then-
 * dropped Messenger failure — found during Batch 12 live validation: every post-deploy
 * "here is your planning" email to instrumentists/surgeons was failing because
 * `emails/planning_instrumentist.html.twig` and `emails/planning_surgeon.html.twig` did
 * not exist (only the PDF-generation and change-summary-email templates did). This test
 * renders every template referenced by PlanningDeployPdfsMessageHandler/
 * SendBillingEmailMessageHandler for real, through the actual Twig environment, so a
 * missing or syntactically broken template fails CI instead of failing silently in prod.
 */
final class PlanningEmailTemplatesTest extends KernelTestCase
{
    private function twig(): Environment
    {
        self::bootKernel();
        return self::getContainer()->get(Environment::class);
    }

    private function makeUser(string $email, string $firstname): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setFirstname($firstname);
        $u->setLastname('Test');
        return $u;
    }

    public function test_planning_instrumentist_email_renders(): void
    {
        // No availableMissions/availableMissionsUrl passed — must still render via the
        // template's `|default([])` fallback (defensive, in case a future caller forgets them).
        $html = $this->twig()->render('emails/planning_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-08-01'),
            'periodTo'      => new \DateTimeImmutable('2026-08-31'),
            'missionCount'  => 5,
        ]);

        $this->assertStringContainsString('Carla', $html);
        $this->assertStringContainsString('01/08/2026', $html);
        $this->assertStringContainsString('5', $html);
        $this->assertStringNotContainsString('Missions disponibles', $html, 'No available missions passed — the section must not render at all.');
    }

    public function test_planning_instrumentist_email_renders_available_missions_section(): void
    {
        $html = $this->twig()->render('emails/planning_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-08-01'),
            'periodTo'      => new \DateTimeImmutable('2026-08-31'),
            'missionCount'  => 5,
            'availableMissions' => [
                [
                    'missionId'   => 42,
                    'date'        => '12/08/2026',
                    'moment'      => 'Matin',
                    'horaire'     => '08:00–13:00',
                    'siteName'    => 'Bloc opératoire Delta',
                    'surgeonName' => 'Jean Dupont',
                    'typeLabel'   => 'Bloc',
                ],
            ],
            'availableMissionsUrl' => 'https://app.surgicalhub.be/app/i/offers',
        ]);

        $this->assertStringContainsString('Missions disponibles (1)', $html);
        $this->assertStringContainsString('12/08/2026', $html);
        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('Bloc opératoire Delta', $html);
        $this->assertStringContainsString('Voir les missions disponibles', $html);
        $this->assertStringContainsString('https://app.surgicalhub.be/app/i/offers', $html);
        // The PDF only ever lists this recipient's own (assigned) missions — the available-
        // missions section must never restate the personal "Missions"/"Affectées" tile values.
        $this->assertStringContainsString('5', $html);
    }

    public function test_planning_surgeon_email_renders(): void
    {
        $html = $this->twig()->render('emails/planning_surgeon.html.twig', [
            'surgeon'        => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom'     => new \DateTimeImmutable('2026-08-01'),
            'periodTo'       => new \DateTimeImmutable('2026-08-31'),
            'totalCount'     => 5,
            'coveredCount'   => 3,
            'uncoveredCount' => 2,
        ]);

        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('proposées aux instrumentistes', $html,
            'When uncoveredCount > 0, the explanatory paragraph must render (no per-post table anymore, D-058).'
        );

        // UX wording pass (2026-07): natural business wording, not technical jargon.
        $this->assertStringContainsString('Missions', $html);
        $this->assertStringContainsString('Affectées', $html);
        $this->assertStringContainsString("En attente d'affectation", $html);
        $this->assertStringContainsString('nouvel email afin de vous laisser le temps', $html);
        $this->assertStringNotContainsString('Séances', $html, 'Old label must no longer appear.');
        $this->assertStringNotContainsString('Couvertes', $html, 'Old label must no longer appear (Affectées instead).');
        $this->assertStringNotContainsString('Non couvertes', $html, "Old label must no longer appear (En attente d'affectation instead).");
        $this->assertStringNotContainsString('Missions disponibles', $html, 'The "missions disponibles" section is instrumentist-only — must never appear in the surgeon email.');
    }

    public function test_planning_manager_email_renders(): void
    {
        $html = $this->twig()->render('emails/planning_manager.html.twig', [
            'manager'       => $this->makeUser('mgr@test.com', 'Marc'),
            'periodFrom'    => new \DateTimeImmutable('2026-08-01'),
            'periodTo'      => new \DateTimeImmutable('2026-08-31'),
            'missionCount'  => 10,
            'assignedCount' => 8,
            'openPoolCount' => 2,
        ]);

        $this->assertStringContainsString('Marc', $html);
        $this->assertStringContainsString('Déploiement confirmé', $html);
    }

    public function test_planning_change_summary_instrumentist_email_renders(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-08-01'),
            'periodTo'      => new \DateTimeImmutable('2026-08-31'),
            'added'         => [],
            'removed'       => [],
            'modified'      => [],
            'uncovered'     => [],
        ]);

        $this->assertStringContainsString('Carla', $html);
    }

    // ── Diff sections — planning_change_summary_instrumentist.html.twig ────────────────
    // Real Twig rendering (strict_variables: true), real diff-shaped fixture data, matching
    // exactly what PlanningDiffService::serializeMission()/detectChanges() and
    // PlanningModificationService produce. Regression guard for the "aucun email reçu" bug:
    // this template is rendered inside the async Messenger worker (SendBillingEmailMessageHandler),
    // decoupled from the HTTP request — a rendering exception there is invisible to the user
    // who triggered the redeploy, so every section must be proven to render without throwing.

    private function addedMissionFixture(array $overrides = []): array
    {
        return array_merge([
            'missionId'         => 501,
            'date'              => '2026-09-18',
            'period'            => 'PM',
            'startAt'           => '13:00',
            'endAt'             => '18:00',
            'missionType'       => 'BLOCK',
            'surgeonId'         => 10,
            'surgeonName'       => 'Jean Dupont',
            'instrumentistId'   => 20,
            'instrumentistName' => 'Léa Martin',
            'siteId'            => 5,
            'siteName'          => 'Bloc opératoire Delta',
        ], $overrides);
    }

    private function modifiedEntryFixture(array $missionOverrides = [], array $changes = []): array
    {
        return [
            'mission' => $this->addedMissionFixture($missionOverrides),
            'changes' => $changes,
        ];
    }

    public function test_instrumentist_email_renders_added_only(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-09-01'),
            'periodTo'      => new \DateTimeImmutable('2026-09-30'),
            'added'         => [$this->addedMissionFixture()],
            'removed'       => [],
            'modified'      => [],
            'uncovered'     => [],
        ]);

        $this->assertStringContainsString('Modifications (1)', $html);
        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('Bloc opératoire Delta', $html);
        $this->assertStringContainsString('Léa Martin', $html);
    }

    public function test_instrumentist_email_renders_modified_only_with_schedule_and_instrumentist_change(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-09-01'),
            'periodTo'      => new \DateTimeImmutable('2026-09-30'),
            'added'         => [],
            'removed'       => [],
            'modified'      => [$this->modifiedEntryFixture([], [
                'schedule' => [
                    'from' => ['startAt' => '08:00', 'endAt' => '13:00'],
                    'to'   => ['startAt' => '09:00', 'endAt' => '14:00'],
                ],
                'instrumentist' => [
                    'from' => ['id' => 2, 'name' => 'Diane Morel'],
                    'to'   => ['id' => 20, 'name' => 'Léa Martin'],
                ],
            ])],
            'uncovered' => [],
        ]);

        $this->assertStringContainsString('Modifications', $html);
        $this->assertStringContainsString('08:00', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('Diane Morel', $html);
        $this->assertStringContainsString('Léa Martin', $html);
    }

    public function test_instrumentist_email_renders_removed_only(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-09-01'),
            'periodTo'      => new \DateTimeImmutable('2026-09-30'),
            'added'         => [],
            'removed'       => [$this->addedMissionFixture(['instrumentistId' => null, 'instrumentistName' => null])],
            'modified'      => [],
            'uncovered'     => [],
        ]);

        $this->assertStringContainsString('Modifications (1)', $html);
        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('Annulée', $html);
    }

    public function test_instrumentist_email_renders_added_modified_and_removed_together_in_one_email(): void
    {
        // The core "one recipient, one consolidated email, all their changes" requirement.
        $html = $this->twig()->render('emails/planning_change_summary_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-09-01'),
            'periodTo'      => new \DateTimeImmutable('2026-09-30'),
            'added'         => [$this->addedMissionFixture(['missionId' => 601, 'surgeonName' => 'Nouveau Chirurgien'])],
            'removed'       => [$this->addedMissionFixture(['missionId' => 602, 'surgeonName' => 'Ancien Chirurgien'])],
            'modified'      => [$this->modifiedEntryFixture(
                ['missionId' => 603, 'surgeonName' => 'Chirurgien Modifié'],
                ['schedule' => ['from' => ['startAt' => '08:00', 'endAt' => '13:00'], 'to' => ['startAt' => '10:00', 'endAt' => '15:00']]],
            )],
            'uncovered' => [],
        ]);

        $this->assertStringContainsString('Nouveau Chirurgien', $html);
        $this->assertStringContainsString('Ancien Chirurgien', $html);
        $this->assertStringContainsString('Chirurgien Modifié', $html);
        $this->assertStringContainsString('Modifications (3)', $html, 'One consolidated list — added + modified + removed all count towards the same header.');
    }

    public function test_instrumentist_email_renders_open_mission_with_no_instrumentist_in_added(): void
    {
        // A mission added to the pool (no instrumentist yet) must not throw on the null field.
        $html = $this->twig()->render('emails/planning_change_summary_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-09-01'),
            'periodTo'      => new \DateTimeImmutable('2026-09-30'),
            'added'         => [$this->addedMissionFixture(['instrumentistId' => null, 'instrumentistName' => null])],
            'removed'       => [],
            'modified'      => [],
            'uncovered'     => [],
        ]);

        $this->assertStringContainsString('Jean Dupont', $html);
    }

    public function test_instrumentist_email_renders_modified_with_absent_old_and_new_instrumentist(): void
    {
        // changes.instrumentist.from === null (previously OPEN) and .to === null (now OPEN)
        // must both render the "Aucun" fallback rather than throwing on ->name of null.
        $html = $this->twig()->render('emails/planning_change_summary_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-09-01'),
            'periodTo'      => new \DateTimeImmutable('2026-09-30'),
            'added'         => [],
            'removed'       => [],
            'modified'      => [$this->modifiedEntryFixture([], [
                'instrumentist' => ['from' => null, 'to' => null],
            ])],
            'uncovered' => [],
        ]);

        $this->assertStringContainsString('Aucun', $html);
    }

    public function test_absences_request_missing_email_renders(): void
    {
        $instr = $this->makeUser('instr@test.com', 'Carla');
        $instr->setRoles(['ROLE_INSTRUMENTIST']);
        $instr->setLastname('Dupont');

        $html = $this->twig()->render('emails/absences_request_missing.html.twig', [
            'user'    => $instr,
            'greeting' => 'Bonjour Carla',
            'message' => 'Merci de nous transmettre vos congés à boost.conge@gmail.com.',
        ]);

        $this->assertStringContainsString('Bonjour Carla,', $html);
        $this->assertStringContainsString('Merci de nous transmettre vos congés à boost.conge@gmail.com.', $html);
        // boost.conge@gmail.com only ever appears as plain text inside the message — never
        // as a structural/recipient element of the template itself.
        $this->assertSame(1, substr_count($html, 'boost.conge@gmail.com'));
    }

    public function test_absences_confirm_encoded_email_renders(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'Alice');
        $surgeon->setRoles(['ROLE_SURGEON']);
        $surgeon->setLastname('Martin');

        $absence = new \App\Entity\Absence();
        $absence->setUser($surgeon);
        $absence->setDateStart(new \DateTimeImmutable('2026-09-10'));
        $absence->setDateEnd(new \DateTimeImmutable('2026-09-15'));
        $absence->setCreatedBy($surgeon);

        $message = 'Voici le récapitulatif. À terme, cette confirmation se fera directement via votre espace SurgicalHub.';
        $html = $this->twig()->render('emails/absences_confirm_encoded.html.twig', [
            'user'    => $surgeon,
            'greeting' => 'Bonjour Dr Martin',
            'absences' => [$absence],
            'message' => $message,
        ]);

        $this->assertStringContainsString('Bonjour Dr Martin,', $html);
        // Period format: "01/07/2026 → 15/07/2026" — never "du ... au ...".
        $this->assertStringContainsString('10/09/2026 → 15/09/2026', $html);
        $this->assertStringNotContainsString('du 10/09/2026', $html);
        $this->assertStringContainsString($message, $html);
        // The template must never repeat a "à terme..." note of its own — the message is the
        // single source for that sentence (regression guard for the duplicated-text bug).
        $this->assertSame(1, substr_count($html, 'À terme'));
    }

    public function test_absences_confirm_encoded_email_renders_isolated_day_without_arrow(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'Alice');
        $surgeon->setRoles(['ROLE_SURGEON']);
        $surgeon->setLastname('Martin');

        $isolatedDay = new \App\Entity\Absence();
        $isolatedDay->setUser($surgeon);
        $isolatedDay->setDateStart(new \DateTimeImmutable('2026-07-07'));
        $isolatedDay->setDateEnd(new \DateTimeImmutable('2026-07-07'));
        $isolatedDay->setCreatedBy($surgeon);

        $html = $this->twig()->render('emails/absences_confirm_encoded.html.twig', [
            'user'    => $surgeon,
            'greeting' => 'Bonjour Dr Martin',
            'absences' => [$isolatedDay],
            'message' => 'Récapitulatif.',
        ]);

        $this->assertStringContainsString('07/07/2026', $html);
        $this->assertStringNotContainsString('07/07/2026 →', $html, 'An isolated day must render as a single date, never with an arrow range');
    }

    public function test_planning_change_summary_surgeon_email_renders(): void
    {
        // Batch 15K rewrite: this template now mirrors the instrumentist one (added/modified/
        // removed diff sections) instead of the old standalone "pool awareness" digest — see
        // PlanningChangeSummaryService's surgeon-targeting fix (only a surgeon whose OWN
        // intervention changed is notified; strict_variables:true means all 4 keys are required
        // on every render, regardless of which sections are actually populated).
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-08-01'),
            'periodTo'   => new \DateTimeImmutable('2026-08-31'),
            'added'      => [],
            'removed'    => [],
            'modified'   => [],
            'uncovered'  => [],
        ]);

        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Votre planning a été modifié', $html);
    }

    // ── Diff sections — planning_change_summary_surgeon.html.twig ──────────────────────

    public function test_surgeon_email_renders_added_only(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-09-01'),
            'periodTo'   => new \DateTimeImmutable('2026-09-30'),
            'added'      => [$this->addedMissionFixture()],
            'removed'    => [],
            'modified'   => [],
            'uncovered'  => [],
        ]);

        $this->assertStringContainsString('Modifications (1)', $html);
        $this->assertStringContainsString('Bloc opératoire Delta', $html);
        $this->assertStringContainsString('Léa Martin', $html);
    }

    public function test_surgeon_email_renders_modified_only_with_instrumentist_change(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-09-01'),
            'periodTo'   => new \DateTimeImmutable('2026-09-30'),
            'added'      => [],
            'removed'    => [],
            'modified'   => [$this->modifiedEntryFixture([], [
                'instrumentist' => [
                    'from' => ['id' => 2, 'name' => 'Diane Morel'],
                    'to'   => ['id' => 20, 'name' => 'Léa Martin'],
                ],
            ])],
            'uncovered' => [],
        ]);

        $this->assertStringContainsString('Modifications (1)', $html);
        $this->assertStringContainsString('Diane Morel', $html);
        $this->assertStringContainsString('Léa Martin', $html);
    }

    public function test_surgeon_email_renders_removed_only(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-09-01'),
            'periodTo'   => new \DateTimeImmutable('2026-09-30'),
            'added'      => [],
            'removed'    => [$this->addedMissionFixture(['instrumentistId' => null, 'instrumentistName' => null])],
            'modified'   => [],
            'uncovered'  => [],
        ]);

        $this->assertStringContainsString('Modifications (1)', $html);
        $this->assertStringContainsString('Annulée', $html);
    }

    public function test_surgeon_email_renders_added_modified_and_removed_together_in_one_email(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-09-01'),
            'periodTo'   => new \DateTimeImmutable('2026-09-30'),
            'added'      => [$this->addedMissionFixture(['missionId' => 601])],
            'removed'    => [$this->addedMissionFixture(['missionId' => 602])],
            'modified'   => [$this->modifiedEntryFixture(
                ['missionId' => 603],
                ['schedule' => ['from' => ['startAt' => '08:00', 'endAt' => '13:00'], 'to' => ['startAt' => '10:00', 'endAt' => '15:00']]],
            )],
            'uncovered' => [],
        ]);

        $this->assertStringContainsString('Modifications (3)', $html, 'One consolidated list — added + modified + removed all count towards the same header.');
    }

    public function test_surgeon_email_renders_open_mission_with_no_instrumentist_in_uncovered(): void
    {
        // A newly-added mission still without an instrumentist surfaces in the "uncovered"
        // section (myStillUncovered in PlanningChangeSummaryService) — must not throw on null.
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-09-01'),
            'periodTo'   => new \DateTimeImmutable('2026-09-30'),
            'added'      => [$this->addedMissionFixture(['instrumentistId' => null, 'instrumentistName' => null])],
            'removed'    => [],
            'modified'   => [],
            'uncovered'  => [$this->addedMissionFixture(['instrumentistId' => null, 'instrumentistName' => null])],
        ]);

        $this->assertStringContainsString('À pourvoir', $html);
    }

    public function test_planning_alert_email_renders(): void
    {
        // Context shape must match PlanningAlertRaisedMessageHandler exactly — Twig
        // runs with strict_variables: true, so a mismatched key throws here too.
        $html = $this->twig()->render('emails/planning_alert.html.twig', [
            'recipientName' => 'Manager Test',
            'alertType'     => 'SURGEON_ABSENCE',
            'missionDate'   => '2026-08-01',
            'siteName'      => 'Test Site',
        ]);

        $this->assertStringContainsString('Manager Test', $html);
    }

    // ── Absence-driven mission reaction emails ──────────────────────────────

    private function absenceMissionRowFixture(array $overrides = []): array
    {
        return array_merge([
            'missionId'         => 10,
            'changeType'        => 'RELEASED',
            'date'              => '10/09/2026',
            'moment'            => 'Matin',
            'horaire'           => '08:00–13:00',
            'siteName'          => 'Bloc opératoire Delta',
            'surgeonId'         => 5,
            'surgeonName'       => 'Jean Dupont',
            'instrumentistId'   => 20,
            'instrumentistName' => 'Léa Martin',
        ], $overrides);
    }

    public function test_absence_instrumentist_released_email_renders_multiple_missions(): void
    {
        $html = $this->twig()->render('emails/absence_instrumentist_released.html.twig', [
            'recipientName' => 'Léa',
            'missions'      => [
                $this->absenceMissionRowFixture(['missionId' => 10]),
                $this->absenceMissionRowFixture(['missionId' => 11, 'date' => '12/09/2026', 'surgeonName' => 'Alice Martin']),
            ],
            'missionCount'  => 2,
        ]);

        $this->assertStringContainsString('Missions retirées', $html);
        $this->assertStringContainsString('10/09/2026', $html);
        $this->assertStringContainsString('12/09/2026', $html);
        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('Alice Martin', $html);
    }

    public function test_absence_instrumentist_released_email_singular_wording_for_one_mission(): void
    {
        $html = $this->twig()->render('emails/absence_instrumentist_released.html.twig', [
            'recipientName' => 'Léa',
            'missions'      => [$this->absenceMissionRowFixture()],
            'missionCount'  => 1,
        ]);

        $this->assertStringContainsString('Mission retirée', $html);
        $this->assertStringNotContainsString('Missions retirées', $html);
    }

    public function test_absence_surgeon_mission_opened_email_renders(): void
    {
        $html = $this->twig()->render('emails/absence_surgeon_mission_opened.html.twig', [
            'recipientName' => 'Jean',
            'missions'      => [$this->absenceMissionRowFixture()],
            'missionCount'  => 1,
        ]);

        $this->assertStringContainsString('désormais à pourvoir', $html);
        $this->assertStringContainsString('Léa Martin', $html);
        $this->assertStringContainsString('À pourvoir', $html);
    }

    public function test_absence_surgeon_mission_opened_email_handles_null_instrumentist_name(): void
    {
        // Defensive: the released mission's own former instrumentist is always known in
        // practice, but the template must not throw if it were ever absent.
        $html = $this->twig()->render('emails/absence_surgeon_mission_opened.html.twig', [
            'recipientName' => 'Jean',
            'missions'      => [$this->absenceMissionRowFixture(['instrumentistId' => null, 'instrumentistName' => null])],
            'missionCount'  => 1,
        ]);

        $this->assertStringContainsString('Aucun', $html);
    }

    public function test_absence_mission_cancelled_email_renders(): void
    {
        $html = $this->twig()->render('emails/absence_mission_cancelled.html.twig', [
            'recipientName' => 'Léa',
            'missions'      => [$this->absenceMissionRowFixture(['changeType' => 'CANCELLED'])],
            'missionCount'  => 1,
        ]);

        $this->assertStringContainsString('Mission annulée', $html);
        $this->assertStringContainsString('absence chirurgien', $html);
        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('Annulée', $html);
    }

    public function test_absence_mission_cancelled_email_plural_wording_for_multiple_missions(): void
    {
        $html = $this->twig()->render('emails/absence_mission_cancelled.html.twig', [
            'recipientName' => 'Léa',
            'missions'      => [
                $this->absenceMissionRowFixture(['missionId' => 10, 'changeType' => 'CANCELLED']),
                $this->absenceMissionRowFixture(['missionId' => 11, 'changeType' => 'CANCELLED']),
            ],
            'missionCount'  => 2,
        ]);

        $this->assertStringContainsString('Missions annulées', $html);
    }

    // ── User email change ────────────────────────────────────────────────────

    private function userEmailChangeContext(array $overrides = []): array
    {
        return array_merge([
            'displayName' => 'Jean Martin',
            'oldEmail'    => 'ancienne@example.com',
            'newEmail'    => 'nouvelle@example.com',
        ], $overrides);
    }

    public function test_user_email_changed_old_address_html_renders(): void
    {
        $html = $this->twig()->render('emails/user_email_changed_old_address.html.twig', $this->userEmailChangeContext());

        $this->assertStringContainsString('Jean Martin', $html);
        $this->assertStringContainsString('ancienne@example.com', $html);
        $this->assertStringContainsString('nouvelle@example.com', $html);
        $this->assertStringContainsString('Votre adresse email a été modifiée', $html);
        $this->assertStringContainsString('support@surgicalhub.be', $html);
    }

    public function test_user_email_changed_old_address_txt_renders(): void
    {
        $text = $this->twig()->render('emails/user_email_changed_old_address.txt.twig', $this->userEmailChangeContext());

        $this->assertStringContainsString('Jean Martin', $text);
        $this->assertStringContainsString('ancienne@example.com', $text);
        $this->assertStringContainsString('nouvelle@example.com', $text);
    }

    public function test_user_email_changed_new_address_html_renders(): void
    {
        $html = $this->twig()->render('emails/user_email_changed_new_address.html.twig', $this->userEmailChangeContext());

        $this->assertStringContainsString('Jean Martin', $html);
        $this->assertStringContainsString('nouvelle@example.com', $html);
        $this->assertStringContainsString('Votre adresse email est confirmée', $html);
        $this->assertStringContainsString('support@surgicalhub.be', $html);
        $this->assertStringNotContainsString('ancienne@example.com', $html, 'The new-address email must never mention the old address.');
    }

    public function test_user_email_changed_new_address_txt_renders(): void
    {
        $text = $this->twig()->render('emails/user_email_changed_new_address.txt.twig', $this->userEmailChangeContext());

        $this->assertStringContainsString('Jean Martin', $text);
        $this->assertStringContainsString('nouvelle@example.com', $text);
        $this->assertStringNotContainsString('ancienne@example.com', $text);
    }
}
