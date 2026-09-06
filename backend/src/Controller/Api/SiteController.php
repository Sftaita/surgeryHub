<?php

namespace App\Controller\Api;

use App\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sites')]
class SiteController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.hospital_photo.upload_dir%')]
        private readonly string $uploadDir,
        #[Autowire('%app.hospital_photo.public_base_path%')]
        private readonly string $publicBasePath,
    ) {}

    #[Route('', name: 'api_sites_list', methods: ['GET'])]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        $sites = $em->getRepository(Hospital::class)->findBy([], ['name' => 'ASC']);

        return $this->json($sites, JsonResponse::HTTP_OK, [], ['groups' => ['site:list']]);
    }

    #[Route('', name: 'api_sites_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => ['message' => 'Le nom est obligatoire.']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $site = new Hospital();
        $site->setName($name);
        $site->setAddress(isset($data['address']) ? trim((string) $data['address']) ?: null : null);
        $site->setTimezone(isset($data['timezone']) ? trim((string) $data['timezone']) ?: null : null);
        self::applyBlockManagementContact($site, $data);

        $em->persist($site);
        $em->flush();

        return $this->json($site, JsonResponse::HTTP_CREATED, [], ['groups' => ['site:list']]);
    }

    #[Route('/{id}', name: 'api_sites_update', methods: ['PATCH'])]
    public function update(Hospital $site, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['error' => ['message' => 'Le nom est obligatoire.']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $site->setName($name);
        }
        if (array_key_exists('address', $data)) {
            $site->setAddress(trim((string) $data['address']) ?: null);
        }
        if (array_key_exists('timezone', $data)) {
            $site->setTimezone(trim((string) $data['timezone']) ?: null);
        }
        self::applyBlockManagementContact($site, $data);

        $em->flush();

        return $this->json($site, JsonResponse::HTTP_OK, [], ['groups' => ['site:list']]);
    }

    /**
     * Communication des absences chirurgiens (D-114, revue post-déploiement) — coordonnées
     * "gestion du bloc" de l'établissement. Mise à jour partielle comme le reste de ce
     * contrôleur : seules les clés présentes dans `$data` sont appliquées. Normalisation
     * (trim, dédoublonnage insensible à la casse, retrait de l'adresse principale si
     * dupliquée dans les CC) sans jamais rejeter une variante triviale — seule une adresse
     * réellement invalide est rejetée.
     */
    private static function applyBlockManagementContact(Hospital $site, array $data): void
    {
        if (array_key_exists('blockManagementContactEmail', $data)) {
            $raw = $data['blockManagementContactEmail'];
            $email = $raw !== null ? trim((string) $raw) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new BadRequestHttpException('blockManagementContactEmail doit être une adresse email valide.');
            }
            $site->setBlockManagementContactEmail($email !== '' ? $email : null);
        }

        if (array_key_exists('blockManagementContactCc', $data)) {
            if (!is_array($data['blockManagementContactCc'])) {
                throw new BadRequestHttpException('blockManagementContactCc doit être un tableau d\'emails.');
            }
            // Normaliser (trim/dédoublonnage/retrait du principal) AVANT de valider — une
            // variante triviale (espace superflu, casse différente de l'adresse principale)
            // ne doit jamais être rejetée, seule une adresse réellement invalide l'est.
            $normalized = self::normalizeCc($data['blockManagementContactCc'], $site->getBlockManagementContactEmail());
            foreach ($normalized as $cc) {
                if (filter_var($cc, FILTER_VALIDATE_EMAIL) === false) {
                    throw new BadRequestHttpException(sprintf('Adresse CC invalide : %s', $cc));
                }
            }
            $site->setBlockManagementContactCc($normalized);
        }
    }

    /**
     * @param list<string> $rawList
     * @return list<string>
     */
    private static function normalizeCc(array $rawList, ?string $primary): array
    {
        $seen = $primary !== null ? [mb_strtolower(trim($primary))] : [];
        $result = [];

        foreach ($rawList as $raw) {
            $address = trim((string) $raw);
            if ($address === '') {
                continue;
            }
            $key = mb_strtolower($address);
            if (in_array($key, $seen, true)) {
                continue;
            }
            $seen[] = $key;
            $result[] = $address;
        }

        return $result;
    }

    #[Route('/{id}', name: 'api_sites_delete', methods: ['DELETE'])]
    public function delete(Hospital $site, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        if ($site->getMissions()->count() > 0) {
            return $this->json(
                ['error' => ['message' => 'Impossible de supprimer un établissement lié à des missions.']],
                JsonResponse::HTTP_CONFLICT
            );
        }

        if ($site->getPhotoPath() !== null) {
            $this->removeFile($site->getPhotoPath());
        }

        $em->remove($site);
        $em->flush();

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/photo', name: 'api_sites_photo_upload', methods: ['POST'])]
    public function uploadPhoto(Hospital $site, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        $file = $request->files->get('photo');
        if (!$file) {
            return $this->json(['error' => ['message' => 'Aucun fichier fourni.']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mime = $file->getMimeType() ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return $this->json(['error' => ['message' => 'Format non supporté (JPEG, PNG ou WebP).']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            return $this->json(['error' => ['message' => 'Erreur de stockage.']], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Supprimer l'ancienne photo
        if ($site->getPhotoPath() !== null) {
            $this->removeFile($site->getPhotoPath());
        }

        $ext      = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension() ?? 'jpg');
        $filename = sprintf('hospital-%d-%s.%s', (int) $site->getId(), bin2hex(random_bytes(8)), $ext);
        $file->move($this->uploadDir, $filename);

        $publicPath = rtrim($this->publicBasePath, '/') . '/' . $filename;
        $site->setPhotoPath($publicPath);
        $em->flush();

        return $this->json(['photoPath' => $publicPath]);
    }

    private function removeFile(string $publicPath): void
    {
        $basename = basename($publicPath);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return;
        }
        $abs = rtrim($this->uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
