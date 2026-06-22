<?php

namespace App\Controller\Api;

use App\Entity\Hospital;
use App\Entity\SiteGroup;
use App\Entity\User;
use App\Security\Voter\PlanningVoter;
use App\Service\SiteGroupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Manager/Admin-only CRUD for SiteGroup. No frontend yet, no cutover, V1 untouched. */
class SiteGroupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteGroupService $service,
    ) {}

    #[Route('/api/planning/site-groups', name: 'api_planning_site_groups_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        return $this->json(['items' => array_map(fn (SiteGroup $g) => $this->serialize($g), $this->service->list())]);
    }

    #[Route('/api/planning/site-groups', name: 'api_planning_site_groups_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data  = json_decode($request->getContent() ?: '{}', true) ?? [];
        $group = $this->service->create((string) ($data['name'] ?? ''), $currentUser);

        return $this->json($this->serialize($group), 201);
    }

    #[Route('/api/planning/site-groups/{id}', name: 'api_planning_site_group_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        return $this->json($this->serialize($this->findOrFail($id)));
    }

    #[Route('/api/planning/site-groups/{id}', name: 'api_planning_site_group_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $group = $this->findOrFail($id);
        $data  = json_decode($request->getContent() ?: '{}', true) ?? [];

        if (isset($data['name'])) {
            $this->service->rename($group, (string) $data['name']);
        }

        return $this->json($this->serialize($group));
    }

    #[Route('/api/planning/site-groups/{id}', name: 'api_planning_site_group_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $this->service->delete($this->findOrFail($id));

        return new JsonResponse(null, 204);
    }

    #[Route('/api/planning/site-groups/{id}/sites', name: 'api_planning_site_group_add_site', methods: ['POST'])]
    public function addSite(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $group = $this->findOrFail($id);
        $data  = json_decode($request->getContent() ?: '{}', true) ?? [];

        if (!isset($data['siteId']) || !is_numeric($data['siteId'])) {
            throw new BadRequestHttpException('siteId est requis.');
        }
        $site = $this->em->find(Hospital::class, (int) $data['siteId']);
        if ($site === null) {
            throw $this->createNotFoundException('Site introuvable.');
        }

        $this->service->addSite($group, $site);

        return $this->json($this->serialize($group));
    }

    #[Route('/api/planning/site-groups/{id}/sites/{siteId}', name: 'api_planning_site_group_remove_site', methods: ['DELETE'])]
    public function removeSite(int $id, int $siteId): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $group = $this->findOrFail($id);
        $site  = $this->em->find(Hospital::class, $siteId);
        if ($site === null) {
            throw $this->createNotFoundException('Site introuvable.');
        }

        $this->service->removeSite($group, $site);

        return $this->json($this->serialize($group));
    }

    private function findOrFail(int $id): SiteGroup
    {
        $group = $this->em->find(SiteGroup::class, $id);
        if ($group === null) {
            throw $this->createNotFoundException('SiteGroup introuvable.');
        }
        return $group;
    }

    private function serialize(SiteGroup $group): array
    {
        return [
            'id'        => $group->getId(),
            'name'      => $group->getName(),
            'createdAt' => $group->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'sites'     => array_map(
                static fn ($m) => ['id' => $m->getSite()->getId(), 'name' => $m->getSite()->getName()],
                $group->getMemberships()->toArray(),
            ),
        ];
    }
}
