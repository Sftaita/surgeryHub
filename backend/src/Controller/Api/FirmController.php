<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/firms')]
final class FirmController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_firms_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $firms = $this->em->getRepository(Firm::class)->findBy([], ['name' => 'ASC']);

        return $this->json(array_map(fn (Firm $f) => $this->serialize($f), $firms));
    }

    #[Route('', name: 'api_firms_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return $this->json(['error' => ['message' => 'Le nom est obligatoire.']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $this->em->getRepository(Firm::class)->findOneBy(['name' => $name]);
        if ($existing) {
            return $this->json(['error' => ['message' => 'Une firme avec ce nom existe déjà.']], JsonResponse::HTTP_CONFLICT);
        }

        $firm = new Firm();
        $firm->setName($name);
        $firm->setActive((bool) ($data['active'] ?? true));
        $firm->setBillingEmail(isset($data['billingEmail']) ? trim((string) $data['billingEmail']) ?: null : null);
        $firm->setBillingEmailCc(isset($data['billingEmailCc']) && is_array($data['billingEmailCc']) ? $data['billingEmailCc'] : null);
        $firm->setCountry(isset($data['country']) ? trim((string) $data['country']) ?: null : null);
        $firm->setRepresentative(isset($data['representative']) ? trim((string) $data['representative']) ?: null : null);
        $firm->setPhone(isset($data['phone']) ? trim((string) $data['phone']) ?: null : null);

        $this->em->persist($firm);
        $this->em->flush();

        return $this->json($this->serialize($firm), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_firms_update', methods: ['PATCH'])]
    public function update(Firm $firm, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['error' => ['message' => 'Le nom est obligatoire.']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $conflict = $this->em->getRepository(Firm::class)->findOneBy(['name' => $name]);
            if ($conflict && $conflict->getId() !== $firm->getId()) {
                return $this->json(['error' => ['message' => 'Une firme avec ce nom existe déjà.']], JsonResponse::HTTP_CONFLICT);
            }
            $firm->setName($name);
        }

        if (array_key_exists('active', $data)) {
            $firm->setActive((bool) $data['active']);
        }

        if (array_key_exists('billingEmail', $data)) {
            $firm->setBillingEmail(trim((string) $data['billingEmail']) ?: null);
        }

        if (array_key_exists('billingEmailCc', $data)) {
            $cc = is_array($data['billingEmailCc'])
                ? array_values(array_filter(array_map('trim', $data['billingEmailCc'])))
                : null;
            $firm->setBillingEmailCc($cc ?: null);
        }

        if (array_key_exists('country', $data)) {
            $firm->setCountry(trim((string) $data['country']) ?: null);
        }

        if (array_key_exists('representative', $data)) {
            $firm->setRepresentative(trim((string) $data['representative']) ?: null);
        }

        if (array_key_exists('phone', $data)) {
            $firm->setPhone(trim((string) $data['phone']) ?: null);
        }

        $this->em->flush();

        return $this->json($this->serialize($firm));
    }

    #[Route('/{id}', name: 'api_firms_delete', methods: ['DELETE'])]
    public function delete(Firm $firm): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        $this->em->remove($firm);
        $this->em->flush();

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function serialize(Firm $f): array
    {
        return [
            'id'             => $f->getId(),
            'name'           => $f->getName(),
            'active'         => $f->isActive(),
            'billingEmail'   => $f->getBillingEmail(),
            'billingEmailCc' => $f->getBillingEmailCc() ?? [],
            'country'        => $f->getCountry(),
            'representative' => $f->getRepresentative(),
            'phone'          => $f->getPhone(),
        ];
    }
}
