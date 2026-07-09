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
        $html = $this->twig()->render('emails/planning_instrumentist.html.twig', [
            'instrumentist' => $this->makeUser('instr@test.com', 'Carla'),
            'periodFrom'    => new \DateTimeImmutable('2026-08-01'),
            'periodTo'      => new \DateTimeImmutable('2026-08-31'),
            'missionCount'  => 5,
        ]);

        $this->assertStringContainsString('Carla', $html);
        $this->assertStringContainsString('01/08/2026', $html);
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
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-08-01'),
            'periodTo'   => new \DateTimeImmutable('2026-08-31'),
            'uncovered'  => [],
        ]);

        $this->assertStringContainsString('Alice', $html);

        // UX wording pass (2026-07): natural business wording, not technical jargon.
        $this->assertStringContainsString("Missions en attente d'affectation", $html);
        $this->assertStringContainsString('sans instrumentiste affectée', $html);
        $this->assertStringNotContainsString('Créneaux non couverts', $html, 'Old title must no longer appear.');
        $this->assertStringNotContainsString('créneau(x)', $html, 'Old technical wording must no longer appear.');
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
}
