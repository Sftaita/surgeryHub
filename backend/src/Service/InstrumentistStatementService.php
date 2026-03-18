<?php

namespace App\Service;

use App\Entity\InstrumentistStatement;
use App\Entity\InstrumentistStatementLine;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\StatementLineType;
use Doctrine\ORM\EntityManagerInterface;

class InstrumentistStatementService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Prévisualise les lignes facturables pour un instrumentiste + mois.
     * Exclut les missions déjà incluses dans un décompte GENERATED/SENT/PAID.
     */
    public function preview(User $instrumentist, int $year, int $month): array
    {
        $missions = $this->findBillableMissions($instrumentist, $year, $month);
        $alreadyBilledIds = $this->getAlreadyBilledMissionIds($instrumentist, $year, $month);

        $lines = [];
        foreach ($missions as $mission) {
            if (in_array($mission->getId(), $alreadyBilledIds, true)) {
                continue;
            }
            $lines[] = $this->buildPreviewLine($mission, $instrumentist);
        }

        $total = array_sum(array_column($lines, 'totalAmount'));

        return [
            'instrumentist' => [
                'id' => $instrumentist->getId(),
                'displayName' => $this->buildDisplayName($instrumentist),
                'email' => $instrumentist->getEmail(),
                'hourlyRate' => $instrumentist->getHourlyRate(),
                'consultationFee' => $instrumentist->getConsultationFee(),
            ],
            'period' => ['year' => $year, 'month' => $month],
            'lines' => $lines,
            'totalAmount' => round($total, 2),
            'alreadyBilledMissionIds' => $alreadyBilledIds,
        ];
    }

    /**
     * Génère un décompte définitif (snapshot + verrouillage).
     */
    public function generate(User $instrumentist, int $year, int $month, array $selectedMissionIds): InstrumentistStatement
    {
        // Vérifie qu'il n'existe pas déjà un décompte GENERATED+ pour ce mois
        $existing = $this->findExistingGeneratedStatement($instrumentist, $year, $month);
        if ($existing !== null) {
            throw new \DomainException(sprintf(
                'Un décompte existe déjà pour %02d/%d (statut : %s).',
                $month, $year, $existing->getStatus()->value
            ));
        }

        $missions = $this->findBillableMissions($instrumentist, $year, $month);
        $alreadyBilled = $this->getAlreadyBilledMissionIds($instrumentist, $year, $month);

        $statement = new InstrumentistStatement();
        $statement->setInstrumentist($instrumentist);
        $statement->setPeriodYear($year);
        $statement->setPeriodMonth($month);
        $statement->setStatus(InvoiceStatus::GENERATED);
        $statement->setInstrumentistNameSnapshot($this->buildDisplayName($instrumentist));
        $statement->setInstrumentistEmailSnapshot($instrumentist->getEmail());

        $total = '0.00';

        foreach ($missions as $mission) {
            if (!in_array($mission->getId(), $selectedMissionIds, true)) {
                continue;
            }
            if (in_array($mission->getId(), $alreadyBilled, true)) {
                continue;
            }

            $lineData = $this->buildPreviewLine($mission, $instrumentist);

            $line = new InstrumentistStatementLine();
            $line->setMission($mission);
            $line->setLineType($lineData['lineType'] === 'BLOC' ? StatementLineType::BLOC : StatementLineType::CONSULTATION);
            $line->setDurationMinutesRaw($lineData['durationMinutesRaw']);
            $line->setDurationMinutesRounded($lineData['durationMinutesRounded']);
            $line->setRateSnapshot((string) $lineData['rateSnapshot']);
            $line->setQuantity((string) $lineData['quantity']);
            $line->setTotalAmount((string) $lineData['totalAmount']);
            $line->setSurgeonNameSnapshot($lineData['surgeonName']);
            $line->setSiteNameSnapshot($lineData['siteName']);
            $line->setMissionDateSnapshot(new \DateTimeImmutable($mission->getStartAt()->format('Y-m-d')));

            $statement->addLine($line);

            $total = (string) round((float) $total + (float) $lineData['totalAmount'], 2);
        }

        $statement->setTotalAmount($total);

        $this->em->persist($statement);
        $this->em->flush();

        return $statement;
    }

    public function markSent(InstrumentistStatement $statement): InstrumentistStatement
    {
        if ($statement->getStatus() !== InvoiceStatus::GENERATED) {
            throw new \DomainException('Le décompte doit être en statut GENERATED pour être envoyé.');
        }
        $statement->setStatus(InvoiceStatus::SENT);
        $statement->setSentAt(new \DateTimeImmutable());
        $this->em->flush();
        return $statement;
    }

    public function markPaid(InstrumentistStatement $statement): InstrumentistStatement
    {
        if ($statement->getStatus() === InvoiceStatus::PAID) {
            return $statement;
        }
        $statement->setStatus(InvoiceStatus::PAID);
        $statement->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();
        return $statement;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return Mission[] */
    private function findBillableMissions(User $instrumentist, int $year, int $month): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);

        return $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->leftJoin('m.services', 's')
            ->where('m.instrumentist = :user')
            ->andWhere('m.status = :status')
            ->andWhere('m.startAt >= :start')
            ->andWhere('m.startAt <= :end')
            ->setParameter('user', $instrumentist)
            ->setParameter('status', MissionStatus::VALIDATED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('m.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function getAlreadyBilledMissionIds(User $instrumentist, int $year, int $month): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.mission) as missionId')
            ->from(InstrumentistStatementLine::class, 'l')
            ->join('l.statement', 's')
            ->where('s.instrumentist = :user')
            ->andWhere('s.periodYear = :year')
            ->andWhere('s.periodMonth = :month')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $instrumentist)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('statuses', [InvoiceStatus::GENERATED, InvoiceStatus::SENT, InvoiceStatus::PAID])
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'missionId');
    }

    private function findExistingGeneratedStatement(User $instrumentist, int $year, int $month): ?InstrumentistStatement
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(InstrumentistStatement::class, 's')
            ->where('s.instrumentist = :user')
            ->andWhere('s.periodYear = :year')
            ->andWhere('s.periodMonth = :month')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $instrumentist)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('statuses', [InvoiceStatus::GENERATED, InvoiceStatus::SENT, InvoiceStatus::PAID])
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function buildPreviewLine(Mission $mission, User $instrumentist): array
    {
        $isConsultation = $mission->getType() === MissionType::CONSULTATION;

        $surgeonName = $mission->getSurgeon()
            ? $this->buildDisplayName($mission->getSurgeon())
            : null;

        $siteName = $mission->getSite()?->getName();

        if ($isConsultation) {
            $rate = (float) ($instrumentist->getConsultationFee() ?? '0');
            return [
                'missionId' => $mission->getId(),
                'missionDate' => $mission->getStartAt()->format('Y-m-d'),
                'lineType' => 'CONSULTATION',
                'durationMinutesRaw' => null,
                'durationMinutesRounded' => null,
                'rateSnapshot' => $rate,
                'quantity' => 1.0,
                'totalAmount' => round($rate, 2),
                'surgeonName' => $surgeonName,
                'siteName' => $siteName,
            ];
        }

        // BLOC — durée à partir de l'heure de début/fin
        $raw = (int) ($mission->getEndAt()->getTimestamp() - $mission->getStartAt()->getTimestamp()) / 60;
        $raw = max(0, $raw);
        $rounded = (int) (ceil($raw / 15) * 15);
        $hours = round($rounded / 60, 4);
        $rate = (float) ($instrumentist->getHourlyRate() ?? '0');
        $total = round($hours * $rate, 2);

        return [
            'missionId' => $mission->getId(),
            'missionDate' => $mission->getStartAt()->format('Y-m-d'),
            'lineType' => 'BLOC',
            'durationMinutesRaw' => $raw,
            'durationMinutesRounded' => $rounded,
            'rateSnapshot' => $rate,
            'quantity' => $hours,
            'totalAmount' => $total,
            'surgeonName' => $surgeonName,
            'siteName' => $siteName,
        ];
    }

    private function buildDisplayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : $user->getEmail();
    }
}
