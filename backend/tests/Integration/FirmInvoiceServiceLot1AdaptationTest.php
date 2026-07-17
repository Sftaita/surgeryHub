<?php

namespace App\Tests\Integration;

use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FirmInvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Contrôle final Lot 1 (2026-07-16) — caractérise que FirmInvoiceService::preview()/
 * generate() fonctionnent réellement sur le nouveau modèle (interventionType,
 * MATERIAL_FEE, dates de validité), après suppression de toute référence à
 * PricingRuleType::IMPLANT_FEE / PricingRule::getInterventionCode(). Un simple grep ne
 * suffit pas — ces tests exécutent le code pour de vrai contre une base réelle.
 */
final class FirmInvoiceServiceLot1AdaptationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FirmInvoiceService $service;
    private array $created = [
        'invoices' => [], 'lines' => [], 'interventions' => [], 'missions' => [],
        'rules' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(FirmInvoiceService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->created['invoices'] as $id) { $e = $this->em->find(FirmInvoice::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['lines'] as $id) { $e = $this->em->find(MaterialLine::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
        foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
        foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['users'] as $id) { $e = $this->em->find(\App\Entity\User::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        parent::tearDown();
    }

    private function makeFirm(string $name): Firm
    {
        $f = new Firm();
        $f->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(string $code): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(3)));
        $t->setLabel($code);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm, bool $isImplant): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel('Item-' . bin2hex(random_bytes(3)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $mi->setIsImplant($isImplant);
        $this->em->persist($mi); $this->em->flush();
        $this->created['items'][] = $mi->getId();
        return $mi;
    }

    private function makeMission(\DateTimeImmutable $startAt): Mission
    {
        $site = new Hospital();
        $site->setName('Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $user = new \App\Entity\User();
        $user->setEmail('fis-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles(['ROLE_SURGEON']);
        $user->setActive(true);
        $user->setPassword('x');
        $this->em->persist($user); $this->em->flush();
        $this->created['users'][] = $user->getId();

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($user);
        $m->setCreatedBy($user);
        $m->setStartAt($startAt);
        $m->setEndAt($startAt->modify('+4 hours'));
        $m->setStatus(MissionStatus::VALIDATED);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    public function test_preview_bills_intervention_fee_and_material_fee_across_two_firms(): void
    {
        $today = new \DateTimeImmutable('today');
        $smithNephew = $this->makeFirm('S&N');
        $arthrex = $this->makeFirm('Arthrex');
        $lca = $this->makeType('LCA');
        $suture = $this->makeItem($arthrex, isImplant: false); // non-implant, doit quand même être facturable

        $forfait = new PricingRule();
        $forfait->setFirm($smithNephew);
        $forfait->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $forfait->setInterventionType($lca);
        $forfait->setUnitPrice('180.00');
        $this->em->persist($forfait); $this->em->flush();
        $this->created['rules'][] = $forfait->getId();

        $materialRule = new PricingRule();
        $materialRule->setFirm($arthrex);
        $materialRule->setRuleType(PricingRuleType::MATERIAL_FEE);
        $materialRule->setMaterialItem($suture);
        $materialRule->setUnitPrice('40.00');
        $this->em->persist($materialRule); $this->em->flush();
        $this->created['rules'][] = $materialRule->getId();

        $mission = $this->makeMission($today);

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($lca->getCode()); // rapprochement par code, MissionIntervention inchangée (Lot 5)
        $intervention->setLabel('LCA primaire');
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();

        $line = new MaterialLine();
        $line->setMission($mission);
        $line->setMissionIntervention($intervention);
        $line->setItem($suture);
        $line->setQuantity('4.00');
        $line->setCreatedBy($mission->getSurgeon());
        $this->em->persist($line); $this->em->flush();
        $this->created['lines'][] = $line->getId();

        $periodStart = $today->modify('-1 day');
        $periodEnd = $today->modify('+1 day');

        // $mission a été construit en mémoire via `new Mission()` : son constructeur
        // initialise déjà `interventions`/`materialLines` en ArrayCollection vide, que
        // Doctrine marque "initialisée" dès le persist(). Les entités enfants créées
        // ensuite (MissionIntervention, MaterialLine) ne mettent jamais à jour cette
        // collection en mémoire pour CETTE même instance PHP encore vivante dans
        // l'identity map — un fetch-join ultérieur sur cette instance ne la
        // re-peuple donc pas (artefact de test, sans impact en production où chaque
        // requête HTTP repart d'un EntityManager neuf). On force le rechargement.
        $this->em->clear();
        $smithNephew = $this->em->find(Firm::class, $smithNephew->getId());
        $arthrex = $this->em->find(Firm::class, $arthrex->getId());

        // ── Appel réel du service, pas un mock ──────────────────────────
        $snPreview = $this->service->preview($smithNephew, $periodStart, $periodEnd);
        self::assertCount(1, $snPreview['lines'], json_encode($snPreview));
        self::assertSame(180.0, $snPreview['totalAmount']);
        self::assertSame('INTERVENTION_FEE', $snPreview['lines'][0]['lineType']);

        $arthrexPreview = $this->service->preview($arthrex, $periodStart, $periodEnd);
        self::assertCount(1, $arthrexPreview['lines'], json_encode($arthrexPreview));
        self::assertSame(160.0, $arthrexPreview['totalAmount'], 'matériel non-implant facturé : isImplant ne filtre plus rien');
        self::assertSame('MATERIAL_FEE', $arthrexPreview['lines'][0]['lineType']);

        // ── generate() réellement exécuté (pas seulement preview) ───────
        $invoice = $this->service->generate(
            $smithNephew, $periodStart, $periodEnd,
            [$intervention->getId()], [],
        );
        $this->created['invoices'][] = $invoice->getId();
        self::assertSame(180.0, (float) $invoice->getTotalAmount());
        self::assertCount(1, $invoice->getLines());
    }

    public function test_expired_rule_does_not_bill(): void
    {
        $today = new \DateTimeImmutable('today');
        $firm = $this->makeFirm('Expired');
        $type = $this->makeType('PTE');

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('250.00');
        $rule->setValidTo($today->modify('-1 day')); // expirée hier
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $mission = $this->makeMission($today);
        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('PTE');
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();

        $preview = $this->service->preview($firm, $today->modify('-1 day'), $today->modify('+1 day'));

        self::assertSame([], $preview['lines'], 'une règle expirée ne doit jamais facturer');
    }
}
