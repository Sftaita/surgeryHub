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
 * MissionMapper::toHospitalSlim()/toUserSlim() used to build DTOs (HospitalSlimDto,
 * UserSlimDto) that silently dropped photoPath/address/specialties even though the
 * entities carry them — the real reason hospital photos and surgeon specialties never
 * reached the instrumistent app's Mission payloads.
 */
final class MissionMapperTest extends TestCase
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

    private function makeSite(?string $address, ?string $photoPath): Hospital
    {
        $h = new Hospital();
        $this->setId($h, 1);
        $h->setName('CHIREC — Hôpital Delta');
        $h->setAddress($address);
        $h->setPhotoPath($photoPath);
        return $h;
    }

    private function makeSurgeon(array $specialties): User
    {
        $u = new User();
        $this->setId($u, 2);
        $u->setEmail('surgeon@surgicalhub.test');
        $u->setRoles(['ROLE_SURGEON']);
        $u->setSpecialties($specialties);
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
        $m->setStartAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-09-01 12:00:00'));
        return $m;
    }

    public function test_hospital_slim_carries_photo_path_and_address(): void
    {
        $site = $this->makeSite('Rue de la Clinique 1', '/uploads/hospital-photos/hospital-1.jpg');
        $surgeon = $this->makeSurgeon([]);
        $mission = $this->makeMission($site, $surgeon);

        $dto = $this->mapper->toDetailDto($mission, $surgeon);

        self::assertSame('/uploads/hospital-photos/hospital-1.jpg', $dto->site->photoPath);
        self::assertSame('Rue de la Clinique 1', $dto->site->address);
    }

    public function test_hospital_slim_is_null_when_site_has_no_photo(): void
    {
        $site = $this->makeSite(null, null);
        $surgeon = $this->makeSurgeon([]);
        $mission = $this->makeMission($site, $surgeon);

        $dto = $this->mapper->toDetailDto($mission, $surgeon);

        self::assertNull($dto->site->photoPath);
        self::assertNull($dto->site->address);
    }

    public function test_user_slim_carries_surgeon_specialties(): void
    {
        $site = $this->makeSite(null, null);
        $surgeon = $this->makeSurgeon(['GENOU', 'EPAULE']);
        $mission = $this->makeMission($site, $surgeon);

        $dto = $this->mapper->toDetailDto($mission, $surgeon);

        self::assertSame(['GENOU', 'EPAULE'], $dto->surgeon->specialties);
    }

    public function test_user_slim_specialties_is_empty_array_when_surgeon_has_none(): void
    {
        $site = $this->makeSite(null, null);
        $surgeon = $this->makeSurgeon([]);
        $mission = $this->makeMission($site, $surgeon);

        $dto = $this->mapper->toDetailDto($mission, $surgeon);

        self::assertSame([], $dto->surgeon->specialties);
    }

    public function test_list_dto_carries_the_same_fields_as_detail_dto(): void
    {
        $site = $this->makeSite('Avenue du Bloc 12', '/uploads/hospital-photos/hospital-2.jpg');
        $surgeon = $this->makeSurgeon(['HANCHE']);
        $mission = $this->makeMission($site, $surgeon);

        $dto = $this->mapper->toListDto($mission, $surgeon);

        self::assertSame('/uploads/hospital-photos/hospital-2.jpg', $dto->site->photoPath);
        self::assertSame('Avenue du Bloc 12', $dto->site->address);
        self::assertSame(['HANCHE'], $dto->surgeon->specialties);
    }

}
