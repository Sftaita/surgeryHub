<?php

namespace App\Tests\Functional;

use App\Dto\MissionFinancialEvaluation;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\PricingRule;
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
 * D-183 — interventions sans firme : une intervention (InterventionType) existe sans
 * rattachement à une firme ; son rattachement est une prestation firme × type. Jamais de firme
 * fictive, jamais de prestation sans firme ni de tarif inventé. « Interventions sans firme » et
 * la sélection « Aucune firme » sont calculées à partir des rattachements effectifs (prestation
 * active d'une firme active), plus l'autorisation manuelle D-178 pour la sélection.
 */
final class InterventionWithoutFirmCatalogueTest extends WebTestCase
{
    private const PASSWORD = 'SansFirme183!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => []];
    /** @var list<string> codes des interventions créées par l'API */
    private array $codes = [];

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
                $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$id]);
            }
            foreach ($this->codes as $code) {
                $conn->executeStatement('DELETE o FROM firm_service_offering o JOIN intervention_type t ON t.id = o.intervention_type_id WHERE t.code = ?', [$code]);
                $conn->executeStatement('DELETE FROM intervention_type WHERE code = ?', [$code]);
            }
            foreach ($this->createdIds['firms'] as $id) {
                $conn->executeStatement('DELETE FROM firm_service_offering WHERE firm_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM firm WHERE id = ?', [$id]);
            }
            foreach ($this->createdIds['users'] as $id) {
                $conn->executeStatement('DELETE FROM refresh_tokens WHERE username = (SELECT email FROM user WHERE id = ?)', [$id]);
                $conn->executeStatement('DELETE FROM user WHERE id = ?', [$id]);
            }
            foreach ($this->createdIds['sites'] as $id) {
                $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
            }
        }
        parent::tearDown();
    }

    // ── 1, 2, 10 — Prestations : création sans firme, catégorie, doublons ─────────

    public function test_creation_without_firm_creates_only_the_intervention_and_lists_it_without_firm(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firmsBefore = $this->rowCount(Firm::class);
        $code = $this->code('RAC');

        $this->request($client, 'POST', '/api/intervention-types', $token, ['code' => $code, 'label' => 'Résection acromio-claviculaire', 'specialty' => 'Épaule', 'firmId' => null]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($created['active']);
        self::assertSame(0, $created['activeFirmsCount']);
        self::assertTrue($created['withoutFirm']);
        self::assertTrue($created['selectableWithoutFirm']);
        self::assertFalse($created['allowedWithoutFirm'], 'aucune autorisation D-178 implicite');

        $this->em->clear();
        self::assertSame(0, $this->offeringsOf($created['id']), 'aucune prestation');
        self::assertSame($firmsBefore, $this->rowCount(Firm::class), 'aucune firme fictive');
        self::assertSame(0, $this->pricingRulesOf($created['id']), 'aucun tarif');

        $listed = $this->listed($client, $token, $created['id']);
        self::assertTrue($listed['withoutFirm']);
        self::assertSame(0, $listed['activeFirmsCount']);

        // 10 — même code : jamais de doublon, l'intervention existante est renvoyée pour être réutilisée.
        $this->request($client, 'POST', '/api/intervention-types', $token, ['code' => $code, 'label' => 'Autre libellé']);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $error = json_decode((string) $client->getResponse()->getContent(), true)['error'];
        self::assertSame('INTERVENTION_TYPE_CODE_EXISTS', $error['code']);
        self::assertSame($created['id'], $error['existingInterventionType']['id']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM intervention_type WHERE code = ?', [$code]));
    }

    // ── 3 — Prestations : création avec une firme ───────────────────────────

    public function test_creation_with_a_firm_creates_the_intervention_and_its_link_in_one_transaction(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $arthrex = $this->firm('Arthrex');

        $code = $this->code('RAC');
        $this->request($client, 'POST', '/api/intervention-types', $token, ['code' => $code, 'label' => 'Résection acromio-claviculaire', 'firmId' => $arthrex->getId()]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $created['activeFirmsCount']);
        self::assertFalse($created['withoutFirm']);
        self::assertFalse($created['selectableWithoutFirm']);
        $this->em->clear();
        $offering = $this->em->getRepository(FirmServiceOffering::class)->findOneBy(['interventionType' => $created['id']]);
        self::assertSame($arthrex->getId(), $offering->getFirm()->getId());
        self::assertTrue($offering->isActive());
        self::assertTrue($offering->isFeeApplicable(), 'paramètres par défaut d\'une prestation créée depuis la firme');
        self::assertSame(0, $this->pricingRulesOf($created['id']), 'aucun montant inventé');

        // Firme désactivée ou inconnue : rien n'est créé (ni l'intervention, ni le rattachement).
        $inactive = $this->firm('Ancienne', active: false);
        foreach ([[$inactive->getId(), 422], [999999999, 404]] as [$firmId, $status]) {
            $failed = $this->code('RAC');
            $this->request($client, 'POST', '/api/intervention-types', $token, ['code' => $failed, 'label' => 'Échec', 'firmId' => $firmId]);
            self::assertSame($status, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM intervention_type WHERE code = ?', [$failed]));
        }
    }

    // ── 4, 5, 6 — rattachements ultérieurs, plusieurs firmes, retrait du dernier ─

    public function test_links_are_added_and_removed_without_ever_duplicating_the_intervention(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $arthrex = $this->firm('Arthrex');
        $smith = $this->firm('Smith & Nephew');
        $id = $this->createType($client, $token, 'Résection acromio-claviculaire');

        // 4 — rattachement ultérieur à Arthrex : même intervention, plus dans « sans firme ».
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $arthrex->getId()]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $arthrexLink = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($id, $arthrexLink['interventionType']['id']);
        self::assertFalse($arthrexLink['interventionType']['withoutFirm']);
        self::assertFalse($this->listed($client, $token, $id)['withoutFirm']);
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $arthrex->getId()]);
        self::assertSame(409, $client->getResponse()->getStatusCode(), 'déjà rattachée : jamais de doublon');

        // 5 — deuxième firme.
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $smith->getId()]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $smithLink = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(2, $this->listed($client, $token, $id)['activeFirmsCount']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM intervention_type WHERE id = ?', [$id]));

        // 6 — retrait (désactivation, jamais suppression) ; le dernier ramène dans « sans firme ».
        $this->request($client, 'DELETE', "/api/intervention-types/{$id}/firm-links/{$arthrexLink['offeringId']}", $token);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['interventionType']['withoutFirm']);
        $this->request($client, 'DELETE', "/api/intervention-types/{$id}/firm-links/{$smithLink['offeringId']}", $token);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['interventionType']['withoutFirm']);
        self::assertTrue($this->listed($client, $token, $id)['withoutFirm']);
        $this->em->clear();
        self::assertSame(2, $this->offeringsOf($id), 'prestations conservées (historique, tarifs)');
        self::assertNotNull($this->em->find(InterventionType::class, $id), 'l\'intervention globale reste');

        // Rattacher à nouveau : la prestation retirée est réactivée, jamais dupliquée.
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $arthrex->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $again = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($again['reactivated']);
        self::assertSame($arthrexLink['offeringId'], $again['offeringId']);
        self::assertSame(2, $this->offeringsOf($id));

        // Une prestation d'une autre intervention ne peut pas être retirée par ce chemin.
        $other = $this->createType($client, $token, 'Autre intervention');
        $this->request($client, 'DELETE', "/api/intervention-types/{$other}/firm-links/{$again['offeringId']}", $token);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Statut global : une intervention désactivée n'est plus « sans firme » ni sélectionnable.
        $this->request($client, 'DELETE', "/api/intervention-types/{$id}/firm-links/{$again['offeringId']}", $token);
        $this->request($client, 'PATCH', "/api/intervention-types/{$id}", $token, ['active' => false]);
        $patched = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($patched['withoutFirm']);
        self::assertFalse($patched['selectableWithoutFirm']);
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $arthrex->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'intervention désactivée : à réactiver d\'abord');
        $this->request($client, 'PATCH', "/api/intervention-types/{$id}", $token, ['active' => true]);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['withoutFirm']);

        // Une firme désactivée n'est pas un rattachement effectif.
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $smith->getId()]);
        self::assertFalse($this->listed($client, $token, $id)['withoutFirm']);
        $this->em->getConnection()->executeStatement('UPDATE firm SET active = 0 WHERE id = ?', [$smith->getId()]);
        self::assertTrue($this->listed($client, $token, $id)['withoutFirm']);
    }

    public function test_a_later_link_never_changes_the_manual_d178_authorisation(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $id = $this->createType($client, $token, 'Plastie du quadriceps');
        $this->request($client, 'PATCH', "/api/intervention-types/{$id}", $token, ['allowedWithoutFirm' => true]);

        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $this->firm('Arthrex')->getId()]);
        $linked = json_decode((string) $client->getResponse()->getContent(), true)['interventionType'];
        self::assertTrue($linked['allowedWithoutFirm'], 'autorisation manuelle conservée');
        self::assertFalse($linked['withoutFirm'], 'plus dans « Interventions sans firme »');
        self::assertTrue($linked['selectableWithoutFirm'], 'toujours proposée « Aucune firme » (autorisée)');
    }

    // ── 7, 8, 9 — Demandes → Interventions demandées ─────────────────────────

    public function test_a_request_is_resolved_without_firm_with_a_new_intervention_and_keeps_its_link(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $arthrex = $this->firm('Arthrex');

        // L'instrumentiste a indiqué une firme ; le manager constate qu'il n'y en avait pas.
        $requestId = $this->interventionRequest($client, $instr, $mission, ['label' => 'Résection acromio-claviculaire', 'requestedFirmId' => $arthrex->getId()]);
        $code = $this->code('RAC');
        $managerToken = $this->login($client, $manager);
        $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'withoutFirm' => true,
            'newInterventionType' => ['code' => $code, 'label' => 'Résection acromio-claviculaire'],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $resolved = json_decode((string) $client->getResponse()->getContent(), true);

        $this->em->clear();
        $type = $this->em->getRepository(InterventionType::class)->findOneBy(['code' => $code]);
        $request = $this->em->find(InterventionTypeRequest::class, $requestId);
        self::assertSame(InterventionTypeRequest::STATUS_RESOLVED, $request->getStatus());
        self::assertSame($type->getId(), $request->getResolvedInterventionType()?->getId(), 'lien demande → intervention conservé');
        self::assertSame(0, $this->offeringsOf((int) $type->getId()), 'aucune prestation');
        self::assertFalse($type->isAllowedWithoutFirm());
        $intervention = $this->em->find(MissionIntervention::class, $resolved['missionInterventionId']);
        self::assertNull($intervention->getPrimaryFirm());
        self::assertTrue($intervention->isWithoutFirm());
        self::assertSame([], $this->requirements()->missingFor($intervention));
        self::assertTrue($this->listed($client, $managerToken, (int) $type->getId())['withoutFirm'], 'immédiatement dans « Interventions sans firme »');

        // Firme demandée conservée, distincte de la résolution effective.
        $row = $this->requestRow($client, $managerToken, $requestId);
        self::assertSame($arthrex->getId(), $row['requestedFirm']['id']);
        self::assertSame(['withoutFirm' => true, 'firm' => null], $row['resolution']);

        // Même demande résolue avec une firme : « associée à » cette firme.
        $withFirmId = $this->interventionRequest($client, $instr, $mission, ['label' => 'Autre geste', 'requestedFirmId' => $arthrex->getId()]);
        $this->request($client, 'POST', "/api/intervention-type-requests/{$withFirmId}/resolve", $managerToken = $this->login($client, $manager), [
            'interventionTypeId' => $type->getId(), 'firmId' => $arthrex->getId(), 'offeringDecision' => 'LEAVE_UNCONFIGURED',
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $row = $this->requestRow($client, $managerToken, $withFirmId);
        self::assertSame(['withoutFirm' => false, 'firm' => ['id' => $arthrex->getId(), 'name' => $arthrex->getName()]], $row['resolution']);
        self::assertNull($this->requestRow($client, $managerToken, $this->interventionRequest($client, $instr, $mission, ['label' => 'En attente', 'withoutFirm' => true]), 'PENDING')['resolution']);
    }

    /** @return array<string, mixed> la demande telle que listée au manager */
    private function requestRow(KernelBrowser $client, string $token, int $id, string $status = 'RESOLVED'): array
    {
        $rows = array_values(array_filter($this->getJson($client, '/api/intervention-type-requests?status=' . $status, $token)['items'], static fn (array $r) => $r['id'] === $id));
        self::assertCount(1, $rows);
        return $rows[0];
    }

    public function test_a_failed_resolution_leaves_the_request_pending_and_the_existing_intervention_is_reused(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $managerToken = $this->login($client, $manager);
        $existing = $this->createType($client, $managerToken, 'Résection acromio-claviculaire');
        $existingCode = $this->em->find(InterventionType::class, $existing)->getCode();
        $typesBefore = $this->rowCount(InterventionType::class);

        $requestId = $this->interventionRequest($client, $instr, $mission, ['label' => 'Résection AC', 'withoutFirm' => true]);
        $resolveUri = "/api/intervention-type-requests/{$requestId}/resolve";

        // 9 — code déjà utilisé : refus, rien n'est créé ni résolu.
        $this->request($client, 'POST', $resolveUri, $this->login($client, $manager), ['newInterventionType' => ['code' => $existingCode, 'label' => 'Doublon']]);
        self::assertSame(409, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertSame($existing, json_decode((string) $client->getResponse()->getContent(), true)['error']['existingInterventionType']['id']);
        $this->em->clear();
        self::assertSame(InterventionTypeRequest::STATUS_PENDING, $this->em->find(InterventionTypeRequest::class, $requestId)->getStatus());
        self::assertSame(MissionInterventionDraft::STATUS_OPEN, $this->em->find(InterventionTypeRequest::class, $requestId)->getDraft()->getStatus());
        self::assertSame($typesBefore, $this->rowCount(InterventionType::class));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$mission->getId()]));

        // 10 — réutilisation de l'intervention existante.
        $this->request($client, 'POST', $resolveUri, $this->login($client, $manager), ['interventionTypeId' => $existing]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame($existing, $this->em->find(InterventionTypeRequest::class, $requestId)->getResolvedInterventionType()?->getId());
        self::assertSame($typesBefore, $this->rowCount(InterventionType::class));
    }

    // ── 11, 12, 13 — encodage, facturation, pas de rétroactivité ─────────────

    public function test_an_intervention_without_firm_is_encoded_without_billing_and_a_later_link_never_rewrites_it(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $managerToken = $this->login($client, $manager);
        $id = $this->createType($client, $managerToken, 'Résection acromio-claviculaire');

        // 11 — proposée sous « Aucune firme » et encodée sans firme.
        $token = $this->login($client, $instr);
        $catalogue = $this->getJson($client, "/api/missions/{$mission->getId()}/encoding", $token)['catalog']['interventionTypes'];
        $entry = array_values(array_filter($catalogue, static fn (array $t) => $t['id'] === $id))[0];
        self::assertTrue($entry['selectableWithoutFirm']);
        self::assertFalse($entry['allowedWithoutFirm']);
        $uri = "/api/missions/{$mission->getId()}/interventions";
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $id, 'withoutFirm' => true]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $interventionId = json_decode((string) $client->getResponse()->getContent(), true)['id'];

        // 12 — ni prestation, ni tarif, ni facturation à une firme.
        $this->em->clear();
        self::assertSame(0, $this->offeringsOf($id));
        self::assertSame(0, $this->pricingRulesOf($id));
        $evaluation = $this->financial()->evaluate($this->em->find(Mission::class, $mission->getId()));
        self::assertNotContains('MISSING_PRIMARY_FIRM', array_map(static fn ($a) => $a->code, $evaluation->anomalies));
        self::assertSame(MissionFinancialEvaluation::WITHOUT_FIRM, $evaluation->outcome('MISSION_INTERVENTION:' . $interventionId)['kind']);

        // 13 — rattachement ultérieur à Arthrex : l'encodage enregistré n'est jamais réécrit.
        $arthrex = $this->firm('Arthrex');
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $this->login($client, $manager), ['firmId' => $arthrex->getId()]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $recorded = $this->em->find(MissionIntervention::class, $interventionId);
        self::assertNull($recorded->getPrimaryFirm(), 'aucune firme attribuée rétroactivement');
        self::assertTrue($recorded->isWithoutFirm());
        self::assertSame([], $this->requirements()->missingFor($recorded));
        $evaluation = $this->financial()->evaluate($this->em->find(Mission::class, $mission->getId()));
        self::assertSame(MissionFinancialEvaluation::WITHOUT_FIRM, $evaluation->outcome('MISSION_INTERVENTION:' . $interventionId)['kind']);

        // Nouvel encodage : désormais avec sa firme (plus proposée « Aucune firme »).
        $token = $this->login($client, $instr);
        $catalogue = $this->getJson($client, "/api/missions/{$mission->getId()}/encoding", $token)['catalog']['interventionTypes'];
        self::assertFalse(array_values(array_filter($catalogue, static fn (array $t) => $t['id'] === $id))[0]['selectableWithoutFirm']);
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $id, 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->request($client, 'POST', $uri, $token, ['interventionTypeId' => $id, 'primaryFirmId' => $arthrex->getId()]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
    }

    // ── 14 — droits ─────────────────────────────────────────────────────────

    public function test_only_managers_and_admins_create_link_and_unlink_interventions(): void
    {
        $client = $this->boot();
        [$surgeon, $instr] = $this->people();
        $arthrex = $this->firm('Arthrex');
        $managerToken = $this->login($client, $this->user('ROLE_MANAGER'));
        $id = $this->createType($client, $managerToken, 'Résection acromio-claviculaire');
        $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $managerToken, ['firmId' => $arthrex->getId()]);
        $offeringId = json_decode((string) $client->getResponse()->getContent(), true)['offeringId'];

        foreach ([$surgeon, $instr] as $user) {
            $token = $this->login($client, $user);
            $code = $this->code('RAC');
            $this->request($client, 'POST', '/api/intervention-types', $token, ['code' => $code, 'label' => 'Interdit']);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $this->request($client, 'POST', "/api/intervention-types/{$id}/firm-links", $token, ['firmId' => $arthrex->getId()]);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $this->request($client, 'DELETE', "/api/intervention-types/{$id}/firm-links/{$offeringId}", $token);
            self::assertSame(403, $client->getResponse()->getStatusCode());
        }
        $this->request($client, 'DELETE', "/api/intervention-types/{$id}/firm-links/{$offeringId}", null);
        self::assertSame(401, $client->getResponse()->getStatusCode());

        $adminToken = $this->login($client, $this->user('ROLE_ADMIN'));
        $this->request($client, 'DELETE', "/api/intervention-types/{$id}/firm-links/{$offeringId}", $adminToken);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['interventionType']['withoutFirm']);
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

    private function code(string $prefix): string
    {
        $code = 'SF183-' . $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
        $this->codes[] = $code;
        return $code;
    }

    /** Intervention créée « Aucune firme » par l'API (Catalogue › Prestations). */
    private function createType(KernelBrowser $client, string $token, string $label): int
    {
        $this->request($client, 'POST', '/api/intervention-types', $token, ['code' => $this->code('T'), 'label' => $label]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        return json_decode((string) $client->getResponse()->getContent(), true)['id'];
    }

    /** @return array<string, mixed> l'entrée de la liste du catalogue pour ce type */
    private function listed(KernelBrowser $client, string $token, int $id): array
    {
        $rows = array_values(array_filter($this->getJson($client, '/api/intervention-types', $token), static fn (array $t) => $t['id'] === $id));
        self::assertCount(1, $rows);
        return $rows[0];
    }

    private function interventionRequest(KernelBrowser $client, User $instr, Mission $mission, array $body): int
    {
        $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $this->login($client, $instr), $body);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        return json_decode((string) $client->getResponse()->getContent(), true)['id'];
    }

    private function offeringsOf(int $typeId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM firm_service_offering WHERE intervention_type_id = ?', [$typeId]);
    }

    private function pricingRulesOf(int $typeId): int
    {
        return (int) $this->em->getRepository(PricingRule::class)->count(['interventionType' => $typeId]);
    }

    /** @param class-string $class */
    private function rowCount(string $class): int
    {
        return (int) $this->em->getRepository($class)->count([]);
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
        $u->setEmail('sansfirme183-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('SansFirme183Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function firm(string $name, bool $active = true): Firm
    {
        $f = new Firm();
        $f->setName($name . '-' . bin2hex(random_bytes(3)));
        $f->setActive($active);
        $this->em->persist($f); $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
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
