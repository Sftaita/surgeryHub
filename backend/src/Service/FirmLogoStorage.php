<?php

namespace App\Service;

use App\Entity\Firm;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Catalogue > Prestations, refonte UX — logo de firme, propriété exclusive de Firm
 * (jamais dupliqué sur une prestation/un matériel/une facture). Mirrors
 * ProfilePictureStorage (User.profilePicturePath) : même convention de nommage de
 * fichier, même stockage local public/uploads/, même résolution frontend
 * (resolveApiAssetUrl). Ajoute removeFirmLogo() — absent de ProfilePictureStorage
 * car le profil n'expose pas de suppression explicite, mais requis ici (§3 du prompt).
 */
class FirmLogoStorage
{
    public function __construct(
        #[Autowire('%app.firm_logo.upload_dir%')]
        private readonly string $uploadDir,
        #[Autowire('%app.firm_logo.public_base_path%')]
        private readonly string $publicBasePath,
    ) {
    }

    public function replaceFirmLogo(Firm $firm, UploadedFile $file): string
    {
        $this->ensureUploadDirectoryExists();

        $extension = $this->resolveExtension($file);
        $filename = sprintf(
            'firm-%s-%s.%s',
            (string) ($firm->getId() ?? 'new'),
            bin2hex(random_bytes(16)),
            $extension
        );

        $file->move($this->uploadDir, $filename);

        $oldPublicPath = $firm->getLogoPath();
        if ($oldPublicPath !== null && trim($oldPublicPath) !== '') {
            $this->removeStoredFile($oldPublicPath);
        }

        return rtrim($this->publicBasePath, '/') . '/' . $filename;
    }

    public function removeFirmLogo(Firm $firm): void
    {
        $publicPath = $firm->getLogoPath();
        if ($publicPath !== null && trim($publicPath) !== '') {
            $this->removeStoredFile($publicPath);
        }
    }

    private function ensureUploadDirectoryExists(): void
    {
        if (is_dir($this->uploadDir)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->uploadDir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory "%s".', $this->uploadDir));
        }
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $extension = $file->guessExtension();

        if (is_string($extension) && $extension !== '') {
            return strtolower($extension);
        }

        $clientExtension = $file->getClientOriginalExtension();
        if (is_string($clientExtension) && $clientExtension !== '') {
            return strtolower($clientExtension);
        }

        return 'bin';
    }

    private function removeStoredFile(string $publicPath): void
    {
        $basename = basename($publicPath);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return;
        }

        $absolutePath = rtrim($this->uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}
