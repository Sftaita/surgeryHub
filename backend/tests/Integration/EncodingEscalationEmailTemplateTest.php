<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * D-144 — l'alerte J+4 aux managers n'évoque des relances que si elles ont réellement eu lieu
 * (aux premiers jours après l'activation, J+1/J+2 n'ont jamais été envoyés).
 */
final class EncodingEscalationEmailTemplateTest extends KernelTestCase
{
    private function render(array $previous): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('emails/encoding_escalation_manager.html.twig', [
            'firstname' => 'Marie', 'instrumentistName' => 'Diane D.', 'missionDate' => '08/10/2026',
            'surgeonName' => 'Dr X', 'situationLabel' => 'Encodage non commencé', 'missing' => ['Heure de début prestée'],
            'previousReminders' => $previous, 'trackingUrl' => 'https://app.test/app/m/billing/encodings?missionId=1',
        ]);
    }

    public function test_no_previous_reminder_is_never_claimed(): void
    {
        $html = $this->render([]);
        self::assertStringNotContainsString('malgré les rappels', $html);
        self::assertStringContainsString('quatre jours après la mission', $html);
    }

    public function test_previous_reminders_are_mentioned_when_they_happened(): void
    {
        $html = $this->render(['J+1 le 09/10/2026', 'J+2 le 10/10/2026']);
        self::assertStringContainsString('malgré les rappels envoyés', $html);
        self::assertStringContainsString('J+1 le 09/10/2026', $html);
    }
}
