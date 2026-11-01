<?php

namespace App\Tests\Functional;

use App\Dto\MissionFinancialEvaluation;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\FinancialCalculationService;
use App\Service\InterventionRequirementsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-178 — interventions sans firme. Le manager autorise un type à être encodé « Aucune firme »
 * (Catalogue › Interventions) ; seul ce choix explicite, enregistré sur l'intervention
 * (MissionIntervention::$withoutFirm), rend une intervention légitimement sans firme :
 * complétude, calcul financier (jamais MISSING_PRIMARY_FIRM), facturation. Le paramétrage ne
 * s'applique qu'aux nouveaux encodages ; une firme simplement absente (historique) reste une
 * donnée manquante (D-146 bis). Aucun contournement par l'API.
 */
final class InterventionWithoutFirmTest extends WebTestCase
{
    private const PASSWORD = 'SansFirme178!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'offerings' => [], 'items' => []];
    /** @var list<string> codes de types créés via l'API (résolution d'une demande) */
    private array $createdTypeCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            foreach ($this->createdIds['missions'] as $id) {
                $conn->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM material_line WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM mission_intervention_draft WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM intervention_type_request WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM mission_intervention WHERE mission_id = ?', [$id]);
            }
            foreach ($this->createdIds['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdTypeCodes as $code) {
                $t = $this->em->getRepository(InterventionType::class)->findOneBy(['code' => $code]);
                if ($t) $this->em->remove($t);
            }
            foreach ($this->createdIds['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Paramétrage manager ─────────────────────────────────────────────

    public function test_only_a_manager_authorises_a_type_without_firm_and_the_encoding_catalogue_exposes_it(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $type = $this->type('QUAD');

        $instrToken = $this->login($client, $instr);
        $this->request($client, 'PATCH', "/api/intervention-types/{$type->getId()}", $instrToken, ['allowedWithoutFirm' => true]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $managerToken = $this->login($client, $manager);
        $this->request($client, 'PATCH', "/api/intervention-types/{$type->getId()}", $managerToken, ['allowedWithoutFirm' => true]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['allowedWithoutFirm']);

        $catalogue = $this->getJson($client, "/api/missions/{$mission->getId()}/encoding", $this->login($client, $instr))['catalog']['interventionTypes'];
        $entry = array_values(array_filter($catalogue, static fn (array $t) => $t['id'] === $type->getId()))[0];
        self::assertTrue($entry['allowedWithoutFirm']);
    }

    // ── Création ────────────────────────────────────────────────────────

    public function test_creation_without_firm_needs_the_explicit_choice_and_an_authorised_type(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $allowed = $this->type('QUAD', allowedWithoutFirm: true);
        $notAllowed = $this->type('LCA');
        $firm = $this->firm('Arthrex');
        $token = $this->login($client, $instr);
        $uri = "/api/missions/{$mission->getId()}/interventions";

        // Type non autorisé sans firme : refus, même par appel API direct.
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $notAllowed->getId(), 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('ne peut pas être encodée sans firme', json_encode(json_decode((string) $client->getResponse()->getContent(), true), JSON_UNESCAPED_UNICODE));

        // Firme simplement absente : toujours refusée (D-146 bis), même sur un type autorisé.
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $allowed->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Une firme ET « Aucune firme » : refus.
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $allowed->getId(), 'primaryFirmId' => $firm->getId(), 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // « Aucune firme » explicite sur un type autorisé : créée, sans réponse délégué.
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $allowed->getId(), 'withoutFirm' => true, 'representativePresent' => true]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $id = json_decode((string) $client->getResponse()->getContent(), true)['id'];

        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $id);
        self::assertNull($intervention->getPrimaryFirm());
        self::assertTrue($intervention->isWithoutFirm());
        self::assertNull($intervention->getRepresentativePresent(), 'aucune prestation de firme : jamais de réponse délégué');
        self::assertSame($instr->getId(), $intervention->getCreatedBy()?->getId());
        self::assertCount(0, $intervention->getMaterialLines(), 'aucun matériel ajouté');
        self::assertSame([], $this->requirements()->missingFor($intervention), 'complète sans firme');

        $entry = array_values(array_filter($this->getJson($client, "/api/missions/{$mission->getId()}/encoding", $token)['entries'], static fn (array $e) => $e['kind'] === 'INTERVENTION' && $e['id'] === $id))[0];
        self::assertTrue($entry['withoutFirm']);
        self::assertNull($entry['firm']);
    }

    // ── Correction ──────────────────────────────────────────────────────

    public function test_correction_switches_between_a_firm_and_without_firm_under_the_same_rules(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $allowed = $this->type('QUAD', allowedWithoutFirm: true);
        $otherAllowed = $this->type('BICEPS', allowedWithoutFirm: true);
        $notAllowed = $this->type('LCA');
        $firm = $this->firm('Arthrex');
        $withFirm = $this->intervention($mission, $allowed, $firm);
        $token = $this->login($client, $instr);
        $uri = "/api/missions/{$mission->getId()}/interventions/{$withFirm->getId()}";

        // Retirer la firme sans « Aucune firme » : toujours refusé (D-146 bis).
        $this->request($client, 'PATCH', $uri, $token, ['primaryFirmId' => null]);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $this->request($client, 'PATCH', $uri, $token, ['primaryFirmId' => null, 'withoutFirm' => true]);
        self::assertSame(204, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $i = $this->em->find(MissionIntervention::class, $withFirm->getId());
        self::assertNull($i->getPrimaryFirm());
        self::assertTrue($i->isWithoutFirm());

        // Nouveau type sur une intervention sans firme = nouvel encodage : contrôlé.
        $this->request($client, 'PATCH', $uri, $token, ['interventionTypeId' => $notAllowed->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->request($client, 'PATCH', $uri, $token, ['interventionTypeId' => $otherAllowed->getId()]);
        self::assertSame(204, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());

        // Revenir à une firme lève le marqueur.
        $this->request($client, 'PATCH', $uri, $token, ['primaryFirmId' => $firm->getId()]);
        self::assertSame(204, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $i = $this->em->find(MissionIntervention::class, $withFirm->getId());
        self::assertSame($firm->getId(), $i->getPrimaryFirm()?->getId());
        self::assertFalse($i->isWithoutFirm());

        // Type non autorisé : « Aucune firme » refusé en correction aussi.
        $other = $this->intervention($mission, $notAllowed, $firm);
        $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$other->getId()}", $token, ['primaryFirmId' => null, 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    // ── Paramétrage modifié ensuite ─────────────────────────────────────

    public function test_withdrawing_the_authorisation_keeps_recorded_interventions_valid_but_refuses_new_encodings(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $type = $this->type('QUAD', allowedWithoutFirm: true);
        $token = $this->login($client, $instr);
        $uri = "/api/missions/{$mission->getId()}/interventions";

        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $type->getId(), 'withoutFirm' => true]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $id = json_decode((string) $client->getResponse()->getContent(), true)['id'];

        $this->request($client, 'PATCH', "/api/intervention-types/{$type->getId()}", $this->login($client, $manager), ['allowedWithoutFirm' => false]);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $recorded = $this->em->find(MissionIntervention::class, $id);
        self::assertSame([], $this->requirements()->missingFor($recorded), 'validité historique conservée');
        $evaluation = $this->financial()->evaluate($this->em->find(Mission::class, $mission->getId()));
        self::assertNotContains('MISSING_PRIMARY_FIRM', array_map(static fn ($a) => $a->code, $evaluation->anomalies));
        self::assertSame(MissionFinancialEvaluation::WITHOUT_FIRM, $evaluation->outcome('MISSION_INTERVENTION:' . $id)['kind']);

        $token = $this->login($client, $instr);
        // Une correction qui ne touche ni la firme ni le type reste possible.
        $this->request($client, 'PATCH', "{$uri}/{$id}", $token, ['orderIndex' => 3]);
        self::assertSame(204, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        // Un nouvel encodage sans firme de ce type est refusé.
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $type->getId(), 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    // ── Calcul financier ────────────────────────────────────────────────

    public function test_authorised_without_firm_never_raises_missing_primary_firm_and_its_material_keeps_its_own_firm(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $type = $this->type('QUAD', allowedWithoutFirm: true);
        $materialFirm = $this->firm('Arthrex');
        $item = $this->item($materialFirm);
        $token = $this->login($client, $instr);

        $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, ['interventionTypeId' => $type->getId(), 'withoutFirm' => true]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $id = json_decode((string) $client->getResponse()->getContent(), true)['id'];
        $line = $this->materialLine($mission, $id, $item, $instr);

        $this->em->clear();
        $evaluation = $this->financial()->evaluate($this->em->find(Mission::class, $mission->getId()));
        $codes = array_map(static fn ($a) => $a->code, $evaluation->anomalies);
        self::assertNotContains('MISSING_PRIMARY_FIRM', $codes);
        self::assertSame(MissionFinancialEvaluation::WITHOUT_FIRM, $evaluation->outcome('MISSION_INTERVENTION:' . $id)['kind']);
        // Le matériel est évalué avec SA firme (ici sans tarif : anomalie tarif matériel, jamais « firme manquante »).
        $materialOutcome = $evaluation->outcome('MATERIAL_LINE:' . $line->getId());
        self::assertNotNull($materialOutcome);
        self::assertNotContains('MISSING_PRIMARY_FIRM', $materialOutcome['anomalyCodes']);
    }

    // ── Interventions historiques ───────────────────────────────────────

    public function test_historical_intervention_without_firm_is_never_validated_retroactively(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        // Même un type autorisé ensuite ne rend pas légitime une firme simplement absente.
        $type = $this->type('QUAD', allowedWithoutFirm: true);
        $legacy = $this->intervention($mission, $type, null);
        $token = $this->login($client, $instr);

        $this->em->clear();
        $loaded = $this->em->find(MissionIntervention::class, $legacy->getId());
        self::assertFalse($loaded->isWithoutFirm());
        self::assertContains(InterventionRequirementsService::MISSING_FIRM, $this->requirements()->missingFor($loaded));
        $evaluation = $this->financial()->evaluate($this->em->find(Mission::class, $mission->getId()));
        self::assertSame(['MISSING_PRIMARY_FIRM'], $evaluation->outcome('MISSION_INTERVENTION:' . $legacy->getId())['anomalyCodes']);

        // Toucher l'intervention sans répondre à la question de la firme ne la marque jamais.
        $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$legacy->getId()}", $token, ['orderIndex' => 2]);
        self::assertSame(204, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        self::assertFalse($this->em->find(MissionIntervention::class, $legacy->getId())->isWithoutFirm());
    }

    // ── Demandes au manager et résolution ───────────────────────────────

    public function test_request_without_firm_is_kept_and_resolved_with_the_same_controls(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $allowed = $this->type('QUAD', allowedWithoutFirm: true);
        $notAllowed = $this->type('LCA');
        $firm = $this->firm('Arthrex');
        $token = $this->login($client, $instr);
        $requestsUri = "/api/missions/{$mission->getId()}/intervention-type-requests";

        $this->request($client, 'POST', $requestsUri, $token, ['label' => 'Suture tendon quadricipital', 'withoutFirm' => true, 'requestedFirmId' => $firm->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'une firme ET « Aucune firme » : refus');

        $this->request($client, 'POST', $requestsUri, $token, ['label' => 'Suture tendon quadricipital', 'withoutFirm' => true]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($created['withoutFirm']);
        self::assertNull($created['requestedFirm']);
        $requestId = $created['id'];

        $managerToken = $this->login($client, $manager);
        $listed = array_values(array_filter($this->getJson($client, '/api/intervention-type-requests', $managerToken)['items'], static fn (array $r) => $r['id'] === $requestId));
        self::assertTrue($listed[0]['withoutFirm']);

        $resolveUri = "/api/intervention-type-requests/{$requestId}/resolve";
        // Une firme à la résolution : refus (l'information « sans firme » est conservée).
        $this->request($client, 'POST', $resolveUri, $managerToken, ['interventionTypeId' => $allowed->getId(), 'firmId' => $firm->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        // Type non autorisé sans firme : même contrôle qu'un encodage direct.
        $this->request($client, 'POST', $resolveUri, $managerToken, ['interventionTypeId' => $notAllowed->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $this->request($client, 'POST', $resolveUri, $managerToken, ['interventionTypeId' => $allowed->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $resolved = json_decode((string) $client->getResponse()->getContent(), true);
        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $resolved['missionInterventionId']);
        self::assertNull($intervention->getPrimaryFirm());
        self::assertTrue($intervention->isWithoutFirm());
        self::assertSame($instr->getId(), $intervention->getCreatedBy()?->getId(), 'auteur = auteur de la demande');
        self::assertSame([], $this->requirements()->missingFor($intervention));

        // Nouveau type créé à la résolution : autorisation sans firme explicite obligatoire.
        $this->request($client, 'POST', $requestsUri, $this->login($client, $instr), ['label' => 'Autre geste sans firme', 'withoutFirm' => true]);
        $secondId = json_decode((string) $client->getResponse()->getContent(), true)['id'];
        $code = 'SF178-' . strtoupper(bin2hex(random_bytes(3)));
        $this->createdTypeCodes[] = $code;
        $managerToken = $this->login($client, $manager);
        $this->request($client, 'POST', "/api/intervention-type-requests/{$secondId}/resolve", $managerToken, ['newInterventionType' => ['code' => $code, 'label' => 'Autre geste sans firme']]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNull($this->em->getRepository(InterventionType::class)->findOneBy(['code' => $code]), 'transaction annulée : aucun type créé');

        $this->request($client, 'POST', "/api/intervention-type-requests/{$secondId}/resolve", $managerToken, ['newInterventionType' => ['code' => $code, 'label' => 'Autre geste sans firme', 'allowedWithoutFirm' => true]]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        self::assertTrue($this->em->getRepository(InterventionType::class)->findOneBy(['code' => $code])->isAllowedWithoutFirm());
    }

    public function test_request_with_a_firm_is_unchanged_and_never_resolved_without_firm(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $allowed = $this->type('QUAD', allowedWithoutFirm: true);
        $firm = $this->firm('Arthrex');
        $token = $this->login($client, $instr);

        // Ni firme ni « Aucune firme » : toujours refusé (D-146 bis).
        $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, ['label' => 'Geste']);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, ['label' => 'Geste', 'requestedFirmId' => $firm->getId()]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $requestId = json_decode((string) $client->getResponse()->getContent(), true)['id'];

        // Demande faite avec une firme : la résoudre sans firme reste refusé, même sur un type autorisé.
        $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $this->login($client, $manager), ['interventionTypeId' => $allowed->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    // ── Droits et suggestions ───────────────────────────────────────────

    public function test_suggestions_without_firm_follow_the_mission_surgeon_and_the_current_catalogue(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $stranger = $this->user('ROLE_INSTRUMENTIST');
        $mission = $this->mission($surgeon, $instr, $site);
        $type = $this->type('QUAD', allowedWithoutFirm: true);
        $past = $this->mission($surgeon, $this->user('ROLE_INSTRUMENTIST'), $site);
        $this->intervention($past, $type, null, withoutFirm: true);
        // Firme simplement absente : jamais suggérée sous « Aucune firme ».
        $legacyType = $this->type('CLE', allowedWithoutFirm: true);
        $this->intervention($past, $legacyType, null);
        $uri = "/api/missions/{$mission->getId()}/encoding-suggestions/intervention-types?firmId=none";

        $this->request($client, 'GET', $uri, $this->login($client, $stranger));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $this->login($client, $stranger), ['interventionTypeId' => $type->getId(), 'withoutFirm' => true]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $token = $this->login($client, $instr);
        $suggested = $this->getJson($client, $uri, $token);
        self::assertNull($suggested['firmId']);
        self::assertSame([$type->getId()], array_column($suggested['interventionTypes'], 'id'));

        $this->request($client, 'PATCH', "/api/intervention-types/{$type->getId()}", $this->login($client, $manager), ['allowedWithoutFirm' => false]);
        self::assertSame([], $this->getJson($client, $uri, $this->login($client, $instr))['interventionTypes'], 'type retiré du catalogue sans firme : plus suggéré');
        // Une intervention sans firme n'alimente jamais les « firmes récentes ».
        self::assertSame([], $this->getJson($client, "/api/missions/{$mission->getId()}/encoding-suggestions/firms", $token)['firms']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function requirements(): InterventionRequirementsService
    {
        return static::getContainer()->get(InterventionRequirementsService::class);
    }

    private function financial(): FinancialCalculationService
    {
        return static::getContainer()->get(FinancialCalculationService::class);
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
        $u->setEmail('sansfirme178-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('SansFirme');
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
        $h->setName('SansFirme178Site-' . bin2hex(random_bytes(3)));
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

    private function type(string $code, bool $allowedWithoutFirm = false): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type ' . $code);
        $t->setAllowedWithoutFirm($allowedWithoutFirm);
        $this->em->persist($t); $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function item(Firm $firm): MaterialItem
    {
        $item = new MaterialItem();
        $item->setFirm($this->em->getReference(Firm::class, $firm->getId()));
        $item->setLabel('Ancre ' . bin2hex(random_bytes(2)));
        $item->setUnit('pièce');
        $item->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($item); $this->em->flush();
        $this->createdIds['items'][] = $item->getId();
        return $item;
    }

    private function mission(User $surgeon, User $instr, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($this->em->getReference(User::class, $surgeon->getId()));
        $m->setInstrumentist($this->em->getReference(User::class, $instr->getId()));
        $m->setSite($this->em->getReference(Hospital::class, $site->getId()));
        $m->setCreatedBy($this->em->getReference(User::class, $surgeon->getId()));
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-1 hour'));
        $m->setEndAt($now->modify('+2 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function intervention(Mission $mission, InterventionType $type, ?Firm $firm, bool $withoutFirm = false): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($this->em->getReference(Mission::class, $mission->getId()))
            ->setInterventionType($this->em->getReference(InterventionType::class, $type->getId()))
            ->setPrimaryFirm($firm !== null ? $this->em->getReference(Firm::class, $firm->getId()) : null)
            ->setWithoutFirm($withoutFirm)
            ->setCode((string) $type->getCode())
            ->setLabel((string) $type->getLabel())
            ->setOrderIndex(0);
        $this->em->persist($i);
        $this->em->flush();
        return $i;
    }

    private function materialLine(Mission $mission, int $interventionId, MaterialItem $item, User $by): MaterialLine
    {
        $l = new MaterialLine();
        $l->setCreatedBy($this->em->getReference(User::class, $by->getId()));
        $l->setMission($this->em->getReference(Mission::class, $mission->getId()));
        $l->setMissionIntervention($this->em->getReference(MissionIntervention::class, $interventionId));
        $l->setItem($this->em->getReference(MaterialItem::class, $item->getId()));
        $l->setQuantity('1');
        $this->em->persist($l);
        $this->em->flush();
        return $l;
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
