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
        ]);

        $this->assertStringContainsString('Carla', $html);
        $this->assertStringContainsString('01/08/2026', $html);
    }

    public function test_planning_surgeon_email_renders(): void
    {
        $html = $this->twig()->render('emails/planning_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-08-01'),
            'periodTo'   => new \DateTimeImmutable('2026-08-31'),
            'hasGlobal'  => true,
        ]);

        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('planning global', $html);
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

    public function test_planning_change_summary_surgeon_email_renders(): void
    {
        $html = $this->twig()->render('emails/planning_change_summary_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'periodFrom' => new \DateTimeImmutable('2026-08-01'),
            'periodTo'   => new \DateTimeImmutable('2026-08-31'),
            'uncovered'  => [],
        ]);

        $this->assertStringContainsString('Alice', $html);
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
