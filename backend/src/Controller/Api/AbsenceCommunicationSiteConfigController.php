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
 * Lot B : `notifyBlockManagementEnabled`/`blockManagementDelayDays` exposés en écriture ici
 * en plus de `notifyColleaguesEnabled` (Lot A, inchangé). Mise à jour partielle — seules les
 * clés présentes dans le body sont appliquées (AbsenceCommunicationSiteConfigService::updateSettings()).
 *
 * Revue post-déploiement : les coordonnées "gestion du bloc" (`blockManagementContactEmail`/
 * `blockManagementContactCc`) ne sont plus écrites depuis cet endpoint — ce sont des données
 * établissement, gérées par `SiteController` (`PATCH /api/sites/{id}`). Elles restent
 * exposées ici en LECTURE SEULE (sourcées depuis `Hospital`) pour l'affichage inline dans
 * Communication des absences, sans round-trip supplémentaire côté frontend.
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

        $config = $this->service->updateSettings($site, $data);

        return $this->json(self::serialize($site, $config));
    }

    private static function serialize(Hospital $site, ?AbsenceCommunicationSiteConfig $config): array
    {
        return [
            'site' => ['id' => $site->getId(), 'name' => $site->getName()],
            'notifyColleaguesEnabled' => $config?->isNotifyColleaguesEnabled() ?? false,
            'notifyBlockManagementEnabled' => $config?->isNotifyBlockManagementEnabled() ?? false,
            // Lecture seule ici — provient de Hospital, jamais écrit depuis cet endpoint (voir
            // le docblock de la classe).
            'blockManagementContactEmail' => $site->getBlockManagementContactEmail(),
            'blockManagementContactCc' => $site->getBlockManagementContactCc(),
            'blockManagementDelayDays' => $config?->getBlockManagementDelayDays(),
        ];
    }
}
