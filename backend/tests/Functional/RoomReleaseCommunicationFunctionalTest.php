<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\SiteMembership;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\SendTemplatedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Communication des absences chirurgiens — Lot A (D-114). Couverture réelle HTTP + DB de
 * RoomReleaseCommunicationService : « Libération de salle » aux chirurgiens collègues du
 * même site quand un chirurgien encode/allonge une absence, calculée à partir du planning
 * habituel Planning V2 (SurgeonSchedulePost BLOCK), jamais pour les CONSULTATION.
 */
final class RoomReleaseCommunicationFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'RoomReleaseTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'posts' => [], 'sites' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['sites'] as $siteId) {
                $site = $this->em->find(Hospital::class, $siteId);
                if ($site === null) { continue; }

                foreach ($this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $comm) {
                    foreach ($this->em->createQueryBuilder()->select('d')->from(SurgeonAbsenceCommunicationDelivery::class, 'd')->where('d.communication = :c')->setParameter('c', $comm)->getQuery()->getResult() as $delivery) {
                        $this->em->remove($delivery);
                    }
                }
                $this->em->flush();
                foreach ($this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $comm) {
                    $this->em->remove($comm);
                }
                $this->em->flush();

                foreach ($this->em->createQueryBuilder()->select('cfg')->from(AbsenceCommunicationSiteConfig::class, 'cfg')->where('cfg.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $cfg) {
                    $this->em->remove($cfg);
                }
                $this->em->flush();

                foreach ($this->em->createQueryBuilder()->select('sm')->from(SiteMembership::class, 'sm')->where('sm.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $sm) {
                    $this->em->remove($sm);
                }
                $this->em->flush();
            }

            foreach ($this->createdIds['absences'] as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdIds['posts'] as $id) {
                $e = $this->em->find(SurgeonSchedulePost::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('roomrelease-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role, string $firstname = 'Test', bool $active = true): User
    {
        $u = new User();
        $u->setEmail('roomrelease-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname('User');
        $u->setActive($active);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('RoomRelease Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function affiliate(User $user, Hospital $site, string $siteRole = 'SURGEON'): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole($siteRole);
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function enableColleagues(Hospital $site): void
    {
        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyColleaguesEnabled(true);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function makePost(
        User $surgeon,
        Hospital $site,
        MissionType $type,
        RecurrenceFrequency $frequency,
        array $weekdays,
        \DateTimeImmutable $anchorDate,
        string $startDate,
        int $interval = 1,
    ): SurgeonSchedulePost {
        $rule = new RecurrenceRule();
        $rule->setFrequency($frequency);
        $rule->setInterval($interval);
        $rule->setWeekdays($weekdays);
        $rule->setAnchorDate($anchorDate);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType($type);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate(new \DateTimeImmutable($startDate));
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();
        return $post;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function communicationsFor(Hospital $site): array
    {
        return $this->em->createQueryBuilder()
            ->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.site = :s')->setParameter('s', $site)
            ->orderBy('c.revisionNumber', 'ASC')
            ->getQuery()->getResult();
    }

    private function deliveriesFor(SurgeonAbsenceCommunication $comm): array
    {
        return $this->em->createQueryBuilder()
            ->select('d')->from(SurgeonAbsenceCommunicationDelivery::class, 'd')
            ->where('d.communication = :c')->setParameter('c', $comm)
            ->getQuery()->getResult();
    }

    // ── Cas nominal : collègue reçoit un email individuel, journal correct ──────

    #[WithoutErrorHandler]
    public function test_colleague_surgeon_receives_individual_email_and_journal_is_recorded(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon  = $this->makeUser('ROLE_SURGEON', 'Absent');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        $post = $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(),
            'dateStart' => $today->format('Y-m-d'),
            'dateEnd' => $today->modify('+7 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms);
        self::assertSame(AbsenceCommunicationType::ROOM_RELEASE, $comms[0]->getType());
        self::assertSame(0, $comms[0]->getRevisionNumber());
        self::assertCount(2, $comms[0]->getOccurrencesSnapshot(), 'today + today+7 both weekly matches');

        $deliveries = $this->deliveriesFor($comms[0]);
        self::assertCount(1, $deliveries);
        self::assertSame($colleague->getEmail(), $deliveries[0]->getRecipientEmailSnapshot());
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $deliveries[0]->getStatus(), 'not SENT until the handler confirms delivery');

        $emails = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof SendTemplatedEmailMessage
                && $envelope->getMessage()->htmlTemplate === 'emails/absence_room_release.html.twig',
        ));
        self::assertCount(1, $emails, 'exactly one individual email dispatched, never a grouped send');
        self::assertSame($colleague->getEmail(), $emails[0]->getMessage()->to);
        self::assertSame($deliveries[0]->getId(), $emails[0]->getMessage()->absenceCommunicationDeliveryId);
    }

    // ── Revue post-déploiement : nom du chirurgien dans le corps, objet inchangé,
    //    jamais l'intervalle de congé exposé ─────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_body_includes_surgeon_name_subject_unchanged_and_absence_interval_never_exposed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Samy');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $dateStart = $today;
        $dateEnd = $today->modify('+10 days');
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $dateStart->format('Y-m-d'), 'dateEnd' => $dateEnd->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms);

        self::assertSame(sprintf('Libération de salle — %s', $site->getName()), $comms[0]->getSubjectSnapshot(), 'subject stays site-only, the surgeon name is never in the subject');
        self::assertStringContainsString('Dr Samy User', $comms[0]->getBodySnapshot(), 'the recipient must immediately know which surgeon releases the slots');

        // Room Release stays centered on the released slots, never on the leave itself: the
        // absence's own dateStart/dateEnd (its full interval, in d/m/Y form) must never appear
        // in the body — only the individual BLOCK occurrence dates (rendered as d/m, no year)
        // are ever shown.
        self::assertStringNotContainsString($dateStart->format('d/m/Y'), $comms[0]->getBodySnapshot());
        self::assertStringNotContainsString($dateEnd->format('d/m/Y'), $comms[0]->getBodySnapshot());
        self::assertStringNotContainsStringIgnoringCase('absent', $comms[0]->getBodySnapshot(), 'never phrase it as "Dr X est absent du ... au ..." — stay centered on the room release');
    }

    // ── Revue post-déploiement : snapshot figé même si le profil change ensuite ─

    #[WithoutErrorHandler]
    public function test_body_snapshot_keeps_original_surgeon_name_after_profile_change(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Samy');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);
        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $communicationId = $this->communicationsFor($site)[0]->getId();
        self::assertStringContainsString('Dr Samy User', $this->em->find(SurgeonAbsenceCommunication::class, $communicationId)->getBodySnapshot());

        // The surgeon later changes their profile name — the already-journaled email content
        // must never be silently rewritten to reflect the new name.
        $surgeon = $this->em->find(User::class, $surgeon->getId());
        $surgeon->setFirstname('Renamed');
        $this->em->flush();
        $this->em->clear();

        $comm = $this->em->find(SurgeonAbsenceCommunication::class, $communicationId);
        self::assertStringContainsString('Dr Samy User', $comm->getBodySnapshot(), 'the historical journal keeps the exact body as sent, with the old name');
        self::assertStringNotContainsString('Renamed', $comm->getBodySnapshot());
    }

    // ── Exclusions : chirurgien absent, autre site, inactif ─────────────────────

    #[WithoutErrorHandler]
    public function test_absent_surgeon_other_site_and_inactive_surgeon_are_all_excluded(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Absent');
        $otherSiteColleague = $this->makeUser('ROLE_SURGEON', 'OtherSite');
        $inactiveColleague  = $this->makeUser('ROLE_SURGEON', 'Inactive', active: false);

        $site = $this->makeSite();
        $otherSite = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($otherSiteColleague, $otherSite);
        $this->affiliate($inactiveColleague, $site);
        $this->enableColleagues($site);
        $this->enableColleagues($otherSite);

        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms, 'still journaled even with zero eligible colleagues');
        self::assertCount(0, $this->deliveriesFor($comms[0]), 'the absent surgeon, the other-site colleague and the inactive colleague are all excluded');

        $otherSite = $this->em->find(Hospital::class, $otherSite->getId());
        self::assertCount(0, $this->communicationsFor($otherSite), 'no BLOCK occurrence concerns this site — never notified');
    }

    // ── Site désactivé ───────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_site_with_feature_disabled_gets_no_email_and_no_journal_entry(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        // Note: enableColleagues() volontairement PAS appelé — site sans réglage = désactivé par défaut.

        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->communicationsFor($site), 'feature OFF for this site — no journal entry at all');
    }

    // ── BLOCK uniquement, jamais CONSULTATION ───────────────────────────────────

    #[WithoutErrorHandler]
    public function test_consultation_type_post_is_never_announced(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        $this->makePost($surgeon, $site, MissionType::CONSULTATION, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->communicationsFor($site), 'CONSULTATION never triggers a room-release communication');
    }

    // ── Occurrence déjà passée jamais annoncée ──────────────────────────────────

    #[WithoutErrorHandler]
    public function test_past_occurrence_is_never_announced(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        // Entirely in the past — a weekly Friday post anchored 2020-01-03 (a Friday).
        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2020-01-03'), '2020-01-01');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2020-01-01', 'dateEnd' => '2020-01-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->communicationsFor($site), 'never announce an already-past BLOCK, even if the recurrence matched');
    }

    // ── Instrumentiste absent → no-op total ─────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_instrumentist_absence_never_triggers_room_release(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $this->enableColleagues($site);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->communicationsFor($site));
    }

    // ── Allongement : complément uniquement pour les nouvelles occurrences ──────

    #[WithoutErrorHandler]
    public function test_extending_the_absence_sends_a_complement_with_only_new_occurrences(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        // Weekly post on today's weekday — occurrences at +0, +7, +14, +21 days.
        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+7 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms);
        self::assertCount(2, $comms[0]->getOccurrencesSnapshot(), 'initial send: +0 and +7');

        // A no-op update (same dates) must never create a new revision.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+7 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->communicationsFor($site), 'no-op update must never duplicate the communication');

        // Extend to +21 days — reveals two brand-new occurrences (+14, +21), never re-announces +0/+7.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+21 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(2, $comms, 'exactly one complement created for the newly revealed occurrences');
        self::assertSame(0, $comms[0]->getRevisionNumber());
        self::assertSame(1, $comms[1]->getRevisionNumber());
        self::assertCount(2, $comms[1]->getOccurrencesSnapshot(), 'the complement lists only the 2 new occurrences, never the 2 already announced');

        $originalDates = array_column($comms[0]->getOccurrencesSnapshot(), 'date');
        $complementDates = array_column($comms[1]->getOccurrencesSnapshot(), 'date');
        self::assertEmpty(array_intersect($originalDates, $complementDates), 'no date is ever announced twice');
    }

    // ── Raccourcissement : jamais de correction/rétractation ────────────────────

    #[WithoutErrorHandler]
    public function test_shortening_the_absence_never_sends_any_correction(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+14 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->communicationsFor($site));

        // Shorten back to a single day — must never create a second row (no correction).
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->communicationsFor($site), 'shortening must never trigger a correction/retraction');
    }

    // ── Self-service (le chemin le plus réaliste) ───────────────────────────────

    #[WithoutErrorHandler]
    public function test_self_service_surgeon_absence_also_triggers_room_release(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms);
        self::assertCount(1, $this->deliveriesFor($comms[0]));
    }

    // ── Revue finale §2 : mélange occurrences passées/futures à la création ────

    #[WithoutErrorHandler]
    public function test_mixed_past_and_future_occurrences_only_announces_the_future_ones(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        // Weekly post anchored today, active well before today so the recurrence already
        // produced several past occurrences. Absence window: today-35 .. today+14, i.e.
        // exactly the "01/09..30/09 avec aujourd'hui = 10/09" shape from the review request,
        // expressed relative to "today" so the test is stable regardless of when it runs.
        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->modify('-35 days')->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(),
            'dateStart' => $today->modify('-35 days')->format('Y-m-d'),
            'dateEnd' => $today->modify('+14 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms);

        // Theoretical weekly occurrences across the window: -35,-28,-21,-14,-7,0,+7,+14 (8
        // total). Only today, +7 and +14 (3) are still future — never the 5 past ones.
        $dates = array_column($comms[0]->getOccurrencesSnapshot(), 'date');
        sort($dates);
        self::assertSame([
            $today->format('Y-m-d'),
            $today->modify('+7 days')->format('Y-m-d'),
            $today->modify('+14 days')->format('Y-m-d'),
        ], $dates, 'only future occurrences are ever announced, past ones are silently excluded');
    }

    // ── Revue finale §3 : le post est modifié après le premier envoi ───────────

    #[WithoutErrorHandler]
    public function test_modifying_the_same_post_recurrence_after_initial_send_never_reannounces_already_sent_dates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        $post = $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+7 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->communicationsFor($site), 'initial send recorded');

        // A manager edits the SAME post afterwards — e.g. its period changes. The post's id
        // is unchanged, only a field on it is. This must never be reinterpreted as "new"
        // occurrences on the next reaction for this absence.
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $post->setPeriod(ShiftPeriod::JOURNEE);
        $this->em->flush();
        $this->em->clear();

        // A no-op update on the absence re-runs the reaction pipeline against the modified post.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+7 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms, 'editing period on the same post (same postId) must never trigger a re-announcement/complement');

        // The original snapshot keeps the period as it was AT SEND TIME (MATIN) — an
        // immutable audit record, never silently rewritten to reflect the post's current value.
        self::assertSame('MATIN', $comms[0]->getOccurrencesSnapshot()[0]['period']);
    }

    // ── Revue finale §4 : suppression de l'absence, FK à NULL, journal exploitable ──

    #[WithoutErrorHandler]
    public function test_deleting_the_absence_leaves_the_communication_fully_readable_via_snapshots(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);
        $this->makePost($surgeon, $site, MissionType::BLOCK, RecurrenceFrequency::WEEKLY, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->communicationsFor($site);
        self::assertCount(1, $comms);
        $communicationId = $comms[0]->getId();
        $deliveryId = $this->deliveriesFor($comms[0])[0]->getId();

        // Note: the id is removed from $this->createdIds['absences'] on purpose — DELETE
        // below removes it for real, no double-remove needed in tearDown.
        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(Absence::class, $absenceId), 'the Absence row is truly gone');

        $comm = $this->em->find(SurgeonAbsenceCommunication::class, $communicationId);
        self::assertNotNull($comm, 'the journal entry itself is never deleted');
        self::assertNull($comm->getAbsence(), 'FK set to NULL, per ON DELETE SET NULL');
        // Nothing about reading this row assumes getAbsence() is non-null — every fact needed
        // to understand what was sent survives via the entity's own relations/snapshots.
        self::assertNotNull($comm->getSurgeon(), 'surgeon relation is independent of the Absence FK');
        self::assertSame($surgeon->getId(), $comm->getSurgeon()->getId());
        self::assertNotNull($comm->getSite());
        self::assertSame($site->getId(), $comm->getSite()->getId());
        self::assertSame($today->format('Y-m-d'), $comm->getAbsenceDateStartSnapshot()->format('Y-m-d'));
        self::assertSame($today->format('Y-m-d'), $comm->getAbsenceDateEndSnapshot()->format('Y-m-d'));
        self::assertNotEmpty($comm->getOccurrencesSnapshot());
        self::assertNotEmpty($comm->getSubjectSnapshot());
        self::assertNotEmpty($comm->getBodySnapshot());

        $delivery = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $deliveryId);
        self::assertNotNull($delivery, 'deliveries survive the absence deletion too');
        self::assertSame($colleague->getEmail(), $delivery->getRecipientEmailSnapshot());

        // Re-querying the journal by site (the only realistic manager-facing lookup path
        // today) must never error out on the now-null absence relation.
        self::assertCount(1, $this->communicationsFor($site), 'journal remains queryable after the FK is nulled');
    }
}
