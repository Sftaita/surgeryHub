<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Message\SendBillingEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

class PlanningDeploymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfService $pdfService,
        private readonly NotificationService $notificationService,
        private readonly Environment $twig,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {}

    /**
     * Deploy planning: generate and send PDFs to instrumentists and surgeons.
     *
     * @return array{instrumentistsPdfsSent: int, surgeonsPdfsSent: int}
     */
    public function deploy(string $from, string $to, ?int $siteId, User $deployedBy): array
    {
        $fromDate = new \DateTimeImmutable($from);
        $toDate   = new \DateTimeImmutable($to);

        // 1. Find all missions in range with relevant statuses
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.startAt >= :from')
            ->andWhere('m.startAt <= :to')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('from', $fromDate->setTime(0, 0, 0))
            ->setParameter('to', $toDate->setTime(23, 59, 59))
            ->setParameter('statuses', [MissionStatus::DRAFT, MissionStatus::OPEN, MissionStatus::ASSIGNED]);

        if ($siteId !== null) {
            $site = $this->em->find(Hospital::class, $siteId);
            if ($site !== null) {
                $qb->andWhere('m.site = :site')->setParameter('site', $site);
            }
        }

        /** @var Mission[] $missions */
        $missions = $qb->getQuery()->getResult();

        // 2. Group by instrumentist
        $byInstrumentist = [];
        foreach ($missions as $mission) {
            $instr = $mission->getInstrumentist();
            if ($instr !== null) {
                $byInstrumentist[$instr->getId()][] = $mission;
            }
        }

        // 3. Group by surgeon
        $bySurgeon = [];
        foreach ($missions as $mission) {
            $surgeon = $mission->getSurgeon();
            if ($surgeon !== null) {
                $bySurgeon[$surgeon->getId()][] = $mission;
            }
        }

        $instrumentistsSent = 0;
        $surgeonsSent       = 0;

        // 4. Send PDF to each instrumentist
        foreach ($byInstrumentist as $instrId => $instrMissions) {
            $instrumentist = $this->em->find(User::class, $instrId);
            if ($instrumentist === null || !$instrumentist->getEmail()) {
                continue;
            }

            $pdf = $this->pdfService->generateFromTemplate('pdf/planning_instrumentist.html.twig', [
                'instrumentist' => $instrumentist,
                'missions'      => $instrMissions,
                'periodFrom'    => $fromDate,
                'periodTo'      => $toDate,
            ]);

            $name = $this->displayName($instrumentist);
            $filename = sprintf('planning-%s-%s-%s.pdf',
                strtolower(str_replace(' ', '-', $name)),
                $fromDate->format('Y-m-d'),
                $toDate->format('Y-m-d')
            );

            $this->bus->dispatch(new SendBillingEmailMessage(
                to: $instrumentist->getEmail(),
                cc: [],
                subject: sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                fromAddress: $this->fromAddress,
                fromName: $this->fromName,
                htmlTemplate: 'emails/planning_instrumentist.html.twig',
                context: [
                    'instrumentist' => $instrumentist,
                    'periodFrom'    => $fromDate,
                    'periodTo'      => $toDate,
                ],
                attachmentBase64: base64_encode($pdf),
                attachmentFilename: $filename,
            ));

            $instrumentistsSent++;
        }

        // 5. Send PDF to each surgeon
        foreach ($bySurgeon as $surgeonId => $surgeonMissions) {
            $surgeon = $this->em->find(User::class, $surgeonId);
            if ($surgeon === null || !$surgeon->getEmail()) {
                continue;
            }

            $pdf = $this->pdfService->generateFromTemplate('pdf/planning_surgeon.html.twig', [
                'surgeon'    => $surgeon,
                'missions'   => $surgeonMissions,
                'periodFrom' => $fromDate,
                'periodTo'   => $toDate,
            ]);

            $name = $this->displayName($surgeon);
            $filename = sprintf('planning-chirurgien-%s-%s-%s.pdf',
                strtolower(str_replace(' ', '-', $name)),
                $fromDate->format('Y-m-d'),
                $toDate->format('Y-m-d')
            );

            $this->bus->dispatch(new SendBillingEmailMessage(
                to: $surgeon->getEmail(),
                cc: [],
                subject: sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                fromAddress: $this->fromAddress,
                fromName: $this->fromName,
                htmlTemplate: 'emails/planning_surgeon.html.twig',
                context: [
                    'surgeon'    => $surgeon,
                    'periodFrom' => $fromDate,
                    'periodTo'   => $toDate,
                ],
                attachmentBase64: base64_encode($pdf),
                attachmentFilename: $filename,
            ));

            $surgeonsSent++;
        }

        // 6. Record deployment
        $deployment = new PlanningDeployment();
        $deployment->setPeriodFrom($fromDate);
        $deployment->setPeriodTo($toDate);
        $deployment->setDeployedBy($deployedBy);

        if ($siteId !== null) {
            $site = $this->em->find(Hospital::class, $siteId);
            $deployment->setSite($site);
        }

        $this->em->persist($deployment);
        $this->em->flush();

        return [
            'instrumentistsPdfsSent' => $instrumentistsSent,
            'surgeonsPdfsSent'       => $surgeonsSent,
        ];
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
