<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\MissionActionsService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionMapper;
use PHPUnit\Framework\TestCase;

/**
 * D-066 — startAt/endAt must carry the true Europe/Brussels offset.
 *
 * Historically (D-065, superseded) this was fixed by an explicit AppTimezone::
 * relabel() call inside the mapper. D-066 moved the fix to Doctrine hydration itself
 * (business_datetime_immutable) — the mapper now just formats whatever the entity
 * already carries. These tests build fixtures with an explicit Europe/Brussels
 * timezone (see makeMission()'s comment) to simulate that hydration and verify the
 * mapper's own format() call is correct — the real end-to-end proof (raw DB value ->
 * real hydration -> mapper) lives in MissionBusinessTimezoneIntegrationTest.
 *
 * Kept as its own file (rather than folded into MissionMapperTest) so this D-066 work
 * can be committed on its own, independent of MissionMapperTest's unrelated
 * photo/specialties coverage.
 */
final class MissionMapperTimezoneTest extends TestCase
{
    private MissionMapper $mapper;

    protected function setUp(): void
    {
        // MissionActionsService is final — real instance instead of a mock double.
        $this->mapper = new MissionMapper(new MissionActionsService(new MissionEncodingGuard()));
    }

    private function setId(object $entity, int $id): void
    {
        $rp = new \ReflectionProperty($entity, 'id');
        $rp->setAccessible(true);
        $rp->setValue($entity, $id);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $this->setId($h, 1);
        $h->setName('CHIREC — Hôpital Delta');
        return $h;
    }

    private function makeSurgeon(): User
    {
        $u = new User();
        $this->setId($u, 2);
        $u->setEmail('surgeon@surgicalhub.test');
        $u->setRoles(['ROLE_SURGEON']);
        return $u;
    }

    private function makeMission(Hospital $site, User $surgeon): Mission
    {
        $m = new Mission();
        $this->setId($m, 3);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setStatus(MissionStatus::ASSIGNED);
        // This is a pure unit test — no EntityManager, so Doctrine's
        // business_datetime_immutable hydration (App\Doctrine\Type\
        // BusinessDateTimeImmutableType) never runs here. Constructing with an explicit
        // Europe/Brussels timezone mirrors what real hydration produces, so this test
        // stays focused on MissionMapper's own logic (does it format() correctly?) —
        // the hydration itself is covered by BusinessDateTimeImmutableTypeTest (pure
        // unit) and MissionBusinessTimezoneIntegrationTest (real DB).
        $m->setStartAt(new \DateTimeImmutable('2026-09-01 08:00:00', new \DateTimeZone('Europe/Brussels')));
        $m->setEndAt(new \DateTimeImmutable('2026-09-01 12:00:00', new \DateTimeZone('Europe/Brussels')));
        return $m;
    }

    public function test_detail_dto_start_at_carries_the_true_brussels_dst_offset(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeSurgeon();
        $mission = $this->makeMission($site, $surgeon);

        $dto = $this->mapper->toDetailDto($mission, $surgeon);

        self::assertSame('2026-09-01T08:00:00+02:00', $dto->startAt);
        self::assertSame('2026-09-01T12:00:00+02:00', $dto->endAt);
    }

    public function test_list_dto_start_at_carries_the_true_brussels_dst_offset(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeSurgeon();
        $mission = $this->makeMission($site, $surgeon);

        $dto = $this->mapper->toListDto($mission, $surgeon);

        self::assertSame('2026-09-01T08:00:00+02:00', $dto->startAt);
        self::assertSame('2026-09-01T12:00:00+02:00', $dto->endAt);
    }

    public function test_detail_dto_start_at_carries_the_true_brussels_winter_offset(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeSurgeon();
        $mission = $this->makeMission($site, $surgeon);
        $mission->setStartAt(new \DateTimeImmutable('2026-01-15 08:00:00', new \DateTimeZone('Europe/Brussels')));
        $mission->setEndAt(new \DateTimeImmutable('2026-01-15 12:00:00', new \DateTimeZone('Europe/Brussels')));

        $dto = $this->mapper->toDetailDto($mission, $surgeon);

        self::assertSame('2026-01-15T08:00:00+01:00', $dto->startAt);
        self::assertSame('2026-01-15T12:00:00+01:00', $dto->endAt);
    }

    public function test_detail_dto_start_at_is_null_when_mission_has_no_start_at(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeSurgeon();
        // Fresh Mission, startAt/endAt never set — getStartAt()/getEndAt() are nullable.
        $mission = new Mission();
        $this->setId($mission, 3);
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setStatus(MissionStatus::DRAFT);

        $dto = $this->mapper->toDetailDto($mission, $surgeon);

        self::assertNull($dto->startAt);
        self::assertNull($dto->endAt);
    }
}
