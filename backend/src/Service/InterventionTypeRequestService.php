<?php

namespace App\Service;

use App\Dto\Request\InterventionTypeRequestCreateRequest;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class InterventionTypeRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function create(Mission $mission, InterventionTypeRequestCreateRequest $dto, User $createdBy): InterventionTypeRequest
    {
        $req = new InterventionTypeRequest();
        $req
            ->setMission($mission)
            ->setLabel($dto->label)
            ->setSuggestedCode($dto->suggestedCode)
            ->setComment($dto->comment)
            ->setCreatedBy($createdBy);

        $this->em->persist($req);
        $this->em->flush();

        return $req;
    }
}
