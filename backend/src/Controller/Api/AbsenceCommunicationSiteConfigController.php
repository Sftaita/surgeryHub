<?php

namespace App\Controller\Api;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Security\Voter\PlanningVoter;
use App\Service\AbsenceCommunicationSiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réglage par site de la communication des absences chirurgiens (D-114). Manager/Admin
 * uniquement, même voter que le reste des réglages Planning V2 (PlanningVoter::PLANNING_MANAGE).
 *
 * Lot B : les 4 champs "gestion du bloc" sont désormais exposés en écriture (la logique
 * d'envoi existe — BlockManagementCommunicationService) en plus de `notifyColleaguesEnabled`
 * (Lot A, inchangé). Mise à jour partielle — seules les clés présentes dans le body sont
 * appliquées (AbsenceCommunicationSiteConfigService::updateSettings()).
 */
class AbsenceCommunicationSiteConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceCommunicationSiteConfigService $service,
    ) {
    }

    #[Route('/api/planning/absence-communication-settings', name: 'api_absence_communication_settings_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $sites = $this->em->createQueryBuilder()
            ->select('s')->from(Hospital::class, 's')
            ->orderBy('s.name', 'ASC')
            ->getQuery()->getResult();

        $configsBySite = [];
        foreach ($this->service->listConfigured() as $config) {
            /** @var AbsenceCommunicationSiteConfig $config */
            $siteId = $config->getSite()?->getId();
            if ($siteId !== null) {
                $configsBySite[$siteId] = $config;
            }
        }

        $items = array_map(function (Hospital $site) use ($configsBySite) {
            $config = $configsBySite[$site->getId()] ?? null;
            return self::serialize($site, $config);
        }, $sites);

        return $this->json(['items' => $items]);
    }

    #[Route('/api/planning/absence-communication-settings/{siteId}', name: 'api_absence_communication_settings_update', methods: ['PATCH'])]
    public function update(int $siteId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $site = $this->em->find(Hospital::class, $siteId);
        if ($site === null) {
            throw $this->createNotFoundException('Site introuvable.');
        }

        $data = json_decode($request->getContent() ?: '{}', true) ?? [];

        if (array_key_exists('notifyColleaguesEnabled', $data) && !is_bool($data['notifyColleaguesEnabled'])) {
            throw new BadRequestHttpException('notifyColleaguesEnabled doit être un booléen.');
        }
        if (array_key_exists('notifyBlockManagementEnabled', $data) && !is_bool($data['notifyBlockManagementEnabled'])) {
            throw new BadRequestHttpException('notifyBlockManagementEnabled doit être un booléen.');
        }
        if (array_key_exists('blockManagementDelayDays', $data) && $data['blockManagementDelayDays'] !== null && !is_int($data['blockManagementDelayDays'])) {
            throw new BadRequestHttpException('blockManagementDelayDays doit être un entier.');
        }
        if (array_key_exists('blockManagementEmailCc', $data) && !is_array($data['blockManagementEmailCc'])) {
            throw new BadRequestHttpException('blockManagementEmailCc doit être un tableau d\'emails.');
        }

        $config = $this->service->updateSettings($site, $data);

        return $this->json(self::serialize($site, $config));
    }

    private static function serialize(Hospital $site, ?AbsenceCommunicationSiteConfig $config): array
    {
        return [
            'site' => ['id' => $site->getId(), 'name' => $site->getName()],
            'notifyColleaguesEnabled' => $config?->isNotifyColleaguesEnabled() ?? false,
            'notifyBlockManagementEnabled' => $config?->isNotifyBlockManagementEnabled() ?? false,
            'blockManagementEmailTo' => $config?->getBlockManagementEmailTo(),
            'blockManagementEmailCc' => $config?->getBlockManagementEmailCc() ?? [],
            'blockManagementDelayDays' => $config?->getBlockManagementDelayDays(),
        ];
    }
}
