<?php

namespace App\Tests\Functional;

use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\RequiredChoiceGroup;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tarification firme conditionnée à un choix obligatoire — encodage instrumentiste :
 * réponse obligatoire quand le groupe est opérationnel, exclusivité matérielle
 * automatique, confirmation explicite avant retrait de matériel devenu incompatible.
 */
final class InterventionControllerChoiceOptionTest extends WebTestCase
{
    private const PASSWORD = 'ChoiceOpt15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [],
        'offerings' => [], 'groups' => [], 'options' => [], 'items' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getMaterialLines() as $l) { $this->em->remove($l); }
                    foreach ($m->getInterventions() as $i) { $this->em->remove($i); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['options'] as $id) { $e = $this->em->find(ChoiceOption::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['groups'] as $id) { $e = $this->em->find(RequiredChoiceGroup::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('choiceopt-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Choice');
        $u->setLastname('Test');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function request(KernelBrowser $client, string $method, string $uri, ?string $token = null, ?array $body = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);
        return $client->getResponse();
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('ChoiceOptSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('ChoiceOptFirm-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('CHOPT-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type ChoiceOpt');
        $this->em->persist($t); $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm, string $label): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel($label);
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($mi); $this->em->flush();
        $this->createdIds['items'][] = $mi->getId();
        return $mi;
    }

    /** @return array{0: FirmServiceOffering, 1: ChoiceOption, 2: ChoiceOption} */
    private function makeOperationalChoiceGroup(Firm $firm, InterventionType $type, MaterialItem $signatureItem, MaterialItem $alteraItem): array
    {
        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $this->em->persist($offering); $this->em->flush();
        $this->createdIds['offerings'][] = $offering->getId();

        $group = new RequiredChoiceGroup();
        $group->setOffering($offering);
        $group->setQuestion('Quel implant intersomatique a été utilisé ?');
        $this->em->persist($group); $this->em->flush();
        $this->createdIds['groups'][] = $group->getId();

        $signature = new ChoiceOption();
        $signature->setGroup($group);
        $signature->setLabel('Signature');
        $signature->setMaterialItem($signatureItem);
        $this->em->persist($signature);

        $altera = new ChoiceOption();
        $altera->setGroup($group);
        $altera->setLabel('Altera');
        $altera->setMaterialItem($alteraItem);
        $this->em->persist($altera);

        $this->em->flush();
        $this->createdIds['options'][] = $signature->getId();
        $this->createdIds['options'][] = $altera->getId();

        return [$offering, $signature, $altera];
    }

    private function makeEncodableMission(User $surgeon, User $instr, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instr);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-1 hour'));
        $m->setEndAt($now->modify('+2 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function bootMissionScenario(): array
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeEncodableMission($surgeon, $instr, $site);
        $token = $this->login($client, $instr);
        return [$client, $mission, $token, $instr];
    }

    // ── Réponse obligatoire quand le groupe est opérationnel ────────────

    public function test_create_is_rejected_when_group_operational_and_no_choice_provided(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $this->makeOperationalChoiceGroup($firm, $type, $this->makeItem($firm, 'Cage Signature'), $this->makeItem($firm, 'Cage Altera'));

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
        ]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent());
    }

    public function test_create_succeeds_with_valid_choice_option(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        [, $signature] = $this->makeOperationalChoiceGroup($firm, $type, $this->makeItem($firm, 'Cage Signature'), $this->makeItem($firm, 'Cage Altera'));

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
            'selectedChoiceOptionId' => $signature->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame($signature->getId(), $body['selectedChoiceOptionId']);
        self::assertArrayNotHasKey('unitAmount', $body, 'jamais un montant dans la réponse d\'encodage');

        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $body['id']);
        self::assertSame($signature->getId(), $intervention->getSelectedChoiceOption()?->getId());
    }

    public function test_create_rejects_choice_option_belonging_to_another_offering(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $this->makeOperationalChoiceGroup($firm, $type, $this->makeItem($firm, 'Cage Signature'), $this->makeItem($firm, 'Cage Altera'));

        $otherFirm = $this->makeFirm();
        $otherType = $this->makeType();
        [, $foreignOption] = $this->makeOperationalChoiceGroup($otherFirm, $otherType, $this->makeItem($otherFirm, 'X'), $this->makeItem($otherFirm, 'Y'));

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
            'selectedChoiceOptionId' => $foreignOption->getId(),
        ]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent());
    }

    public function test_standard_offering_without_group_is_never_blocked(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
    }

    // ── Exclusivité matérielle automatique ───────────────────────────────

    public function test_incompatible_material_is_rejected_with_409(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $signatureItem = $this->makeItem($firm, 'Cage Signature');
        $alteraItem = $this->makeItem($firm, 'Cage Altera');
        [, $signature] = $this->makeOperationalChoiceGroup($firm, $type, $signatureItem, $alteraItem);

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
            'selectedChoiceOptionId' => $signature->getId(),
        ]);
        $interventionId = json_decode($created->getContent(), true)['id'];

        // Signature sélectionnée -> Altera (matériel de l'AUTRE option) doit être refusé.
        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'missionInterventionId' => $interventionId,
            'itemId' => $alteraItem->getId(),
            'quantity' => '1.00',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $response->getContent());
        self::assertSame('INCOMPATIBLE_CHOICE_MATERIAL', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_compatible_material_of_selected_option_is_accepted(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $signatureItem = $this->makeItem($firm, 'Cage Signature');
        $alteraItem = $this->makeItem($firm, 'Cage Altera');
        [, $signature] = $this->makeOperationalChoiceGroup($firm, $type, $signatureItem, $alteraItem);

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
            'selectedChoiceOptionId' => $signature->getId(),
        ]);
        $interventionId = json_decode($created->getContent(), true)['id'];

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'missionInterventionId' => $interventionId,
            'itemId' => $signatureItem->getId(),
            'quantity' => '1.00',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
    }

    // ── §8 : changement de choix avec matériel déjà encodé ───────────────

    public function test_changing_choice_with_incompatible_material_requires_confirmation(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $signatureItem = $this->makeItem($firm, 'Cage Signature');
        $alteraItem = $this->makeItem($firm, 'Cage Altera');
        [, $signature, $altera] = $this->makeOperationalChoiceGroup($firm, $type, $signatureItem, $alteraItem);

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
            'selectedChoiceOptionId' => $signature->getId(),
        ]);
        $interventionId = json_decode($created->getContent(), true)['id'];

        $lineResponse = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'missionInterventionId' => $interventionId,
            'itemId' => $signatureItem->getId(),
            'quantity' => '1.00',
        ]);
        self::assertSame(Response::HTTP_CREATED, $lineResponse->getStatusCode());
        $lineId = json_decode($lineResponse->getContent(), true)['id'];

        // Changement vers Altera sans confirmation -> 409, matériel Signature intact.
        $blocked = $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$interventionId}", $token, [
            'selectedChoiceOptionId' => $altera->getId(),
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $blocked->getStatusCode(), $blocked->getContent());
        self::assertSame('CHOICE_OPTION_CHANGE_REQUIRES_CONFIRMATION', json_decode($blocked->getContent(), true)['error']['code']);

        $this->em->clear();
        self::assertNotNull($this->em->find(MaterialLine::class, $lineId), 'jamais de suppression silencieuse');
        self::assertSame($signature->getId(), $this->em->find(MissionIntervention::class, $interventionId)->getSelectedChoiceOption()?->getId());

        // Avec confirmation explicite -> succès, matériel incompatible retiré.
        $confirmed = $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$interventionId}", $token, [
            'selectedChoiceOptionId' => $altera->getId(),
            'confirmRemoveIncompatibleMaterial' => true,
        ]);
        self::assertSame(Response::HTTP_NO_CONTENT, $confirmed->getStatusCode(), $confirmed->getContent());

        $this->em->clear();
        self::assertNull($this->em->find(MaterialLine::class, $lineId), 'le matériel devenu incompatible doit être retiré');
        self::assertSame($altera->getId(), $this->em->find(MissionIntervention::class, $interventionId)->getSelectedChoiceOption()?->getId());
    }

    public function test_changing_choice_without_conflicting_material_succeeds_directly(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        [, $signature, $altera] = $this->makeOperationalChoiceGroup($firm, $type, $this->makeItem($firm, 'Cage Signature'), $this->makeItem($firm, 'Cage Altera'));

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
            'selectedChoiceOptionId' => $signature->getId(),
        ]);
        $interventionId = json_decode($created->getContent(), true)['id'];

        $response = $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$interventionId}", $token, [
            'selectedChoiceOptionId' => $altera->getId(),
        ]);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), $response->getContent());
        $this->em->clear();
        self::assertSame($altera->getId(), $this->em->find(MissionIntervention::class, $interventionId)->getSelectedChoiceOption()?->getId());
    }
}
