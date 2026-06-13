<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionPublication;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PublicationChannel;
use App\Enum\PublicationScope;
use App\Enum\SchedulePrecision;
use App\Service\InstrumentistMissionSyncService;
use App\Service\MissionActionsService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests InstrumentistMissionSyncService::sync() — V1 "polling intelligent".
 *
 * Scénarios couverts (cf. spec):
 *  - mission publiée (OPEN, éligible) après `since` apparaît dans `missions`
 *  - mission claimée par un autre instrumentiste revient dans `removedMissionIds`
 *  - mission claimée par l'utilisateur courant apparaît dans `missions` ("Mes missions")
 *  - aucune donnée patient dans la réponse
 *  - allowedActions toujours présent
 */
class InstrumentistMissionSyncServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MissionMapper $mapper;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->mapper = new MissionMapper(new MissionActionsService(new MissionEncodingGuard()));
    }

    private function makeService(): InstrumentistMissionSyncService
    {
        return new InstrumentistMissionSyncService($this->em, $this->mapper);
    }

    private function makeSite(int $id = 1): Hospital
    {
        $h = new Hospital();
        $h->setName('Clinique Alpha');
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($h, $id);
        return $h;
    }

    private function makeUser(int $id, array $roles, ?EmploymentType $employmentType = null): User
    {
        $u = new User();
        $u->setEmail("user{$id}@test.com");
        $u->setRoles($roles);
        $u->setActive(true);
        if ($employmentType !== null) {
            $u->setEmploymentType($employmentType);
        }
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);
        return $u;
    }

    private function makeMission(int $id, MissionStatus $status, Hospital $site, User $surgeon, ?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setSite($site)
            ->setType(MissionType::BLOCK)
            ->setSchedulePrecision(SchedulePrecision::EXACT)
            ->setSurgeon($surgeon)
            ->setCreatedBy($surgeon)
            ->setStatus($status)
            ->setStartAt(new \DateTimeImmutable('2026-06-15 08:00:00'))
            ->setEndAt(new \DateTimeImmutable('2026-06-15 12:00:00'));

        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }

        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m, $id);

        return $m;
    }

    private function addPoolPublication(Mission $mission): Mission
    {
        $pub = new MissionPublication();
        $pub->setMission($mission)
            ->setScope(PublicationScope::POOL)
            ->setChannel(PublicationChannel::IN_APP)
            ->setPublishedAt(new \DateTimeImmutable());

        $mission->getPublications()->add($pub);

        return $mission;
    }

    /**
     * Configure $this->em pour renvoyer, dans l'ordre, les résultats des 3 requêtes
     * effectuées par sync() : offres OPEN éligibles, mes missions, missions retirées.
     *
     * @param Mission[] $offers
     * @param Mission[] $mine
     * @param Mission[] $removed
     */
    private function mockQueries(array $offers, array $mine, array $removed): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('distinct')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('expr')->willReturn(new Expr());

        $queryOffers = $this->createMock(Query::class);
        $queryOffers->method('getResult')->willReturn($offers);

        $queryMine = $this->createMock(Query::class);
        $queryMine->method('getResult')->willReturn($mine);

        $queryRemoved = $this->createMock(Query::class);
        $queryRemoved->method('getResult')->willReturn($removed);

        $qb->method('getQuery')->willReturnOnConsecutiveCalls($queryOffers, $queryMine, $queryRemoved);

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('getRepository')->willReturn($repo);
    }

    public function testNewlyPublishedOpenMissionAppearsInMissions(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser(10, ['ROLE_SURGEON']);
        $me = $this->makeUser(20, ['ROLE_INSTRUMENTIST'], EmploymentType::FREELANCER);

        $offer = $this->addPoolPublication(
            $this->makeMission(100, MissionStatus::OPEN, $site, $surgeon)
        );

        $this->mockQueries(offers: [$offer], mine: [], removed: []);

        $result = $this->makeService()->sync($me, new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        $this->assertTrue($result['changed']);
        $this->assertCount(1, $result['missions']);
        $this->assertSame(100, $result['missions'][0]->id);
        $this->assertSame('OPEN', $result['missions'][0]->status);
        $this->assertSame([], $result['removedMissionIds']);
    }

    public function testMissionClaimedByAnotherUserReturnsInRemovedMissionIds(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser(10, ['ROLE_SURGEON']);
        $me = $this->makeUser(20, ['ROLE_INSTRUMENTIST'], EmploymentType::FREELANCER);
        $other = $this->makeUser(21, ['ROLE_INSTRUMENTIST'], EmploymentType::FREELANCER);

        // Mission désormais ASSIGNED à un autre instrumentiste — n'est plus une offre pour "me".
        $claimedByOther = $this->addPoolPublication(
            $this->makeMission(101, MissionStatus::ASSIGNED, $site, $surgeon, $other)
        );

        $this->mockQueries(offers: [], mine: [], removed: [$claimedByOther]);

        $result = $this->makeService()->sync($me, new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        $this->assertTrue($result['changed']);
        $this->assertSame([], $result['missions']);
        $this->assertSame([101], $result['removedMissionIds']);
    }

    public function testMissionClaimedByCurrentUserAppearsInMyMissions(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser(10, ['ROLE_SURGEON']);
        $me = $this->makeUser(20, ['ROLE_INSTRUMENTIST'], EmploymentType::FREELANCER);

        $myMission = $this->addPoolPublication(
            $this->makeMission(102, MissionStatus::ASSIGNED, $site, $surgeon, $me)
        );

        $this->mockQueries(offers: [], mine: [$myMission], removed: []);

        $result = $this->makeService()->sync($me, new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        $this->assertTrue($result['changed']);
        $this->assertCount(1, $result['missions']);
        $this->assertSame(102, $result['missions'][0]->id);
        $this->assertSame('ASSIGNED', $result['missions'][0]->status);
    }

    public function testResponseContainsNoPatientDataAndAllowedActions(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser(10, ['ROLE_SURGEON']);
        $me = $this->makeUser(20, ['ROLE_INSTRUMENTIST'], EmploymentType::FREELANCER);

        $offer = $this->addPoolPublication(
            $this->makeMission(103, MissionStatus::OPEN, $site, $surgeon)
        );

        $this->mockQueries(offers: [$offer], mine: [], removed: []);

        $result = $this->makeService()->sync($me, new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));
        $response = $this->makeService()->toResponse($result);

        $json = json_encode($response);

        $this->assertStringNotContainsStringIgnoringCase('patient', (string) $json);
        $this->assertArrayHasKey(0, $result['missions']);
        $this->assertNotEmpty($result['missions'][0]->allowedActions);
        $this->assertContains('claim', $result['missions'][0]->allowedActions);
    }

    public function testNoChangesSinceLastSync(): void
    {
        $me = $this->makeUser(20, ['ROLE_INSTRUMENTIST'], EmploymentType::FREELANCER);

        $this->mockQueries(offers: [], mine: [], removed: []);

        $result = $this->makeService()->sync($me, new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        $this->assertFalse($result['changed']);
        $this->assertSame([], $result['missions']);
        $this->assertSame([], $result['removedMissionIds']);
    }
}
