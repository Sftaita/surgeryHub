<?php

namespace App\Tests\Functional;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\SiteGroup;
use App\Entity\SiteGroupMembership;
use App\Enum\MissionStatus;
use App\Enum\PlanningVersionStatus;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D-156 lot 1 — Mission is the single source of truth of the operational planning: a
 * mission created, published or kept from any screen must be the same everywhere.
 * Real HTTP, real DB. Each test pins one confirmed cause of "created in Missions, missing
 * in Planning V2" (or the opposite).
 */
final class MissionPlanningConsistencyTest extends WebTestCase
{
    use LivingPlanningFixturesTrait { tearDown as traitTearDown; }

    /** @var int[] */
    private array $groupIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    /**
     * Cause: `from`/`to` sent as instants (`Z`) were compared with Brussels wall-clock
     * columns without conversion — "today" also returned yesterday's late missions.
     */
    #[WithoutErrorHandler]
    public function test_list_range_given_as_utc_instants_is_compared_in_brussels_wall_clock(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $day     = $this->firstMonday();
        $eve     = $day->modify('-1 day');

        $lateEve = $this->createMission($client, $token, $site, $surgeon, $eve, '23:15', '23:45');
        $morning = $this->createMission($client, $token, $site, $surgeon, $day, '08:00', '13:00');

        // The browser's "this day" in Brussels, sent as UTC instants (what the Missions tab sends).
        $tz   = new \DateTimeZone('Europe/Brussels');
        $from = (new \DateTimeImmutable($day->format('Y-m-d') . ' 00:00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $to   = (new \DateTimeImmutable($day->modify('+1 day')->format('Y-m-d') . ' 00:00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $ids = $this->listIds($client, $token, sprintf('/api/missions?siteId=%d&from=%s&to=%s&limit=100', $site->getId(), $from, $to));

        self::assertContains($morning, $ids);
        self::assertNotContains($lateEve, $ids, 'A mission of the previous evening is not part of this day');
    }

    /** A bare date (no offset) is a Brussels calendar day — the published planning's "Du/Au". */
    #[WithoutErrorHandler]
    public function test_list_range_given_as_bare_dates_is_a_brussels_calendar_day(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $day     = $this->firstMonday();

        $id = $this->createMission($client, $token, $site, $surgeon, $day, '00:30', '01:30');

        $ids = $this->listIds($client, $token, sprintf('/api/missions?siteId=%d&from=%s&to=%s&limit=100', $site->getId(), $day->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d')));
        self::assertContains($id, $ids);

        $before = $this->listIds($client, $token, sprintf('/api/missions?siteId=%d&from=%s&to=%s&limit=100', $site->getId(), $day->modify('-1 day')->format('Y-m-d'), $day->format('Y-m-d')));
        self::assertNotContains($id, $before);
    }

    /** Cause: the wizard created (DRAFT) then dispatched in a second call — orphan drafts. */
    #[WithoutErrorHandler]
    public function test_create_with_pool_dispatch_publishes_in_the_same_request(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $day     = $this->firstMonday();

        $res = $this->api($client, $token, 'POST', '/api/missions', [
            'siteId' => $site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($day, '08:00'), 'endAt' => $this->at($day, '13:00'),
            'surgeonUserId' => $surgeon->getId(), 'dispatchMode' => 'POOL',
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $body = $this->body($res);
        self::assertSame('OPEN', $body['status']);

        // Visible in the published planning's query (DRAFT is the only status it hides).
        $ids = $this->listIds($client, $token, sprintf('/api/missions?siteId=%d&from=%s&to=%s&limit=100', $site->getId(), $day->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d')));
        self::assertContains((int) $body['id'], $ids);
    }

    #[WithoutErrorHandler]
    public function test_create_with_direct_dispatch_to_an_ineligible_instrumentist_creates_nothing(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $inst    = $this->makeSiteInstrumentist($site, 'Absente', 'Ce Jour');
        $day     = $this->firstMonday();
        $this->makeAbsence($inst, $day);

        $res = $this->api($client, $token, 'POST', '/api/missions', [
            'siteId' => $site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($day, '08:00'), 'endAt' => $this->at($day, '13:00'),
            'surgeonUserId' => $surgeon->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $inst->getId(),
        ]);
        self::assertGreaterThanOrEqual(400, $res->getStatusCode(), (string) $res->getContent());
        self::assertLessThan(500, $res->getStatusCode(), (string) $res->getContent());

        $this->em->clear();
        $count = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Mission m WHERE m.surgeon = :s')
            ->setParameter('s', $surgeon->getId())->getSingleScalarResult();
        self::assertSame(0, $count, 'A refused dispatch leaves no orphan DRAFT behind');
    }

    /**
     * Cause: generate() takes over a manual DRAFT of the same slot; deleting that undeployed
     * draft physically deleted the manual mission too — gone from Missions.
     */
    #[WithoutErrorHandler]
    public function test_deleting_an_undeployed_draft_keeps_the_manually_created_missions_it_took_over(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeMondayPost($surgeon, $site, $this->makeSiteInstrumentist($site, 'Anna', 'Poste'));
        $monday  = $this->firstMonday();

        $manualId = $this->createMission($client, $token, $site, $surgeon, $monday);

        $gen = $this->api($client, $token, 'POST', '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());
        $versionId = (int) $this->body($gen)['versionId'];

        $this->em->clear();
        self::assertSame($versionId, $this->em->find(Mission::class, $manualId)?->getPlanningVersion()?->getId(), 'precondition: generate() took the manual draft over');
        $generatedIds = array_map('intval', array_column($this->em->createQuery(
            'SELECT m.id FROM App\Entity\Mission m WHERE m.planningVersion = :v AND m.id != :manual'
        )->setParameter('v', $versionId)->setParameter('manual', $manualId)->getArrayResult(), 'id'));
        self::assertNotEmpty($generatedIds);

        $del = $this->api($client, $token, 'DELETE', "/api/planning/versions/{$versionId}");
        self::assertContains($del->getStatusCode(), [200, 204], (string) $del->getContent());

        $this->em->clear();
        $manual = $this->em->find(Mission::class, $manualId);
        self::assertNotNull($manual, 'The mission created in Missions still exists');
        self::assertSame(MissionStatus::DRAFT, $manual->getStatus());
        self::assertNull($manual->getPlanningVersion());
        foreach ($generatedIds as $id) {
            self::assertNull($this->em->find(Mission::class, $id), 'Generated draft missions are deleted as before');
        }
        self::assertNull($this->em->find(PlanningVersion::class, $versionId));
    }

    /** Cause: deploying group A archived group B's ACTIVE version of the same month. */
    #[WithoutErrorHandler]
    public function test_deploying_a_site_group_never_archives_another_groups_active_version(): void
    {
        $client  = $this->boot();
        $manager = $this->makeUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $siteA   = $this->makeSite();
        $siteB   = $this->makeSite();
        $groupA  = $this->makeGroup([$siteA], $manager);
        $groupB  = $this->makeGroup([$siteB], $manager);
        $this->makeMondayPost($this->makeUser('ROLE_SURGEON'), $siteA, $this->makeSiteInstrumentist($siteA, 'A', 'Inst'));
        $this->makeMondayPost($this->makeUser('ROLE_SURGEON'), $siteB, $this->makeSiteInstrumentist($siteB, 'B', 'Inst'));

        $versionA = $this->generateAndDeployGroup($client, $token, $groupA);
        $versionB = $this->generateAndDeployGroup($client, $token, $groupB);

        $this->em->clear();
        self::assertSame(PlanningVersionStatus::ACTIVE, $this->em->find(PlanningVersion::class, $versionA)?->getStatus(), 'Group A stays ACTIVE');
        self::assertSame(PlanningVersionStatus::ACTIVE, $this->em->find(PlanningVersion::class, $versionB)?->getStatus());

        // Redeploying group A replaces group A's version only.
        $versionA2 = $this->generateAndDeployGroup($client, $token, $groupA);
        $this->em->clear();
        self::assertSame(PlanningVersionStatus::ARCHIVED, $this->em->find(PlanningVersion::class, $versionA)?->getStatus());
        self::assertSame(PlanningVersionStatus::ACTIVE, $this->em->find(PlanningVersion::class, $versionA2)?->getStatus());
        self::assertSame(PlanningVersionStatus::ACTIVE, $this->em->find(PlanningVersion::class, $versionB)?->getStatus());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return int[] */
    private function listIds(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token, string $uri): array
    {
        $res = $this->api($client, $token, 'GET', $uri);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        return array_map(static fn (array $i) => (int) $i['id'], $this->body($res)['items']);
    }

    /** @param \App\Entity\Hospital[] $sites */
    private function makeGroup(array $sites, \App\Entity\User $creator): SiteGroup
    {
        $g = new SiteGroup();
        $g->setName('D156 Groupe ' . bin2hex(random_bytes(3)));
        $g->setCreatedBy($this->ref($creator));
        $this->em->persist($g);
        foreach ($sites as $site) {
            $m = new SiteGroupMembership();
            $m->setGroup($g);
            $m->setSite($this->ref($site));
            $this->em->persist($m);
        }
        $this->em->flush();
        $this->groupIds[] = (int) $g->getId();

        return $g;
    }

    private function generateAndDeployGroup(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token, SiteGroup $group): int
    {
        $gen = $this->api($client, $token, 'POST', '/api/planning/v2/generate', [
            'siteId' => null, 'siteGroupId' => $group->getId(), 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());
        $versionId = (int) $this->body($gen)['versionId'];

        $dep = $this->api($client, $token, 'POST', '/api/planning/v2/deploy', ['planningVersionId' => $versionId]);
        self::assertSame(200, $dep->getStatusCode(), (string) $dep->getContent());

        return $versionId;
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen() && $this->groupIds !== []) {
            $this->em->clear();
            // Versions first (they reference the group), then the group itself.
            $users = $this->fx['users'] ?: [0];
            $missionIds = array_map('intval', array_column($this->em->createQuery(
                'SELECT m.id FROM App\Entity\Mission m WHERE m.planningVersion IN (SELECT v.id FROM App\Entity\PlanningVersion v WHERE v.siteGroup IN (:g))'
            )->setParameter('g', $this->groupIds)->getArrayResult(), 'id'));
            if ($missionIds !== []) {
                $this->em->createQuery('UPDATE App\Entity\Mission m SET m.planningVersion = NULL WHERE m.id IN (:m)')->setParameter('m', $missionIds)->execute();
            }
            $this->em->createQuery('DELETE FROM App\Entity\PlanningDeployment d WHERE d.deployedBy IN (:u)')->setParameter('u', $users)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\PlanningVersion v WHERE v.siteGroup IN (:g)')->setParameter('g', $this->groupIds)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\SiteGroupMembership m WHERE m.group IN (:g)')->setParameter('g', $this->groupIds)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\SiteGroup g WHERE g.id IN (:g)')->setParameter('g', $this->groupIds)->execute();
        }
        $this->traitTearDown();
    }
}
