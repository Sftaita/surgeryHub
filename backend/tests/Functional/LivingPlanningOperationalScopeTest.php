<?php

namespace App\Tests\Functional;

use App\Entity\Mission;
use App\Enum\MissionStatus;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D-125 — « planning vivant » : the three origins of a Mission (before generation, by
 * generation, after generation) must all be part of the same operational calendar, and
 * generate() must never overwrite a pre-existing operational Mission.
 *
 * Root cause covered here: every "calendar of a generated month" read (Mode Modification
 * list, coverage KPI, apply-modifications) used to filter on PlanningVersion PROVENANCE
 * (`Mission.planningVersion = V`), so a Mission generate() never created — manual,
 * accepted surgeon request, or deliberately left untouched by R-01 — was invisible.
 */
final class LivingPlanningOperationalScopeTest extends WebTestCase
{
    use LivingPlanningFixturesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    /** Scenario 1 — a Mission created BEFORE generation stays intact and is part of the planning. */
    #[WithoutErrorHandler]
    public function test_mission_created_before_generation_is_seen_by_preview_kept_intact_and_shown_in_calendar(): void
    {
        $client  = $this->boot();
        $manager = $this->makeUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Paul', 'Chir');
        $postDefault = $this->makeSiteInstrumentist($site, 'Default', 'Poste');
        $agreed      = $this->makeSiteInstrumentist($site, 'Salve', 'Decorte');
        $this->makeMondayPost($surgeon, $site, $postDefault);
        $monday = $this->firstMonday();

        // Created manually weeks before the month is generated, already assigned to someone
        // other than the post's default instrumentist.
        $manualId = $this->createMission($client, $token, $site, $surgeon, $monday);
        $res = $this->api($client, $token, 'POST', "/api/missions/{$manualId}/assign-directly", ['instrumentistId' => $agreed->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        // Preview takes that reality into account: same slot → the existing mission, shown as
        // it really is (never "MODIFIED to the post default", which generate() would ignore).
        $preview = $this->body($this->api($client, $token, 'POST', '/api/planning/v2/preview', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]));
        $line = current(array_filter($preview['lines'], fn (array $l) => $l['date'] === $monday->format('Y-m-d')));
        self::assertNotFalse($line, 'The Monday occurrence must be in the preview');
        self::assertSame($manualId, $line['existingMissionId']);
        self::assertSame('ASSIGNED', $line['existingMissionStatus']);
        self::assertSame('COVERED', $line['status']);
        self::assertSame($agreed->getId(), $line['instrumentistId'], 'The line reflects the existing assignment, not the post default');

        $versionId = $this->generateAndDeploy($client, $token, $site);

        // Same ID, same business state, provenance untouched (never re-parented).
        $this->em->clear();
        $manual = $this->em->find(Mission::class, $manualId);
        self::assertSame(MissionStatus::ASSIGNED, $manual->getStatus());
        self::assertSame($agreed->getId(), $manual->getInstrumentist()?->getId());
        self::assertNull($manual->getPlanningVersion(), 'generate() never adopts an already-operational mission');

        // Never duplicated: exactly one live mission for that surgeon/site/Monday.
        $sameSlot = $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Mission m WHERE m.surgeon = :s AND m.site = :site AND m.startAt >= :from AND m.startAt < :to AND m.status != :cancelled'
        )->setParameters([
            's' => $surgeon, 'site' => $site, 'cancelled' => MissionStatus::CANCELLED,
            'from' => new \DateTimeImmutable($monday->format('Y-m-d') . ' 00:00:00'),
            'to'   => new \DateTimeImmutable($monday->format('Y-m-d') . ' 23:59:59'),
        ])->getSingleScalarResult();
        self::assertSame(1, (int) $sameSlot);

        // It appears in the manager's calendar of the generated month.
        $calendar = $this->calendar($client, $token, $versionId);
        self::assertArrayHasKey($manualId, $calendar);
        self::assertSame('ASSIGNED', $calendar[$manualId]['status']);

        // …and the coverage KPI counts it like any generated mission.
        $coverage = $this->body($this->api($client, $token, 'GET', "/api/planning/versions/{$versionId}/coverage-summary"));
        self::assertSame(count($calendar), $coverage['total']);
        self::assertSame($coverage['total'], $coverage['covered']);
    }

    /** Scenario 2 — a Mission created AFTER generation appears without regenerating. */
    #[WithoutErrorHandler]
    public function test_mission_created_after_generation_appears_in_calendar_without_regeneration(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $inst    = $this->makeSiteInstrumentist($site, 'Anna', 'Poste');
        $this->makeMondayPost($surgeon, $site, $inst);

        $versionId = $this->generateAndDeploy($client, $token, $site);
        $before    = $this->calendar($client, $token, $versionId);
        $coverageBefore = $this->body($this->api($client, $token, 'GET', "/api/planning/versions/{$versionId}/coverage-summary"));

        $tuesday = $this->firstMonday()->modify('+1 day');
        $otherSurgeon = $this->makeUser('ROLE_SURGEON');
        $newId = $this->createMission($client, $token, $site, $otherSurgeon, $tuesday);
        $other = $this->makeSiteInstrumentist($site, 'Bob', 'Direct');
        $res = $this->api($client, $token, 'POST', "/api/missions/{$newId}/assign-directly", ['instrumentistId' => $other->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $after = $this->calendar($client, $token, $versionId);
        self::assertArrayNotHasKey($newId, $before);
        self::assertArrayHasKey($newId, $after, 'A mission added after generation belongs to the operational calendar');
        self::assertCount(count($before) + 1, $after);

        // Provenance filter is unchanged: the version did not generate it.
        $provenance = $this->body($this->api($client, $token, 'GET', "/api/missions?planningVersionId={$versionId}&limit=100"));
        self::assertNotContains($newId, array_map(fn ($i) => (int) $i['id'], $provenance['items']));

        $coverageAfter = $this->body($this->api($client, $token, 'GET', "/api/planning/versions/{$versionId}/coverage-summary"));
        self::assertSame($coverageBefore['total'] + 1, $coverageAfter['total']);
        self::assertSame($coverageBefore['covered'] + 1, $coverageAfter['covered']);
    }

    /** Scenario 3 — surgeon request accepted after generation, "proposer au pool". */
    #[WithoutErrorHandler]
    public function test_surgeon_request_accepted_to_pool_after_generation_is_open_and_in_calendar(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeMondayPost($surgeon, $site, $this->makeSiteInstrumentist($site, 'Anna', 'Poste'));
        $versionId = $this->generateAndDeploy($client, $token, $site);

        $requester = $this->makeUser('ROLE_SURGEON', 'Req', 'Uester');
        $request   = $this->makePendingRequest($requester, $site, $this->firstMonday()->modify('+2 days'));

        $res = $this->api($client, $token, 'POST', "/api/manager/surgeon-mission-requests/{$request->getId()}/accept", [
            'dispatch' => ['mode' => 'POOL'],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $accepted = $this->body($res);
        self::assertSame('ACCEPTED', $accepted['status']);
        self::assertSame('OPEN', $accepted['createdMissionStatus']);

        $calendar = $this->calendar($client, $token, $versionId);
        self::assertArrayHasKey($accepted['createdMissionId'], $calendar);
        self::assertSame('OPEN', $calendar[$accepted['createdMissionId']]['status']);
        self::assertFalse($calendar[$accepted['createdMissionId']]['covered']);
    }

    /** Scenario 5 — a pre-existing mission already occupies the post's instrumentist. */
    #[WithoutErrorHandler]
    public function test_preexisting_mission_occupying_the_instrumentist_is_a_conflict_never_a_double_booking(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $busy    = $this->makeSiteInstrumentist($site, 'Occupee', 'Ailleurs');
        $this->makeMondayPost($surgeon, $site, $busy);
        $monday  = $this->firstMonday();

        // Another surgeon's mission, created before generation, already holds her 09:00–12:00.
        $otherSurgeon = $this->makeUser('ROLE_SURGEON');
        $existingId = $this->createMission($client, $token, $site, $otherSurgeon, $monday, '09:00', '12:00');
        $res = $this->api($client, $token, 'POST', "/api/missions/{$existingId}/assign-directly", ['instrumentistId' => $busy->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $preview = $this->body($this->api($client, $token, 'POST', '/api/planning/v2/preview', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]));
        $line = current(array_filter($preview['lines'], fn (array $l) => $l['date'] === $monday->format('Y-m-d') && $l['surgeonId'] === $surgeon->getId()));
        self::assertSame('CONFLICT', $line['status'], 'The existing mission must be taken into account by the preview');

        $this->generateAndDeploy($client, $token, $site);

        // Never a silent double booking: she still holds exactly one mission that morning.
        $this->em->clear();
        $hers = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m WHERE m.instrumentist = :i AND m.startAt >= :from AND m.startAt < :to'
        )->setParameters([
            'i' => $busy,
            'from' => new \DateTimeImmutable($monday->format('Y-m-d') . ' 00:00:00'),
            'to'   => new \DateTimeImmutable($monday->format('Y-m-d') . ' 23:59:59'),
        ])->getResult();
        self::assertCount(1, $hers);
        self::assertSame($existingId, $hers[0]->getId());
    }

    /** apply-modifications used to silently skip every Mission the version had not generated. */
    #[WithoutErrorHandler]
    public function test_modification_mode_edits_a_mission_the_version_did_not_generate(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeMondayPost($surgeon, $site, $this->makeSiteInstrumentist($site, 'Anna', 'Poste'));
        $versionId = $this->generateAndDeploy($client, $token, $site);

        $tuesday = $this->firstMonday()->modify('+1 day');
        $manualId = $this->createMission($client, $token, $site, $surgeon, $tuesday);
        $first  = $this->makeSiteInstrumentist($site, 'Premier', 'Choix');
        $second = $this->makeSiteInstrumentist($site, 'Second', 'Choix');
        $this->api($client, $token, 'POST', "/api/missions/{$manualId}/assign-directly", ['instrumentistId' => $first->getId()]);

        $res = $this->api($client, $token, 'POST', "/api/planning/versions/{$versionId}/apply-modifications", [
            'lines' => [[
                'existingMissionId' => $manualId, 'status' => 'COVERED',
                'date' => $tuesday->format('Y-m-d'), 'startTime' => '08:00', 'endTime' => '13:00',
                'siteId' => $site->getId(), 'missionType' => 'BLOCK', 'surgeonId' => $surgeon->getId(),
                'instrumentistId' => $second->getId(),
            ]],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(1, $this->body($res)['updated']);

        $this->em->clear();
        $manual = $this->em->find(Mission::class, $manualId);
        self::assertSame($second->getId(), $manual->getInstrumentist()?->getId());
        self::assertNull($manual->getPlanningVersion(), 'Editing never re-parents the mission');
    }
}
