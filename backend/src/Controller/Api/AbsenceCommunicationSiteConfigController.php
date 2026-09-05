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
 * Réglage par site de la communication des absences chirurgiens (Lot A/D-114). Manager/Admin
 * uniquement, même voter que le reste des réglages Planning V2 (PlanningVoter::PLANNING_MANAGE).
 *
 * Contrat volontairement restreint en Lot A : seul `notifyColleaguesEnabled` est exposé en
 * écriture. Les 4 champs "gestion du bloc" existent déjà en base (schéma stabilisé pour B/C)
 * mais ne sont pas encore exploitables — les exposer en écriture avant que la logique
 * d'envoi n'existe donnerait une fausse impression de fonctionnalité active.
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
            return [
                'site' => ['id' => $site->getId(), 'name' => $site->getName()],
                'notifyColleaguesEnabled' => $config?->isNotifyColleaguesEnabled() ?? false,
            ];
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
        if (!array_key_exists('notifyColleaguesEnabled', $data) || !is_bool($data['notifyColleaguesEnabled'])) {
            throw new BadRequestHttpException('notifyColleaguesEnabled (booléen) est requis.');
        }

        $config = $this->service->setNotifyColleaguesEnabled($site, $data['notifyColleaguesEnabled']);

        return $this->json([
            'site' => ['id' => $site->getId(), 'name' => $site->getName()],
            'notifyColleaguesEnabled' => $config->isNotifyColleaguesEnabled(),
        ]);
    }
}
