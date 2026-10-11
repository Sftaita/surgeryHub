<?php

namespace App\Tests\Integration\Notification;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Message\SendTemplatedEmailMessage;
use App\MessageHandler\SendTemplatedEmailMessageHandler;
use App\Notification\EmailContent;
use App\Notification\HtmlToTextRenderer;
use App\Service\AbsenceCommunicationJournalService;
use App\Service\NotificationService;
use App\Service\OutboundNotificationService;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * H5a (§20) — every email of a flow migrated in H5a, rendered through the REAL templates and
 * the REAL send handler: a multipart HTML + text email whose text/plain part is human
 * readable (no CSS rule, no tag) and says what the HTML says.
 */
final class H5aPlainTextEmailTest extends KernelTestCase
{
    /** @return iterable<string, array{0: callable(NotificationService, array): EmailContent, 1: list<string>}> */
    public static function emails(): iterable
    {
        yield 'encoding reminder' => [fn (NotificationService $n, array $f) => $n->missionEncodingReminderEmail($f['mission'], $f['instr']), ['Jane', '/app/i/missions/']];
        yield 'mission open (surgeon)' => [fn (NotificationService $n, array $f) => $n->missionOpenSurgeonEmail($f['mission'], $f['surgeon']), ['12/10/2026', 'CHU Delta']];
        yield 'mission offered' => [fn (NotificationService $n, array $f) => $n->missionOfferedEmail($f['mission'], $f['instr']), ['12/10/2026', 'CHU Delta']];
        yield 'offer declined' => [fn (NotificationService $n, array $f) => $n->missionOfferDeclinedEmail($f['mission'], $f['manager'], 'Jane Doe', 'Indisponible'), ['Jane Doe', 'Indisponible']];
        yield 'catalogue resolved' => [fn (NotificationService $n, array $f) => $n->catalogueRequestResolvedEmail($f['mission'], $f['instr'], 'Pince X', 'matériel'), ['Pince X']];
        yield 'catalogue ignored' => [fn (NotificationService $n, array $f) => $n->catalogueRequestIgnoredEmail($f['mission'], $f['instr'], 'Pince X', 'matériel', CatalogueRequestIgnoreReason::cases()[0], 'Doublon'), ['Pince X', 'Doublon']];
        yield 'catalogue created' => [fn (NotificationService $n, array $f) => $n->catalogueRequestCreatedEmail($f['mission'], $f['manager'], 7, 'Pince X', 'matériel', CatalogueRequestKind::MATERIAL_ITEM), ['Pince X', 'requestId=7']];
        yield 'surgeon request accepted' => [fn (NotificationService $n, array $f) => $n->surgeonMissionRequestAcceptedEmail($f['mission'], $f['surgeon']), ['CHU Delta']];
        yield 'surgeon request rejected' => [fn (NotificationService $n, array $f) => $n->surgeonMissionRequestRejectedEmail($f['surgeon'], 'CHU Delta', new \DateTimeImmutable('2026-10-12 08:00'), 'Bloc fermé'), ['CHU Delta', 'Bloc fermé']];
        yield 'anomaly resolved' => [fn (NotificationService $n, array $f) => $n->encodingAnomalyResolvedEmail($f['mission'], $f['surgeon'], 'Corrigé par le manager'), ['Corrigé par le manager']];
        yield 'absence request missing' => [fn (NotificationService $n, array $f) => $n->absenceRequestMissingEmail($f['instr'], 'Merci de nous transmettre vos congés.'), ['Bonjour Jane', 'Merci de nous transmettre vos congés.']];
        yield 'absence encoded recap' => [fn (NotificationService $n, array $f) => $n->absenceEncodedRecapEmail($f['instr'], [$f['absence']], 'Voici vos congés.'), ['Bonjour Jane', '20/10/2026']];
        yield 'uncovered escalation (J-14)' => [fn (NotificationService $n, array $f) => new EmailContent('Instrumentiste toujours recherchée', 'emails/mission_uncovered_escalation.html.twig', ['recipientName' => 'Paul Martin', 'dateLabel' => '12/10/2026', 'siteName' => 'CHU Delta']), ['12/10/2026', 'CHU Delta']];
        yield 'planning alert' => [fn (NotificationService $n, array $f) => new EmailContent('Alerte planning', 'emails/planning_alert.html.twig', ['recipientName' => 'Max', 'alertType' => 'INSTRUMENTIST_DOUBLE_BOOKING', 'siteName' => 'CHU Delta', 'missionDate' => '2026-10-12']), ['CHU Delta']];
        yield 'deploy confirmation (manager)' => [fn (NotificationService $n, array $f) => new EmailContent('Déploiement confirmé', 'emails/planning_manager.html.twig', ['manager' => $f['manager'], 'periodFrom' => new \DateTimeImmutable('2026-11-01'), 'periodTo' => new \DateTimeImmutable('2026-11-30'), 'missionCount' => 12, 'assignedCount' => 10, 'openPoolCount' => 2]), ['Max', '12']];
        yield 'planning (instrumentist, resend)' => [fn (NotificationService $n, array $f) => new EmailContent('Planning', 'emails/planning_instrumentist.html.twig', ['instrumentist' => $f['instr'], 'periodFrom' => new \DateTimeImmutable('2026-11-01'), 'periodTo' => new \DateTimeImmutable('2026-11-30'), 'missionCount' => 4, 'availableMissions' => [], 'availableMissionsUrl' => null]), ['Jane']];
        yield 'planning (surgeon, resend)' => [fn (NotificationService $n, array $f) => new EmailContent('Planning', 'emails/planning_surgeon.html.twig', ['surgeon' => $f['surgeon'], 'periodFrom' => new \DateTimeImmutable('2026-11-01'), 'periodTo' => new \DateTimeImmutable('2026-11-30'), 'totalCount' => 5, 'coveredCount' => 4, 'uncoveredCount' => 1]), ['Paul']];
    }

    /** @dataProvider emails */
    #[\PHPUnit\Framework\Attributes\DataProvider('emails')]
    public function test_the_text_part_is_readable_and_equivalent_to_the_html(callable $build, array $mustSay): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $content = $build($c->get(NotificationService::class), $this->fixtures());

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sent) { $sent = $email; });
        $handler = new SendTemplatedEmailMessageHandler(
            $mailer, $c->get(Environment::class), new NullLogger(),
            $this->createMock(OutboundNotificationService::class), $this->createMock(AbsenceCommunicationJournalService::class),
            $c->get(\App\Notification\DocumentStore::class), new HtmlToTextRenderer(),
        );
        $handler(new SendTemplatedEmailMessage('x@test.local', $content->subject, 'no-reply@surgicalhub.test', 'SurgicalHub',
            $content->htmlTemplate, $content->context, $content->textTemplate));

        self::assertInstanceOf(Email::class, $sent);
        $html = (string) $sent->getHtmlBody();
        $text = (string) $sent->getTextBody();
        self::assertNotSame('', $html, 'multipart: an HTML part');
        self::assertNotSame('', $text, 'multipart: a text part');
        self::assertDoesNotMatchRegularExpression('/[{}]/', $text, 'no CSS block in text/plain');
        self::assertDoesNotMatchRegularExpression('/(^|[\s;])[a-z-]+\s*:\s*[^;\n]+;/m', $text, 'no CSS declaration in text/plain');
        self::assertDoesNotMatchRegularExpression('/<\/?[a-z][^>]*>/i', $text, 'no HTML tag in text/plain');
        self::assertStringNotContainsString('motif privé', $html . $text, 'the free-text absence reason never leaves the Absence (D-130 A.5)');
        foreach ($mustSay as $fragment) {
            self::assertStringContainsString($fragment, $html, "the HTML says « $fragment »");
            self::assertStringContainsString($fragment, $text, "the text part says « $fragment » too");
        }
    }

    /** @return array<string, object> */
    private function fixtures(): array
    {
        $site = (new Hospital())->setName('CHU Delta');
        $surgeon = (new User())->setEmail('dr@test.local')->setFirstname('Paul')->setLastname('Martin')->setRoles(['ROLE_SURGEON']);
        $instr = (new User())->setEmail('jane@test.local')->setFirstname('Jane')->setLastname('Doe')->setRoles(['ROLE_INSTRUMENTIST']);
        $manager = (new User())->setEmail('max@test.local')->setFirstname('Max')->setLastname('Manager')->setRoles(['ROLE_MANAGER']);
        $mission = (new Mission())->setStatus(MissionStatus::OPEN)->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)->setInstrumentist($instr)
            ->setStartAt(new \DateTimeImmutable('2026-10-12 08:00'))->setEndAt(new \DateTimeImmutable('2026-10-12 12:00'));
        (new \ReflectionProperty($mission, 'id'))->setValue($mission, 4242);
        $absence = (new Absence())->setUser($instr)->setDateStart(new \DateTimeImmutable('2026-10-20'))->setDateEnd(new \DateTimeImmutable('2026-10-22'))->setReason('motif privé');

        return compact('site', 'surgeon', 'instr', 'manager', 'mission', 'absence');
    }
}
