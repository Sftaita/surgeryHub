<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProfilePictureStorage
{
    public function __construct(
        #[Autowire('%app.profile_picture.upload_dir%')]
        private readonly string $uploadDir,
        #[Autowire('%app.profile_picture.public_base_path%')]
        private readonly string $publicBasePath,
    ) {
    }

    public function replaceUserProfilePicture(User $user, UploadedFile $file): string
    {
        $this->ensureUploadDirectoryExists();

        $extension = $this->resolveExtension($file);
        $filename = sprintf(
            'user-%s-%s.%s',
            (string) ($user->getId() ?? 'new'),
            bin2hex(random_bytes(16)),
            $extension
        );

        $file->move($this->uploadDir, $filename);

        $oldPublicPath = $user->getProfilePicturePath();
        if ($oldPublicPath !== null && trim($oldPublicPath) !== '') {
            $this->removeStoredFile($oldPublicPath);
        }

        return rtrim($this->publicBasePath, '/') . '/' . $filename;
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