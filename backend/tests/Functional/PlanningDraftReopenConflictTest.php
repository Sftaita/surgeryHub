<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Recette D-156 (A10) — a reopened draft must show a real conflict as such: the editor and
 * deploy() rely on the same check, so a line can no longer read "OK" while deploy refuses it.
 * A legitimate multi-room prestation (same surgeon, same site, same instrumentist) stays OK.
 */
final class PlanningDraftReopenConflictTest extends WebTestCase
{
    use LivingPlanningFixturesTrait;

    #[WithoutErrorHandler]
    public function test_a_reopened_draft_shows_the_real_conflicts_deploy_blocks_on(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site = $this->makeSite();
        $sophie = $this->makeSiteInstrumentist($site, 'Sophie', 'Collette');
        $berger = $this->makeUser('ROLE_SURGEON', 'Yorick', 'Berger');
        $petronilia = $this->makeUser('ROLE_SURGEON', 'Ana', 'Petronilia');
        // Two different surgeons, same Monday morning, same instrumentist: a real conflict.
        $this->makeMondayPost($berger, $site, $sophie);
        $this->makeMondayPost($petronilia, $site, $sophie);

        $gen = $this->api($client, $token, 'POST', '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());
        $versionId = (int) $this->body($gen)['versionId'];

        $reopen = $this->api($client, $token, 'GET', "/api/planning/v2/drafts/{$versionId}");
        self::assertSame(200, $reopen->getStatusCode(), (string) $reopen->getContent());
        $monday = $this->firstMonday()->format('Y-m-d');
        $lines = array_values(array_filter($this->body($reopen)['lines'], static fn (array $l) => $l['date'] === $monday));
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertNotNull($line['existingMissionId']);
            self::assertSame($sophie->getId(), $line['instrumentistId']);
            self::assertSame('CONFLICT', $line['status'], 'Never "OK" while deploy refuses it');
        }
        self::assertGreaterThan(0, $this->body($reopen)['summary']['conflict'] ?? 0);

        $dep = $this->api($client, $token, 'POST', '/api/planning/v2/deploy', ['planningVersionId' => $versionId]);
        self::assertSame(409, $dep->getStatusCode(), (string) $dep->getContent());
        self::assertSame('DRAFT_CONFLICTS', $this->body($dep)['code']);
        $blocked = array_column($this->body($dep)['conflicts'], 'missionId');
        foreach ($lines as $line) {
            self::assertContains($line['existingMissionId'], $blocked, 'Same missions in the editor and in the deploy gate');
        }
    }

    #[WithoutErrorHandler]
    public function test_saving_an_unrelated_edit_never_drops_both_sides_of_a_real_conflict(): void
    {
        // Recette D-156 (Feb 2027 on the production copy): one edit saved after generation
        // removed Salve from both conflicting missions, which were then published OPEN.
        $client = $this->boot();
        $token = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site = $this->makeSite();
        $sophie = $this->makeSiteInstrumentist($site, 'Sophie', 'Collette');
        $perrine = $this->makeSiteInstrumentist($site, 'Perrine', 'Pineux');
        $this->makeMondayPost($this->makeUser('ROLE_SURGEON', 'Yorick', 'Berger'), $site, $sophie);
        $this->makeMondayPost($this->makeUser('ROLE_SURGEON', 'Ana', 'Petronilia'), $site, $sophie);
        $this->makeMondayPost($this->makeUser('ROLE_SURGEON', 'Jean', 'Libre'), $site, null);

        $gen = $this->api($client, $token, 'POST', '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());
        $versionId = (int) $this->body($gen)['versionId'];
        $lines = $this->body($this->api($client, $token, 'GET', "/api/planning/v2/drafts/{$versionId}"))['lines'];

        // The manager only staffs the free post; every other line is resent unchanged.
        $monday = $this->firstMonday()->format('Y-m-d');
        foreach ($lines as &$line) {
            if ($line['date'] === $monday && $line['instrumentistId'] === null) {
                $line['instrumentistId'] = $perrine->getId();
            }
        }
        unset($line);
        $res = $this->api($client, $token, 'PATCH', "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $reopened = array_values(array_filter(
            $this->body($this->api($client, $token, 'GET', "/api/planning/v2/drafts/{$versionId}"))['lines'],
            static fn (array $l) => $l['date'] === $monday,
        ));
        $byInstrumentist = array_count_values(array_map(static fn (array $l) => (string) $l['instrumentistId'], $reopened));
        self::assertSame(2, $byInstrumentist[(string) $sophie->getId()] ?? 0, 'Sophie kept on both conflicting missions');
        self::assertSame(1, $byInstrumentist[(string) $perrine->getId()] ?? 0, 'The edit itself is saved');
        self::assertSame(2, count(array_filter($reopened, static fn (array $l) => $l['status'] === 'CONFLICT')));

        // The real conflict still blocks deploy: the manager decides, nothing is published OPEN.
        $dep = $this->api($client, $token, 'POST', '/api/planning/v2/deploy', ['planningVersionId' => $versionId]);
        self::assertSame(409, $dep->getStatusCode(), (string) $dep->getContent());
        self::assertSame('DRAFT_CONFLICTS', $this->body($dep)['code']);
    }

    #[WithoutErrorHandler]
    public function test_a_multi_room_prestation_stays_ok_in_a_reopened_draft(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site = $this->makeSite();
        $sophie = $this->makeSiteInstrumentist($site, 'Sophie', 'Collette');
        $deltour = $this->makeUser('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $this->makeMondayPost($deltour, $site, $sophie);
        $this->makeMondayPost($deltour, $site, $sophie);

        $gen = $this->api($client, $token, 'POST', '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());
        $versionId = (int) $this->body($gen)['versionId'];

        $monday = $this->firstMonday()->format('Y-m-d');
        $lines = array_values(array_filter(
            $this->body($this->api($client, $token, 'GET', "/api/planning/v2/drafts/{$versionId}"))['lines'],
            static fn (array $l) => $l['date'] === $monday,
        ));
        self::assertCount(2, $lines);
        self::assertSame(['COVERED', 'COVERED'], array_column($lines, 'status'));
    }
}
