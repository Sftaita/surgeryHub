<?php

namespace App\Service;

use App\Dto\Request\InstrumentistRatingRequest;
use App\Dto\Request\SurgeonRatingRequest;
use App\Entity\InstrumentistRating;
use App\Entity\Mission;
use App\Entity\SurgeonRatingByInstrumentist;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Doctrine\ORM\EntityManagerInterface;

class RatingService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function rateInstrumentist(Mission $mission, User $surgeon, User $instrumentist, InstrumentistRatingRequest $dto): InstrumentistRating
    {
        $existing = $this->em->getRepository(InstrumentistRating::class)->findOneBy([
            'mission' => $mission,
            'surgeon' => $surgeon,
        ]);
        if ($existing) {
            throw new ConflictHttpException('Rating already exists for this mission and surgeon');
        }

        $rating = new InstrumentistRating();
        $rating
            ->setMission($mission)
            ->setSite($mission->getSite())
            ->setSurgeon($surgeon)
            ->setInstrumentist($instrumentist)
            ->setSterilityRespect($dto->sterilityRespect)
            ->setEquipmentKnowledge($dto->equipmentKnowledge)
            ->setAttitude($dto->attitude)
            ->setPunctuality($dto->punctuality)
            ->setComment($dto->comment)
            ->setIsFirstCollaboration($dto->isFirstCollaboration ?? false);

        $this->em->persist($rating);
        $this->em->flush();

        return $rating;
    }

    public function rateSurgeon(Mission $mission, User $instrumentist, User $surgeon, SurgeonRatingRequest $dto): SurgeonRatingByInstrumentist
    {
        $existing = $this->em->getRepository(SurgeonRatingByInstrumentist::class)->findOneBy([
            'mission' => $mission,
            'instrumentist' => $instrumentist,
        ]);
        if ($existing) {
            throw new ConflictHttpException('Rating already exists for this mission and instrumentist');
        }

        $rating = new SurgeonRatingByInstrumentist();
        $rating
            ->setMission($mission)
            ->setInstrumentist($instrumentist)
            ->setSurgeon($surgeon)
            ->setCordiality($dto->cordiality)
            ->setPunctuality($dto->punctuality)
            ->setMissionRespect($dto->missionRespect)
            ->setComment($dto->comment)
            ->setIsFirstCollaboration($dto->isFirstCollaboration ?? false);

        $this->em->persist($rating);
        $this->em->flush();

        return $rating;
    }

    public function getInstrumentistForMission(Mission $mission): User
    {
        return $mission->getInstrumentist() ?? throw new NotFoundHttpException('Mission has no instrumentist assigned');
    }
}
