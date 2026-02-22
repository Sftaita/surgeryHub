<?php

namespace App\Service;

use App\Dto\Request\Response\HospitalSlimDto;
use App\Dto\Request\Response\MissionDetailDto;
use App\Dto\Request\Response\MissionListDto;
use App\Dto\Request\Response\UserSlimDto;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;

final class MissionMapper
{
    public function __construct(private readonly MissionActionsService $actions) {}

    public function toListDto(Mission $m, User $viewer): MissionListDto
    {
        return new MissionListDto(
            id: (int) $m->getId(),
            site: $this->toHospitalSlim($m->getSite()),
            startAt: $m->getStartAt()?->format(\DateTimeInterface::ATOM),
            endAt: $m->getEndAt()?->format(\DateTimeInterface::ATOM),
            schedulePrecision: (string) $m->getSchedulePrecision()?->value,
            type: (string) $m->getType()?->value,
            status: (string) $m->getStatus()?->value,
            surgeon: $this->toUserSlim($m->getSurgeon()),
            instrumentist: $m->getInstrumentist() ? $this->toUserSlim($m->getInstrumentist()) : null,
            allowedActions: $this->actions->allowedActions($m, $viewer),
        );
    }

    public function toDetailDto(Mission $m, User $viewer): MissionDetailDto
    {
        return new MissionDetailDto(
            id: (int) $m->getId(),
            site: $this->toHospitalSlim($m->getSite()),
            startAt: $m->getStartAt()?->format(\DateTimeInterface::ATOM),
            endAt: $m->getEndAt()?->format(\DateTimeInterface::ATOM),
            schedulePrecision: (string) $m->getSchedulePrecision()?->value,
            type: (string) $m->getType()?->value,
            status: (string) $m->getStatus()?->value,
            surgeon: $this->toUserSlim($m->getSurgeon()),
            instrumentist: $m->getInstrumentist() ? $this->toUserSlim($m->getInstrumentist()) : null,
            allowedActions: $this->actions->allowedActions($m, $viewer),
        );
    }

    private function toHospitalSlim(?Hospital $h): HospitalSlimDto
    {
        if (!$h) {
            // ne devrait jamais arriver
            return new HospitalSlimDto(0, 'Unknown', Hospital::DEFAULT_TIMEZONE);
        }

        return new HospitalSlimDto(
            id: (int) $h->getId(),
            name: (string) $h->getName(),
            timezone: $h->getTimezone(), // getter fallback Europe/Brussels
        );
    }

    private function toUserSlim(?User $u): UserSlimDto
    {
        if (!$u) {
            // ne devrait jamais arriver pour surgeon
            return new UserSlimDto(0, 'unknown', null, null);
        }

        return new UserSlimDto(
            id: (int) $u->getId(),
            email: (string) $u->getEmail(),
            firstname: $u->getFirstname(),
            lastname: $u->getLastname(),
            active: $u->isActive(),
            employmentType: $u->getEmploymentType()?->value,
        );
    }
}