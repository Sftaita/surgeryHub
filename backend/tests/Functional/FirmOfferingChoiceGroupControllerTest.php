<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Entity\RequiredChoiceGroup;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tarification firme conditionnée à un choix obligatoire — configuration manager
 * (RequiredChoiceGroup/ChoiceOption) sous /api/firms/{firmId}/service-offerings/{id}/choice-group.
 */
final class FirmOfferingChoiceGroupControllerTest extends WebTestCase
{
    private const PASSWORD = 'ChoiceGroup15!';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'options' => [], 'groups' => [], 'offerings' => [], 'rules' => [], 'items' => [], 'types' => [], 'firms' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
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
            // createPricingRule() émet un AuditEvent global (actor = manager de test) —
            // à purger avant de supprimer les utilisateurs de test (FK actor_id).
            foreach ($this->createdIds['users'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('cgroup-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
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

    private function request(KernelBrowser $client, string $method, string $uri, string $token, array $body = []): Response
    {
        $client->request($method, $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $method === 'GET' ? null : json_encode($body),
        );
        return $client->getResponse();
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('CGFirm-' . bin2hex(random_bytes(4)));
        $this->em->persist($f); $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('CG-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type de test');
        $this->em->persist($t); $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel('Item-' . bin2hex(random_bytes(3)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($mi); $this->em->flush();
        $this->createdIds['items'][] = $mi->getId();
        return $mi;
    }

    private function makeOffering(Firm $firm, InterventionType $type): FirmServiceOffering
    {
        $o = new FirmServiceOffering();
        $o->setFirm($firm);
        $o->setInterventionType($type);
        $this->em->persist($o); $this->em->flush();
        $this->createdIds['offerings'][] = $o->getId();
        return $o;
    }

    public function test_manager_configures_group_and_two_options(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);
        $item = $this->makeItem($firm);

        $groupResponse = $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $token, [
            'question' => 'Quel implant intersomatique a été utilisé ?',
        ]);
        self::assertSame(Response::HTTP_OK, $groupResponse->getStatusCode(), $groupResponse->getContent());
        $group = json_decode($groupResponse->getContent(), true);
        $this->createdIds['groups'][] = $group['id'];
        self::assertSame([], $group['options']);
        self::assertFalse($group['operational'], 'moins de 2 options actives — pas encore opérationnel');

        $optA = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, [
            'label' => 'Signature', 'materialItemId' => $item->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $optA->getStatusCode(), $optA->getContent());
        $optionA = json_decode($optA->getContent(), true);
        $this->createdIds['options'][] = $optionA['id'];
        self::assertSame($item->getId(), $optionA['materialItem']['id']);

        $optB = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, [
            'label' => 'Altera',
        ]);
        self::assertSame(Response::HTTP_CREATED, $optB->getStatusCode(), $optB->getContent());
        $optionB = json_decode($optB->getContent(), true);
        $this->createdIds['options'][] = $optionB['id'];
        self::assertNull($optionB['materialItem']);

        // Devient opérationnel dès 2 options actives.
        $list = $this->request($client, 'GET', "/api/firms/{$firm->getId()}/service-offerings", $token);
        $offeringBody = json_decode($list->getContent(), true)[0];
        self::assertTrue($offeringBody['choiceGroupConfig']['operational']);
        self::assertNotNull($offeringBody['choiceGroup'], 'exposé à tous une fois opérationnel');
        self::assertSame('Quel implant intersomatique a été utilisé ?', $offeringBody['choiceGroup']['question']);
        self::assertCount(2, $offeringBody['choiceGroup']['options']);
    }

    public function test_option_material_from_another_firm_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $otherFirm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);
        $foreignItem = $this->makeItem($otherFirm);

        $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $token, ['question' => 'Q ?']);
        $group = $this->em->getRepository(RequiredChoiceGroup::class)->findOneBy(['offering' => $offering]);
        $this->createdIds['groups'][] = $group->getId();

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, [
            'label' => 'Option invalide', 'materialItemId' => $foreignItem->getId(),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $response->getContent());
    }

    public function test_duplicate_material_within_same_group_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);
        $item = $this->makeItem($firm);

        $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $token, ['question' => 'Q ?']);
        $group = $this->em->getRepository(RequiredChoiceGroup::class)->findOneBy(['offering' => $offering]);
        $this->createdIds['groups'][] = $group->getId();

        $first = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, [
            'label' => 'A', 'materialItemId' => $item->getId(),
        ]);
        $this->createdIds['options'][] = json_decode($first->getContent(), true)['id'];

        $second = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, [
            'label' => 'B', 'materialItemId' => $item->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode(), $second->getContent());
    }

    public function test_option_never_used_can_be_physically_deleted(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);

        $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $token, ['question' => 'Q ?']);
        $group = $this->em->getRepository(RequiredChoiceGroup::class)->findOneBy(['offering' => $offering]);
        $this->createdIds['groups'][] = $group->getId();

        $optResponse = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, ['label' => 'A']);
        $optionId = json_decode($optResponse->getContent(), true)['id'];

        $delete = $this->request($client, 'DELETE', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options/{$optionId}", $token);

        self::assertSame(Response::HTTP_OK, $delete->getStatusCode(), $delete->getContent());
        self::assertNull($this->em->find(ChoiceOption::class, $optionId));
    }

    public function test_option_used_by_pricing_rule_cannot_be_deleted(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);

        $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $token, ['question' => 'Q ?']);
        $group = $this->em->getRepository(RequiredChoiceGroup::class)->findOneBy(['offering' => $offering]);
        $this->createdIds['groups'][] = $group->getId();

        $optResponse = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, ['label' => 'A']);
        $optionId = json_decode($optResponse->getContent(), true)['id'];
        $this->createdIds['options'][] = $optionId;

        $ruleResponse = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'choiceOptionId' => $optionId, 'unitPrice' => 180,
        ]);
        self::assertSame(Response::HTTP_CREATED, $ruleResponse->getStatusCode(), $ruleResponse->getContent());
        $this->createdIds['rules'][] = json_decode($ruleResponse->getContent(), true)['id'];

        $delete = $this->request($client, 'DELETE', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options/{$optionId}", $token);

        self::assertSame(Response::HTTP_CONFLICT, $delete->getStatusCode(), $delete->getContent());
        self::assertNotNull($this->em->find(ChoiceOption::class, $optionId), 'jamais de perte d\'historique financier');
    }

    public function test_deactivating_group_reverts_to_single_forfait_without_deleting_data(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);

        $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $token, ['question' => 'Q ?']);
        $group = $this->em->getRepository(RequiredChoiceGroup::class)->findOneBy(['offering' => $offering]);
        $this->createdIds['groups'][] = $group->getId();
        $optA = json_decode($this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, ['label' => 'A'])->getContent(), true);
        $optB = json_decode($this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $token, ['label' => 'B'])->getContent(), true);
        $this->createdIds['options'][] = $optA['id'];
        $this->createdIds['options'][] = $optB['id'];

        $deactivate = $this->request($client, 'DELETE', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $token);
        self::assertSame(Response::HTTP_OK, $deactivate->getStatusCode(), $deactivate->getContent());

        $list = $this->request($client, 'GET', "/api/firms/{$firm->getId()}/service-offerings", $token);
        $body = json_decode($list->getContent(), true)[0];
        self::assertNull($body['choiceGroup'], 'redevenu forfait unique côté instrumentiste');
        self::assertFalse($body['choiceGroupConfig']['active'], 'données conservées côté manager, seulement désactivées');
        self::assertCount(2, $body['choiceGroupConfig']['options'], 'les options ne sont jamais supprimées à la désactivation');
    }

    /** Sécurité — la configuration complète (choiceGroupConfig) reste réservée au manager, jamais un montant nulle part. */
    public function test_instrumentist_never_sees_choice_group_config_only_the_operational_view(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);

        $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $managerToken, ['question' => 'Quel implant ?']);
        $group = $this->em->getRepository(RequiredChoiceGroup::class)->findOneBy(['offering' => $offering]);
        $this->createdIds['groups'][] = $group->getId();
        $optA = json_decode($this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $managerToken, ['label' => 'Signature'])->getContent(), true);
        $optB = json_decode($this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $managerToken, ['label' => 'Altera'])->getContent(), true);
        $this->createdIds['options'][] = $optA['id'];
        $this->createdIds['options'][] = $optB['id'];

        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $instrToken = $this->login($client, $instr);
        $list = $this->request($client, 'GET', "/api/firms/{$firm->getId()}/service-offerings", $instrToken);
        $body = json_decode($list->getContent(), true)[0];

        self::assertArrayNotHasKey('choiceGroupConfig', $body, 'vue complète réservée au manager');
        self::assertNotNull($body['choiceGroup']);
        self::assertSame('Quel implant ?', $body['choiceGroup']['question']);
        self::assertCount(2, $body['choiceGroup']['options']);
        // Jamais un montant nulle part dans cette structure, quel que soit le rôle —
        // seul FirmBillingController (BillingVoter::MANAGE) expose PricingRule.unitPrice.
        self::assertJsonStringNotContainsUnitPrice($list->getContent());
    }

    private static function assertJsonStringNotContainsUnitPrice(string $json): void
    {
        self::assertStringNotContainsString('unitPrice', $json);
    }

    public function test_instrumentist_cannot_configure_choice_group(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $offering = $this->makeOffering($firm, $type);
        $managerToken = $this->login($client, $manager);
        $this->request($client, 'PUT', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group", $managerToken, ['question' => 'Q ?']);
        $group = $this->em->getRepository(RequiredChoiceGroup::class)->findOneBy(['offering' => $offering]);
        $this->createdIds['groups'][] = $group->getId();

        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $instrToken = $this->login($client, $instr);

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offering->getId()}/choice-group/options", $instrToken, ['label' => 'X']);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
