<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use App\Security\Voter\BillingVoter;
use App\Service\FirmLogoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/firms')]
final class FirmController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FirmLogoStorage $firmLogoStorage,
        private readonly ValidatorInterface $validator,
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
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

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
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

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
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $this->em->remove($firm);
        $this->em->flush();

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Catalogue > Prestations, refonte UX (§3) — le logo est une propriété exclusive de
     * Firm, jamais dupliqué sur une prestation/un matériel. Mirrors
     * MeController::uploadProfilePicture() : mêmes contraintes de validation (5 Mo,
     * JPEG/PNG/WebP uniquement — jamais de SVG, surface XSS inutile pour un simple
     * logo). Gate MANAGE (pas IS_AUTHENTICATED_FULLY comme le profil : ce n'est pas
     * "sa propre ressource", c'est une donnée de catalogue partagée).
     */
    #[Route('/{id}/logo', name: 'api_firms_logo_upload', methods: ['POST'])]
    public function uploadLogo(Firm $firm, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $logo = $request->files->get('logo');
        if ($logo === null) {
            throw new BadRequestHttpException('logo file is required');
        }
        if (!$logo instanceof UploadedFile) {
            throw new BadRequestHttpException('Invalid logo upload');
        }

        $fileErrors = $this->validator->validate($logo, [
            new Assert\Image(
                maxSize: '5M',
                mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                mimeTypesMessage: 'Only JPEG, PNG and WEBP images are allowed.',
            ),
        ]);

        if (count($fileErrors) > 0) {
            throw new UnprocessableEntityHttpException((string) $fileErrors);
        }

        $publicPath = $this->firmLogoStorage->replaceFirmLogo($firm, $logo);
        $firm->setLogoPath($publicPath);
        $this->em->flush();

        return $this->json($this->serialize($firm));
    }

    #[Route('/{id}/logo', name: 'api_firms_logo_delete', methods: ['DELETE'])]
    public function deleteLogo(Firm $firm): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $this->firmLogoStorage->removeFirmLogo($firm);
        $firm->setLogoPath(null);
        $this->em->flush();

        return $this->json($this->serialize($firm));
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
            'logoPath'       => $f->getLogoPath(),
        ];
    }
}
