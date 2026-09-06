<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Communication des absences chirurgiens — Lot C (D-114). Journal manager en lecture (§15-
 * §23) : RBAC, pagination, filtres, détail, deliveries, absence supprimée (§20).
 */
final class AbsenceCommunicationJournalControllerTest extends WebTestCase
{
    private const PASSWORD = 'JournalTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'sites' => [], 'communications' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['communications'] as $id) {
                $c = $this->em->find(SurgeonAbsenceCommunication::class, $id);
                if ($c !== null) {
                    foreach ($c->getDeliveries() as $d) { $this->em->remove($d); }
                    $this->em->flush();
                    $this->em->remove($c);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['absences'] as $id) {
                $e = $this->em->find(Absence::class, $id);
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

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('journalctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function makeUser(string $role = 'ROLE_SURGEON', string $firstname = 'Jean', string $lastname = 'Dupont'): User
    {
        $u = new User();
        $u->setEmail('journalctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(string $name = null): Hospital
    {
        $h = new Hospital();
        $h->setName($name ?? ('Journal Site ' . bin2hex(random_bytes(3))));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeAbsence(User $user): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable('2026-09-10'));
        $a->setDateEnd(new \DateTimeImmutable('2026-09-15'));
        $a->setCreatedBy($user);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    /**
     * @param list<AbsenceCommunicationStatus> $deliveryStatuses une delivery par statut fourni
     */
    private function makeCommunication(
        ?Absence $absence,
        User $surgeon,
        Hospital $site,
        AbsenceCommunicationType $type,
        array $deliveryStatuses,
        int $revision = 0,
    ): SurgeonAbsenceCommunication {
        $c = new SurgeonAbsenceCommunication();
        $c->setAbsence($absence);
        $c->setSurgeon($surgeon);
        $c->setSite($site);
        $c->setType($type);
        $c->setRevisionNumber($revision);
        $c->setSubjectSnapshot('Sujet test');
        $c->setBodySnapshot('Corps test');
        $c->setOccurrencesSnapshot([]);
        $c->setAbsenceDateStartSnapshot(new \DateTimeImmutable('2026-09-10'));
        $c->setAbsenceDateEndSnapshot(new \DateTimeImmutable('2026-09-15'));
        $this->em->persist($c);

        foreach ($deliveryStatuses as $status) {
            $d = new SurgeonAbsenceCommunicationDelivery();
            $d->setStatus($status);
            $d->setRecipientEmailSnapshot('to@example.com');
            $d->setRecipientCcSnapshot([]);
            if ($status === AbsenceCommunicationStatus::SENT) { $d->setSentAt(new \DateTimeImmutable()); }
            if ($status === AbsenceCommunicationStatus::FAILED) { $d->setLastError('boom'); }
            if ($status === AbsenceCommunicationStatus::CANCELLED) { $d->setCancelledAt(new \DateTimeImmutable()); }
            $c->addDelivery($d);
            $this->em->persist($d);
        }
        $this->em->flush();
        $this->createdIds['communications'][] = $c->getId();
        return $c;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── RBAC ─────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden_from_list(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/planning/absence-communications', server: $this->auth($token));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden_from_detail(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/planning/absence-communications/1', server: $this->auth($token));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    // ── Liste, pagination ────────────────────────────────────────────────────

    public function test_list_returns_paginated_shape(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);

        $client->request('GET', '/api/planning/absence-communications?limit=5&page=1', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());

        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('page', $data);
        self::assertArrayHasKey('limit', $data);
        self::assertArrayHasKey('total', $data);
        self::assertSame(1, $data['page']);
        self::assertSame(5, $data['limit']);
        self::assertGreaterThanOrEqual(1, $data['total']);
    }

    public function test_list_respects_limit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        for ($i = 0; $i < 3; $i++) {
            $absence = $this->makeAbsence($surgeon);
            $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);
        }

        $client->request('GET', '/api/planning/absence-communications?limit=2&page=1&siteId=' . $site->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());

        self::assertCount(2, $data['items']);
        self::assertSame(3, $data['total']);
    }

    // ── Filtres ──────────────────────────────────────────────────────────────

    public function test_list_filters_by_site(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $siteA = $this->makeSite('Site Filtre A');
        $siteB = $this->makeSite('Site Filtre B');
        $absence = $this->makeAbsence($surgeon);
        $this->makeCommunication($absence, $surgeon, $siteA, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);
        $this->makeCommunication($absence, $surgeon, $siteB, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);

        $client->request('GET', '/api/planning/absence-communications?siteId=' . $siteA->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());

        foreach ($data['items'] as $item) {
            self::assertSame($siteA->getId(), $item['site']['id']);
        }
        self::assertGreaterThanOrEqual(1, count($data['items']));
    }

    public function test_list_filters_by_surgeon(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeonA = $this->makeUser('ROLE_SURGEON', 'A', 'Surgeon');
        $surgeonB = $this->makeUser('ROLE_SURGEON', 'B', 'Surgeon');
        $site = $this->makeSite();
        $absenceA = $this->makeAbsence($surgeonA);
        $absenceB = $this->makeAbsence($surgeonB);
        $this->makeCommunication($absenceA, $surgeonA, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);
        $this->makeCommunication($absenceB, $surgeonB, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);

        $client->request('GET', '/api/planning/absence-communications?surgeonId=' . $surgeonA->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());

        foreach ($data['items'] as $item) {
            self::assertSame($surgeonA->getId(), $item['surgeon']['id']);
        }
    }

    public function test_list_filters_by_type(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);
        $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE, [AbsenceCommunicationStatus::SENT]);

        $client->request('GET', '/api/planning/absence-communications?type=BLOCK_MANAGEMENT_ABSENCE&siteId=' . $site->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());

        self::assertCount(1, $data['items']);
        self::assertSame('BLOCK_MANAGEMENT_ABSENCE', $data['items'][0]['type']);
    }

    // ── Statut global (§19) ──────────────────────────────────────────────────

    public function test_list_filters_by_global_status_scheduled_when_any_delivery_pending(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        // Deux deliveries : une SENT, une SCHEDULED — le statut global doit rester SCHEDULED
        // (envoi encore en cours), jamais SENT (contradiction interdite, §19).
        $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [
            AbsenceCommunicationStatus::SENT, AbsenceCommunicationStatus::SCHEDULED,
        ]);

        $client->request('GET', '/api/planning/absence-communications?status=SCHEDULED&siteId=' . $site->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertCount(1, $data['items']);
        self::assertSame('SCHEDULED', $data['items'][0]['globalStatus']);

        $client->request('GET', '/api/planning/absence-communications?status=SENT&siteId=' . $site->getId(), server: $this->auth($token));
        self::assertCount(0, $this->json($client->getResponse())['items'], 'jamais classé SENT tant qu\'une delivery reste SCHEDULED');
    }

    public function test_list_filters_by_global_status_failed_when_no_scheduled_but_one_failed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [
            AbsenceCommunicationStatus::SENT, AbsenceCommunicationStatus::FAILED,
        ]);

        $client->request('GET', '/api/planning/absence-communications?status=FAILED&siteId=' . $site->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertCount(1, $data['items']);
        self::assertSame(1, $data['items'][0]['failedCount']);
        self::assertSame(1, $data['items'][0]['sentCount']);
    }

    public function test_list_filters_by_global_status_cancelled_only_when_nothing_else_pending_or_failed_or_sent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        // Deux deliveries CANCELLED, aucune SCHEDULED/FAILED/SENT — dernier palier de la
        // priorité (§19) : SCHEDULED > FAILED > SENT > CANCELLED.
        $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [
            AbsenceCommunicationStatus::CANCELLED, AbsenceCommunicationStatus::CANCELLED,
        ]);

        $client->request('GET', '/api/planning/absence-communications?status=CANCELLED&siteId=' . $site->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertCount(1, $data['items']);
        self::assertSame('CANCELLED', $data['items'][0]['globalStatus']);
        self::assertSame(2, $data['items'][0]['cancelledCount']);

        foreach (['SCHEDULED', 'FAILED', 'SENT'] as $otherStatus) {
            $client->request('GET', '/api/planning/absence-communications?status=' . $otherStatus . '&siteId=' . $site->getId(), server: $this->auth($token));
            self::assertCount(0, $this->json($client->getResponse())['items'], "jamais classé $otherStatus quand toutes les deliveries sont CANCELLED");
        }
    }

    // ── Pagination stable (§18) ──────────────────────────────────────────────

    /**
     * Revue finale Lot C (§18) — deux communications créées à la même microseconde (rattrapage
     * créant plusieurs lignes très rapprochées) ne doivent jamais changer d'ordre relatif d'une
     * requête à l'autre : `id DESC` en tie-breaker après `createdAt DESC` rend la pagination
     * déterministe même quand `createdAt` est strictement identique en base.
     */
    public function test_list_pagination_is_stable_when_two_communications_share_the_exact_same_created_at(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $first = $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);
        $second = $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT], revision: 1);

        $sameInstant = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $this->em->getConnection()->executeStatement(
            'UPDATE surgeon_absence_communication SET created_at = ? WHERE id IN (?, ?)',
            [$sameInstant, $first->getId(), $second->getId()],
        );

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $client->request('GET', '/api/planning/absence-communications?limit=2&page=1&siteId=' . $site->getId(), server: $this->auth($token));
            $data = $this->json($client->getResponse());
            $ids[] = array_column($data['items'], 'id');
        }

        self::assertSame($ids[0], $ids[1], 'même ordre à chaque requête malgré un createdAt identique');
        self::assertSame($ids[1], $ids[2]);
        self::assertSame([$second->getId(), $first->getId()], $ids[0], 'à createdAt égal, id DESC départage — la révision la plus récente en premier');
    }

    // ── Détail + deliveries ──────────────────────────────────────────────────

    public function test_detail_returns_subject_body_and_deliveries(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Etienne', 'Willemart');
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $comm = $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE, [AbsenceCommunicationStatus::SENT]);

        $client->request('GET', "/api/planning/absence-communications/{$comm->getId()}", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());

        self::assertSame('Sujet test', $data['subject']);
        self::assertSame('Corps test', $data['body']);
        self::assertSame($surgeon->getEmail(), $data['replyTo'], 'Reply-To présent pour la gestion du bloc');
        self::assertCount(1, $data['deliveries']);
        self::assertSame('to@example.com', $data['deliveries'][0]['to']);
        self::assertSame('SENT', $data['deliveries'][0]['status']);
    }

    public function test_detail_room_release_has_no_reply_to(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $comm = $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::ROOM_RELEASE, [AbsenceCommunicationStatus::SENT]);

        $client->request('GET', "/api/planning/absence-communications/{$comm->getId()}", server: $this->auth($token));
        $data = $this->json($client->getResponse());

        self::assertNull($data['replyTo'], 'ROOM_RELEASE n\'a jamais de Reply-To');
    }

    public function test_detail_unknown_id_returns_404(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('GET', '/api/planning/absence-communications/999999999', server: $this->auth($token));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ── §20 : absence supprimée, journal reste exploitable ──────────────────

    public function test_detail_remains_fully_readable_after_absence_deletion(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Nadia', 'Kova');
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $comm = $this->makeCommunication($absence, $surgeon, $site, AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE, [AbsenceCommunicationStatus::SENT]);
        $absenceId = $absence->getId();

        // Suppression réelle de l'absence (FK ON DELETE SET NULL, Lot A) — retirée de
        // createdIds pour ne pas être supprimée deux fois en tearDown.
        $this->createdIds['absences'] = array_values(array_filter($this->createdIds['absences'], fn ($id) => $id !== $absenceId));
        $toDelete = $this->em->find(Absence::class, $absenceId);
        $this->em->remove($toDelete);
        $this->em->flush();
        $this->em->clear();

        $client->request('GET', "/api/planning/absence-communications/{$comm->getId()}", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());

        self::assertNull($data['absenceId'], 'FK nullée, jamais une erreur');
        self::assertSame($surgeon->getId(), $data['surgeon']['id']);
        self::assertSame($site->getId(), $data['site']['id']);
        self::assertSame('2026-09-10', $data['absenceDateStart']);
        self::assertSame('2026-09-15', $data['absenceDateEnd']);
        self::assertSame('Sujet test', $data['subject']);
        // Revue finale (§7) — tout ce qui compose l'affichage du drawer manager vient des
        // snapshots portés directement par la communication (subject/body/occurrences/
        // replyTo/deliveries.to/cc), jamais de `communication.absence` — reste donc
        // entièrement lisible même après suppression de l'absence d'origine.
        self::assertSame('Corps test', $data['body']);
        self::assertSame([], $data['occurrences']);
        self::assertSame($surgeon->getEmail(), $data['replyTo'], 'BLOCK_MANAGEMENT_ABSENCE a un Reply-To (email du chirurgien)');
        self::assertCount(1, $data['deliveries']);
        self::assertSame('to@example.com', $data['deliveries'][0]['to']);
        self::assertSame([], $data['deliveries'][0]['cc']);

        // La liste (avec filtre par site, pour ne pas dépendre du contenu global de la base)
        // reste également exploitable.
        $client->request('GET', '/api/planning/absence-communications?siteId=' . $site->getId(), server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
