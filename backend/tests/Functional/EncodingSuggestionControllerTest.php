<?php

namespace App\Tests\Functional;

use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
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
 * D-178 — raccourcis « firmes récemment utilisées » (utilisateur connecté) et « interventions
 * récemment utilisées » (firme × chirurgien de la mission), calculés sur les seules interventions
 * réellement enregistrées. La sélection d'une suggestion passe par l'endpoint de création
 * inchangé : mêmes règles (choix obligatoire, exclusivité, firme obligatoire) qu'une sélection
 * dans le catalogue complet.
 */
final class EncodingSuggestionControllerTest extends WebTestCase
{
    private const PASSWORD = 'Suggest176!';
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
            $this->em->clear();
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

    // ── Firmes récemment utilisées ──────────────────────────────────────

    public function test_recent_firms_are_the_two_last_distinct_firms_of_the_user_most_recent_first(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        [$arthrex, $stryker, $zimmer] = [$this->firm('Arthrex'), $this->firm('Stryker'), $this->firm('Zimmer')];
        $type = $this->type();

        $this->intervention($mission, $type, $zimmer, $instr, '-5 days');
        $this->intervention($mission, $type, $arthrex, $instr, '-3 days');
        $this->intervention($mission, $type, $stryker, $instr, '-2 days');
        $this->intervention($mission, $type, $arthrex, $instr, '-1 day'); // doublon : Arthrex remonte, une seule fois

        $body = $this->getJson($client, "/api/missions/{$mission->getId()}/encoding-suggestions/firms", $this->login($client, $instr));

        self::assertSame([$arthrex->getId(), $stryker->getId()], array_column($body['firms'], 'id'));
        self::assertSame(['id', 'name'], array_keys($body['firms'][0]));
    }

    public function test_recent_firms_empty_history_then_single_firm(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $token = $this->login($client, $instr);
        $uri = "/api/missions/{$mission->getId()}/encoding-suggestions/firms";

        self::assertSame([], $this->getJson($client, $uri, $token)['firms']);

        $firm = $this->firm('Arthrex');
        $this->intervention($mission, $this->type(), $firm, $instr, '-1 day');
        $this->intervention($mission, $this->type(), $firm, $instr, '-2 days');

        self::assertSame([$firm->getId()], array_column($this->getJson($client, $uri, $token)['firms'], 'id'));
    }

    public function test_recent_firms_exclude_other_users_inactive_firms_and_cancelled_missions(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $other = $this->user('ROLE_INSTRUMENTIST');
        $mission = $this->mission($surgeon, $instr, $site);
        $cancelled = $this->mission($surgeon, $instr, $site, MissionStatus::CANCELLED);
        $type = $this->type();

        $kept = $this->firm('Kept');
        $inactive = $this->firm('Inactive');
        $byOther = $this->firm('ByOther');
        $onCancelled = $this->firm('OnCancelled');

        $this->intervention($mission, $type, $kept, $instr, '-4 days');
        $this->intervention($mission, $type, $inactive, $instr, '-3 days');
        $inactive->setActive(false);
        $this->intervention($mission, $type, $byOther, $other, '-2 days'); // encodée par quelqu'un d'autre
        $this->intervention($cancelled, $type, $onCancelled, $instr, '-1 day');
        $this->em->flush();

        $body = $this->getJson($client, "/api/missions/{$mission->getId()}/encoding-suggestions/firms", $this->login($client, $instr));

        self::assertSame([$kept->getId()], array_column($body['firms'], 'id'));
    }

    public function test_legacy_interventions_without_author_are_never_attributed_for_firms_but_count_for_firm_and_surgeon(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $legacyFirm = $this->firm('Legacy');
        $legacyType = $this->type('LEGACY');
        $this->intervention($mission, $legacyType, $legacyFirm, null, '-10 days'); // antérieure : auteur inconnu

        // Firmes : aucun auteur reconstruit, ni pour l'instrumentiste de la mission ni pour personne.
        $token = $this->login($client, $instr);
        self::assertSame([], $this->getJson($client, "/api/missions/{$mission->getId()}/encoding-suggestions/firms", $token)['firms']);

        // Firme × chirurgien : l'auteur est sans objet, l'intervention historique reste éligible.
        self::assertSame([$legacyType->getId()], array_column($this->getJson($client, $this->typesUri($mission, $legacyFirm), $token)['interventionTypes'], 'id'));

        $this->em->clear();
        self::assertNull($this->em->getRepository(MissionIntervention::class)->findOneBy(['primaryFirm' => $legacyFirm->getId()])->getCreatedBy(), 'aucun auteur écrit en base');
    }

    // ── Interventions récemment utilisées (firme × chirurgien) ──────────

    public function test_recent_types_are_two_last_distinct_types_for_firm_and_surgeon_shared_between_instrumentists(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $colleague = $this->user('ROLE_INSTRUMENTIST');
        $mission = $this->mission($surgeon, $instr, $site);
        $past = $this->mission($surgeon, $colleague, $site);
        $firm = $this->firm('Arthrex');
        [$lca, $osteo, $old] = [$this->type('LCA'), $this->type('OSTEO'), $this->type('OLD')];

        $this->intervention($past, $old, $firm, $colleague, '-9 days');
        $this->intervention($past, $osteo, $firm, $colleague, '-5 days');
        $this->intervention($past, $lca, $firm, $colleague, '-3 days');
        $this->intervention($past, $osteo, $firm, $colleague, '-1 day');

        $body = $this->getJson($client, $this->typesUri($mission, $firm), $this->login($client, $instr));

        self::assertSame($firm->getId(), $body['firmId']);
        self::assertSame($surgeon->getId(), $body['surgeonId']);
        self::assertSame([$osteo->getId(), $lca->getId()], array_column($body['interventionTypes'], 'id'));
        self::assertSame(['id', 'code', 'label'], array_keys($body['interventionTypes'][0]));
    }

    public function test_recent_types_follow_the_mission_surgeon_and_the_firm(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $otherSurgeon = $this->user('ROLE_SURGEON');
        $mission = $this->mission($surgeon, $instr, $site); // mission en cours d'encodage, sans historique propre
        $history = $this->mission($surgeon, $instr, $site);
        $otherSurgeonMission = $this->mission($otherSurgeon, $instr, $site);
        [$arthrex, $stryker] = [$this->firm('Arthrex'), $this->firm('Stryker')];
        [$lca, $latarjet, $pth] = [$this->type('LCA'), $this->type('LATARJET'), $this->type('PTH')];

        $this->intervention($history, $lca, $arthrex, $instr, '-3 days');
        $this->intervention($history, $pth, $stryker, $instr, '-2 days');
        $this->intervention($otherSurgeonMission, $latarjet, $arthrex, $instr, '-1 day');
        $token = $this->login($client, $instr);

        self::assertSame([$lca->getId()], array_column($this->getJson($client, $this->typesUri($mission, $arthrex), $token)['interventionTypes'], 'id'));
        // Changement de firme : recalculé.
        self::assertSame([$pth->getId()], array_column($this->getJson($client, $this->typesUri($mission, $stryker), $token)['interventionTypes'], 'id'));

        // Changement du chirurgien de la mission : recalculé (jamais l'utilisateur connecté).
        $mission = $this->em->find(Mission::class, $mission->getId());
        $mission->setSurgeon($this->em->getReference(User::class, $otherSurgeon->getId()));
        $this->em->flush();
        $body = $this->getJson($client, $this->typesUri($mission, $arthrex), $token);
        self::assertSame($otherSurgeon->getId(), $body['surgeonId']);
        self::assertSame([$latarjet->getId()], array_column($body['interventionTypes'], 'id'));
    }

    public function test_recent_types_exclude_unavailable_types(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $cancelled = $this->mission($surgeon, $instr, $site, MissionStatus::CANCELLED);
        $firm = $this->firm('Arthrex');
        [$offered, $inactive, $notOffered, $offeringOff, $onCancelled] = [$this->type('OFFERED'), $this->type('INACTIVE'), $this->type('NOTOFFERED'), $this->type('OFFOFF'), $this->type('CANC')];
        $this->offering($firm, $offered);
        $this->offering($firm, $inactive);
        $this->offering($firm, $offeringOff, active: false);
        $this->offering($firm, $onCancelled);

        $this->intervention($mission, $offered, $firm, $instr, '-6 days');
        $this->intervention($mission, $inactive, $firm, $instr, '-5 days');
        $this->intervention($mission, $notOffered, $firm, $instr, '-4 days'); // la firme a des prestations, pas celle-ci
        $this->intervention($mission, $offeringOff, $firm, $instr, '-3 days');
        $this->intervention($cancelled, $onCancelled, $firm, $instr, '-1 day');
        $inactive->setActive(false);
        $this->em->flush();

        $body = $this->getJson($client, $this->typesUri($mission, $firm), $this->login($client, $instr));

        self::assertSame([$offered->getId()], array_column($body['interventionTypes'], 'id'));
    }

    public function test_recent_types_for_firm_without_offerings_use_the_active_catalogue_and_inactive_firm_yields_nothing(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $firm = $this->firm('NoOfferings');
        $type = $this->type('ANY');
        $this->intervention($mission, $type, $firm, $instr, '-1 day');
        $token = $this->login($client, $instr);

        self::assertSame([$type->getId()], array_column($this->getJson($client, $this->typesUri($mission, $firm), $token)['interventionTypes'], 'id'));

        $firm = $this->em->find(Firm::class, $firm->getId());
        $firm->setActive(false);
        $this->em->flush();
        self::assertSame([], $this->getJson($client, $this->typesUri($mission, $firm), $token)['interventionTypes']);
    }

    // ── Droits ──────────────────────────────────────────────────────────

    public function test_suggestions_require_encoding_rights_on_the_mission(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $stranger = $this->user('ROLE_INSTRUMENTIST');
        $mission = $this->mission($surgeon, $instr, $site);
        $firm = $this->firm('Arthrex');

        $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding-suggestions/firms");
        self::assertSame(401, $client->getResponse()->getStatusCode());

        $token = $this->login($client, $stranger);
        $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding-suggestions/firms", $token);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $this->request($client, 'GET', $this->typesUri($mission, $firm), $token);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $token = $this->login($client, $instr);
        $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding-suggestions/intervention-types", $token);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->request($client, 'GET', "/api/missions/999999999/encoding-suggestions/firms", $token);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ── Sélection d'une suggestion = sélection catalogue ────────────────

    public function test_creating_from_a_suggestion_records_the_author_and_keeps_mandatory_choice_rules(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $firm = $this->firm('Medtronic');
        $type = $this->type('TLIF');
        [$signature, $altera] = $this->exclusiveChoice($firm, $type);
        $token = $this->login($client, $instr);

        // Historique réel sur une autre mission du même chirurgien.
        $past = $this->mission($surgeon, $this->user('ROLE_INSTRUMENTIST'), $site);
        $this->intervention($past, $type, $firm, null, '-2 days', $signature);
        $suggested = $this->getJson($client, $this->typesUri($mission, $firm), $token)['interventionTypes'];
        self::assertSame([$type->getId()], array_column($suggested, 'id'));

        $uri = "/api/missions/{$mission->getId()}/interventions";
        // Choix obligatoire : jamais contourné, même depuis une suggestion.
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $suggested[0]['id'], 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());

        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $suggested[0]['id'], 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0, 'selectedChoiceOptionId' => $altera->getId()]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($altera->getId(), $created['selectedChoiceOptionId']);

        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $created['id']);
        self::assertSame($instr->getId(), $intervention->getCreatedBy()?->getId());
        self::assertSame($altera->getId(), $intervention->getSelectedChoiceOption()?->getId(), 'le choix de l\'historique (Signature) n\'est jamais repris');
        self::assertCount(0, $intervention->getMaterialLines(), 'aucun matériel copié depuis l\'historique');

        // La firme suggérée remonte désormais pour l'auteur.
        self::assertSame([$firm->getId()], array_column($this->getJson($client, "/api/missions/{$mission->getId()}/encoding-suggestions/firms", $token)['firms'], 'id'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        // Fixtures créées entre deux requêtes : un seul noyau, un seul EntityManager.
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    /** @return array{0: User, 1: User, 2: Hospital} */
    private function people(): array
    {
        return [$this->user('ROLE_SURGEON'), $this->user('ROLE_INSTRUMENTIST'), $this->site()];
    }

    private function user(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('suggest176-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Suggest');
        $u->setLastname('Test');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function site(): Hospital
    {
        $h = new Hospital();
        $h->setName('Suggest176Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function firm(string $name): Firm
    {
        $f = new Firm();
        $f->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function type(string $code = 'T'): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type ' . $code);
        $this->em->persist($t); $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function offering(Firm $firm, InterventionType $type, bool $active = true): FirmServiceOffering
    {
        $o = new FirmServiceOffering();
        $o->setFirm($firm);
        $o->setInterventionType($type);
        $o->setActive($active);
        $this->em->persist($o); $this->em->flush();
        $this->createdIds['offerings'][] = $o->getId();
        return $o;
    }

    /** @return array{0: ChoiceOption, 1: ChoiceOption} */
    private function exclusiveChoice(Firm $firm, InterventionType $type): array
    {
        $offering = $this->offering($firm, $type);
        $group = new RequiredChoiceGroup();
        $group->setOffering($offering);
        $group->setQuestion('Quel implant intersomatique a été utilisé ?');
        $this->em->persist($group); $this->em->flush();
        $this->createdIds['groups'][] = $group->getId();

        $options = [];
        foreach (['Signature', 'Altera'] as $label) {
            $item = new MaterialItem();
            $item->setFirm($firm);
            $item->setLabel('Cage ' . $label);
            $item->setUnit('pièce');
            $item->setReferenceCode(bin2hex(random_bytes(4)));
            $this->em->persist($item); $this->em->flush();
            $this->createdIds['items'][] = $item->getId();

            $option = new ChoiceOption();
            $option->setGroup($group);
            $option->setLabel($label);
            $option->setMaterialItem($item);
            $this->em->persist($option); $this->em->flush();
            $this->createdIds['options'][] = $option->getId();
            $options[] = $option;
        }
        return $options;
    }

    private function mission(User $surgeon, User $instr, Hospital $site, MissionStatus $status = MissionStatus::ASSIGNED): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
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

    private function intervention(Mission $mission, InterventionType $type, Firm $firm, ?User $author, string $ago, ?ChoiceOption $choice = null): MissionIntervention
    {
        // Références par id : une requête HTTP (login) vide l'EntityManager entre deux fixtures.
        $i = new MissionIntervention();
        $i->setMission($this->em->getReference(Mission::class, $mission->getId()))
            ->setInterventionType($this->em->getReference(InterventionType::class, $type->getId()))
            ->setPrimaryFirm($this->em->getReference(Firm::class, $firm->getId()))
            ->setCode((string) $type->getCode())
            ->setLabel((string) $type->getLabel())
            ->setOrderIndex(0)
            ->setSelectedChoiceOption($choice !== null ? $this->em->getReference(ChoiceOption::class, $choice->getId()) : null)
            ->setCreatedBy($author !== null ? $this->em->getReference(User::class, $author->getId()) : null);
        $i->setCreatedAt(new \DateTimeImmutable($ago));
        $this->em->persist($i);
        $this->em->flush();
        return $i;
    }

    private function typesUri(Mission $mission, Firm $firm): string
    {
        return "/api/missions/{$mission->getId()}/encoding-suggestions/intervention-types?firmId={$firm->getId()}";
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

    private function getJson(KernelBrowser $client, string $uri, string $token): array
    {
        $response = $this->request($client, 'GET', $uri, $token);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }
}
