<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\PricingRuleType;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-155 — activité opératoire réellement exécutée sans instrumentiste, de bout en bout :
 * déclaration (chirurgien de la mission ou manager), encodage interventions / matériel sans
 * heures, soumission, renvoi en correction (le chirurgien est prévenu), validation, calcul
 * firme uniquement, facturation firme ; annulation auditée d'une déclaration erronée ;
 * missions classiques inchangées. Vrais endpoints, voters, services, journal.
 */
final class ActivityWithoutInstrumentistD155Test extends WebTestCase
{
    private const PASSWORD = 'SansInstru155!';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'rules' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $c = $this->em->getConnection();
            $missions = implode(',', array_map('intval', $this->created['missions'])) ?: '0';
            $users = implode(',', array_map('intval', $this->created['users'])) ?: '0';
            foreach (['notification_dispatch', 'notification_event', 'outbound_notification'] as $table) {
                $col = $table === 'notification_event' ? 'user_id' : 'recipient_user_id';
                $c->executeStatement("DELETE FROM $table WHERE $col IN ($users) OR mission_id IN ($missions)");
            }
            $c->executeStatement("DELETE FROM firm_invoice_line WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE fcl FROM financial_calculation_line fcl JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id WHERE fc.mission_id IN ($missions)");
            $c->executeStatement("UPDATE financial_calculation SET superseded_by_calculation_id = NULL WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM financial_calculation WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_encoding_comment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM material_line WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_intervention WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_execution WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            foreach ($this->created['rules'] as $id) { $c->executeStatement('DELETE FROM pricing_rule WHERE id = ?', [$id]); }
            foreach ($this->created['types'] as $id) { $c->executeStatement('DELETE FROM intervention_type WHERE id = ?', [$id]); }
            foreach ($this->created['firms'] as $id) { $c->executeStatement('DELETE FROM firm WHERE id = ?', [$id]); }
            $c->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($users)");
            $c->executeStatement("DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM user WHERE id IN ($users))");
            $c->executeStatement("DELETE FROM user WHERE id IN ($users)");
            foreach ($this->created['sites'] as $id) { $c->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]); }
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function user(string $role, string $firstname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())
            ->setEmail('d155-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])
            ->setActive(true)
            ->setFirstname($firstname)
            ->setLastname('D155');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    private function mission(User $surgeon, MissionStatus $status = MissionStatus::OPEN, string $start = '-1 day', ?User $instrumentist = null): Mission
    {
        $site = (new Hospital())->setName('D155-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        // Toujours une référence gérée par l'EntityManager courant (il a pu être vidé entre-temps).
        $surgeon = $this->em->getReference(User::class, $surgeon->getId());
        $instrumentist = $instrumentist !== null ? $this->em->getReference(User::class, $instrumentist->getId()) : null;
        $startAt = (new \DateTimeImmutable($start))->setTime(8, 0);
        $m = (new Mission())
            ->setType(MissionType::BLOCK)
            ->setSite($site)
            ->setSurgeon($surgeon)
            ->setCreatedBy($surgeon)
            ->setStartAt($startAt)
            ->setEndAt($startAt->modify('+4 hours'))
            ->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return $m;
    }

    /** @return array{InterventionType, Firm} prestation facturée 170 € à la firme */
    private function pricedCatalogue(): array
    {
        $firm = (new Firm())->setName('D155-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $type = (new InterventionType())->setCode('D155-' . bin2hex(random_bytes(3)))->setLabel('Arthroscopie D155')->setActive(true);
        $this->em->persist($type);
        $this->em->flush();
        $rule = (new PricingRule())->setFirm($firm)->setRuleType(PricingRuleType::INTERVENTION_FEE)->setInterventionType($type)->setUnitPrice('170.00');
        $this->em->persist($rule);
        $this->em->flush();
        $this->created['firms'][] = $firm->getId();
        $this->created['types'][] = $type->getId();
        $this->created['rules'][] = $rule->getId();

        return [$type, $firm];
    }

    private function send(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null): Response
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $body === null ? null : json_encode($body));
        return $client->getResponse();
    }

    private function detail(KernelBrowser $client, string $token, int $id): array
    {
        $response = $this->send($client, $token, 'GET', "/api/missions/$id");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }

    /** Messages de cycle de vie envoyés par la DERNIÈRE requête, traités aussitôt (transport vidé à la suivante). */
    private function handleLifecycleMessages(): void
    {
        $handler = static::getContainer()->get(MissionLifecycleChangedMessageHandler::class);
        foreach (static::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof MissionLifecycleChangedMessage) {
                $handler($envelope->getMessage());
            }
        }
    }

    private function dispatchesOf(NotificationType $type, int $missionId): array
    {
        return $this->em->getRepository(NotificationDispatch::class)->findBy(['mission' => $missionId, 'notificationType' => $type->value]);
    }

    private function executionRows(int $missionId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_execution WHERE mission_id = ?', [$missionId]);
    }

    // ── Parcours complet ──────────────────────────────────────────────────────

    public function test_surgeon_declares_encodes_and_submits_manager_corrects_validates_and_bills_the_firm_only(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Paul');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Diane');
        [$type, $firm] = $this->pricedCatalogue();
        $mission = $this->mission($surgeon);
        $id = (int) $mission->getId();
        $surgeonToken = $this->login($client, $surgeon);

        // Mission OPEN commencée, jamais couverte : déclarable, pas encore encodable.
        $before = $this->detail($client, $surgeonToken, $id);
        self::assertContains('declare_executed_without_instrumentist', $before['allowedActions']);
        self::assertNotContains('edit_encoding', $before['allowedActions']);

        $declared = $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/declare-executed-without-instrumentist");
        self::assertSame(Response::HTTP_OK, $declared->getStatusCode(), (string) $declared->getContent());
        $body = json_decode((string) $declared->getContent(), true);
        self::assertSame('ENCODING_IN_PROGRESS', $body['status']);
        self::assertSame('Paul D155', $body['executedWithoutInstrumentist']['declaredByName']);
        self::assertContains('edit_encoding', $body['allowedActions']);
        self::assertContains('submit', $body['allowedActions']);
        foreach (['edit_hours', 'declare_executed_without_instrumentist', 'claim'] as $never) {
            self::assertNotContains($never, $body['allowedActions'], $never);
        }

        // Sortie immédiate du pool : plus aucun claim.
        $claim = $this->send($client, $this->login($client, $instr), 'POST', "/api/missions/$id/claim");
        self::assertContains($claim->getStatusCode(), [Response::HTTP_FORBIDDEN, Response::HTTP_CONFLICT], (string) $claim->getContent());

        // Encodage par le chirurgien : intervention ; jamais d'heures.
        $surgeonToken = $this->login($client, $surgeon);
        $created = $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $hours = $this->send($client, $surgeonToken, 'PATCH', "/api/missions/$id/execution", [
            'actualStartAt' => $mission->getStartAt()->format(DATE_ATOM), 'actualEndAt' => $mission->getStartAt()->modify('+2 hours')->format(DATE_ATOM), 'hoursSource' => 'INSTRUMENTIST',
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $hours->getStatusCode());

        // Soumission sans heures (BLOCK sans matériel : description exigée, règle D-080 inchangée).
        $noComment = $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/complete", []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $noComment->getStatusCode(), (string) $noComment->getContent());
        $submitted = $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/complete", ['comment' => 'Arthroscopie diagnostique, aucun implant.']);
        self::assertSame(Response::HTTP_OK, $submitted->getStatusCode(), (string) $submitted->getContent());
        self::assertSame('SUBMITTED', json_decode((string) $submitted->getContent(), true)['status']);
        $this->handleLifecycleMessages();
        self::assertSame([], $this->dispatchesOf(NotificationType::ENCODING_SUBMITTED, $id), 'le chirurgien n\'est pas notifié de sa propre soumission');

        // Renvoi en correction par le manager : c'est le chirurgien qui est prévenu.
        $managerToken = $this->login($client, $manager);
        self::assertSame(Response::HTTP_OK, $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/reject", ['comment' => 'Précisez la firme du matériel.'])->getStatusCode());
        $this->handleLifecycleMessages();
        $correction = $this->dispatchesOf(NotificationType::ENCODING_CORRECTION_REQUESTED, $id);
        self::assertCount(1, $correction);
        self::assertSame($surgeon->getId(), $correction[0]->getRecipient()?->getId());

        // Re-soumission, puis validation sans heures.
        $surgeonToken = $this->login($client, $surgeon);
        self::assertSame(Response::HTTP_OK, $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/complete", ['comment' => 'Arthroscopie diagnostique, aucun implant.'])->getStatusCode());
        $managerToken = $this->login($client, $manager);
        $validated = $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/validate");
        self::assertSame(Response::HTTP_OK, $validated->getStatusCode(), (string) $validated->getContent());

        // Calcul : uniquement la ligne firme réellement applicable, aucune ligne instrumentiste.
        $calculated = $this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations");
        self::assertSame(Response::HTTP_CREATED, $calculated->getStatusCode(), (string) $calculated->getContent());
        $calc = json_decode((string) $calculated->getContent(), true);
        self::assertSame(['FIRM'], array_values(array_unique(array_column($calc['lines'], 'beneficiaryType'))));
        self::assertSame(['FIRM' => '170.00'], $calc['totalsByCurrency']['EUR']);
        self::assertSame(Response::HTTP_OK, $this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$calc['id']}/approve")->getStatusCode());

        // Facturation firme : la ligne est éligible comme pour une mission classique.
        $day = $mission->getStartAt();
        $eligible = $this->send($client, $managerToken, 'GET', sprintf('/api/firm-invoices/eligible-lines?firmId=%d&currency=EUR&periodStart=%s&periodEnd=%s',
            $firm->getId(), $day->modify('-1 day')->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d')));
        self::assertSame(Response::HTTP_OK, $eligible->getStatusCode());
        self::assertCount(1, json_decode((string) $eligible->getContent(), true)['lines']);

        // Aucune heure ni rémunération d'instrumentiste fictive, à aucun moment.
        self::assertSame(0, $this->executionRows($id));
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM financial_calculation_line l JOIN financial_calculation c ON c.id = l.financial_calculation_id WHERE c.mission_id = ? AND l.beneficiary_type <> ?',
            [$id, FinancialBeneficiaryType::FIRM->value],
        ));
        $audit = $this->em->getRepository(AuditEvent::class)->findOneBy(['mission' => $id, 'eventType' => AuditEventType::MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_DECLARED]);
        self::assertNotNull($audit);
        self::assertSame('SURGEON', $audit->getPayload()['declaredAs']);
        self::assertSame($surgeon->getId(), $audit->getActor()?->getId());
    }

    // ── Règles de déclaration ─────────────────────────────────────────────────

    public function test_only_the_missions_surgeon_or_a_manager_declares_a_started_uncovered_open_mission(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Paul');
        $colleague = $this->user('ROLE_SURGEON', 'Marc');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Diane');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');

        $open = $this->mission($surgeon);
        $future = $this->mission($surgeon, start: '+2 days');
        $assigned = $this->mission($surgeon, MissionStatus::ASSIGNED, '-1 day', $instr);
        $declare = fn (string $token, Mission $m) => $this->send($client, $token, 'POST', "/api/missions/{$m->getId()}/declare-executed-without-instrumentist")->getStatusCode();

        self::assertSame(Response::HTTP_FORBIDDEN, $declare($this->login($client, $colleague), $open), "mission d'un confrère");
        self::assertSame(Response::HTTP_FORBIDDEN, $declare($this->login($client, $instr), $open), 'instrumentiste');
        $surgeonToken = $this->login($client, $surgeon);
        self::assertSame(Response::HTTP_FORBIDDEN, $declare($surgeonToken, $future), 'mission à venir : reste disponible dans le pool');
        self::assertSame(Response::HTTP_FORBIDDEN, $declare($surgeonToken, $assigned), 'mission couverte par une instrumentiste');
        self::assertNotContains('declare_executed_without_instrumentist', $this->detail($client, $surgeonToken, (int) $future->getId())['allowedActions']);

        // Un manager peut déclarer ; une seconde déclaration est refusée.
        $managerToken = $this->login($client, $manager);
        self::assertSame(Response::HTTP_OK, $declare($managerToken, $open));
        self::assertSame(Response::HTTP_FORBIDDEN, $declare($managerToken, $open));
        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $open->getId());
        self::assertTrue($reloaded->isExecutedWithoutInstrumentist());
        self::assertSame($manager->getId(), $reloaded->getExecutedWithoutInstrumentistBy()?->getId());
        self::assertSame(MissionStatus::OPEN, $this->em->find(Mission::class, $future->getId())->getStatus(), 'mission à venir inchangée');
    }

    // ── Correction d'une déclaration erronée ──────────────────────────────────

    public function test_an_erroneous_declaration_is_cancelled_by_a_manager_with_a_reason_never_after_submission(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Paul');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        [$type, $firm] = $this->pricedCatalogue();
        $mission = $this->mission($surgeon);
        $id = (int) $mission->getId();

        $surgeonToken = $this->login($client, $surgeon);
        self::assertSame(Response::HTTP_OK, $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/declare-executed-without-instrumentist")->getStatusCode());
        self::assertSame(Response::HTTP_CREATED, $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0])->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/cancel-executed-without-instrumentist", ['reason' => 'Erreur'])->getStatusCode(), 'le chirurgien n\'annule pas');

        $managerToken = $this->login($client, $manager);
        self::assertContains('cancel_executed_without_instrumentist', $this->detail($client, $managerToken, $id)['allowedActions']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->send($client, $managerToken, 'POST', "/api/missions/$id/cancel-executed-without-instrumentist", ['reason' => '  '])->getStatusCode(), 'motif obligatoire');
        $cancelled = $this->send($client, $managerToken, 'POST', "/api/missions/$id/cancel-executed-without-instrumentist", ['reason' => "Une instrumentiste était bien présente, l'affectation sera corrigée."]);
        self::assertSame(Response::HTTP_OK, $cancelled->getStatusCode(), (string) $cancelled->getContent());
        $body = json_decode((string) $cancelled->getContent(), true);
        self::assertSame('OPEN', $body['status']);
        self::assertNull($body['executedWithoutInstrumentist']);

        $this->em->clear();
        $audit = $this->em->getRepository(AuditEvent::class)->findOneBy(['mission' => $id, 'eventType' => AuditEventType::MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_CANCELLED]);
        self::assertNotNull($audit);
        self::assertSame('Paul D155', $audit->getPayload()['declaredByName']);
        self::assertSame(1, $audit->getPayload()['interventionCount'], 'le contenu encodé est conservé et tracé, jamais supprimé en silence');

        // Une fois soumise, la déclaration ne s'annule plus (renvoi en correction d'abord).
        $second = $this->mission($surgeon);
        $sid = (int) $second->getId();
        $surgeonToken = $this->login($client, $surgeon);
        $this->send($client, $surgeonToken, 'POST', "/api/missions/$sid/declare-executed-without-instrumentist");
        $this->send($client, $surgeonToken, 'POST', "/api/missions/$sid/interventions", ['interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(Response::HTTP_OK, $this->send($client, $surgeonToken, 'POST', "/api/missions/$sid/encoding/complete", ['comment' => 'Arthroscopie.'])->getStatusCode());
        $managerToken = $this->login($client, $manager);
        self::assertNotContains('cancel_executed_without_instrumentist', $this->detail($client, $managerToken, $sid)['allowedActions']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $managerToken, 'POST', "/api/missions/$sid/cancel-executed-without-instrumentist", ['reason' => 'Trop tard'])->getStatusCode());
    }

    // ── Missions classiques inchangées ────────────────────────────────────────

    public function test_a_classic_mission_without_instrumentist_is_still_never_calculated(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Paul');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $mission = $this->mission($surgeon, MissionStatus::VALIDATED);

        $response = $this->send($client, $this->login($client, $manager), 'POST', "/api/missions/{$mission->getId()}/financial-calculations");
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('instrumentist', (string) $response->getContent());
    }
}
