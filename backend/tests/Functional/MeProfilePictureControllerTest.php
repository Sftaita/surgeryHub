<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional HTTP tests for POST /api/me/profile-picture.
 * Profile photo feature: onboarding + post-onboarding self-service upload.
 */
final class MeProfilePictureControllerTest extends WebTestCase
{
    private const PASSWORD = 'ProfilePic25!';

    // 1x1 transparent PNG, valid enough for Symfony's Assert\Image (real mime-type detection).
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private EntityManagerInterface $em;
    private array $createdUserIds = [];
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em = static::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->createdUserIds as $id) {
                $u = $this->em->find(User::class, $id);
                if ($u !== null) {
                    $path = $u->getProfilePicturePath();
                    if ($path !== null) {
                        $this->deleteUploadedFile($path);
                    }
                    $this->em->remove($u);
                }
            }
            $this->em->flush();
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('mepic-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makePngUpload(string $name = 'photo.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mepic_') . '.png';
        file_put_contents($tmp, base64_decode(self::PNG_BASE64));
        $this->tmpFiles[] = $tmp;
        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function makeTextUpload(string $name = 'not-an-image.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mepic_') . '.txt';
        file_put_contents($tmp, 'this is not an image');
        $this->tmpFiles[] = $tmp;
        return new UploadedFile($tmp, $name, 'text/plain', null, true);
    }

    private function deleteUploadedFile(string $publicPath): void
    {
        $uploadDir = static::getContainer()->getParameter('app.profile_picture.upload_dir');
        $basename = basename($publicPath);
        $abs = rtrim((string) $uploadDir, '/\\') . DIRECTORY_SEPARATOR . $basename;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    private function absoluteUploadPath(string $publicPath): string
    {
        $uploadDir = static::getContainer()->getParameter('app.profile_picture.upload_dir');
        return rtrim((string) $uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($publicPath);
    }

    /**
     * $client->request() may reboot the kernel, detaching entities fetched from the
     * previous EntityManager instance. Re-fetch via a fresh container reference instead
     * of calling refresh() on a possibly-detached entity.
     */
    private function reload(int $userId): User
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        $user = $this->em->find(User::class, $userId);
        self::assertNotNull($user);
        return $user;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_upload_requires_authentication(): void
    {
        $client = $this->boot();

        $client->request('POST', '/api/me/profile-picture', files: ['profilePicture' => $this->makePngUpload()]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function test_upload_success_returns_me_response_with_profile_picture_url(): void
    {
        $client = $this->boot();
        $user = $this->createUser();
        $token = $this->login($client, $user);

        $client->request('POST', '/api/me/profile-picture',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['profilePicture' => $this->makePngUpload()],
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertNotNull($data['profilePictureUrl'] ?? null);
        self::assertStringContainsString('/uploads/profile-pictures/', $data['profilePictureUrl']);
        self::assertSame($user->getEmail(), $data['email']);

        $user = $this->reload($user->getId());
        self::assertNotNull($user->getProfilePicturePath());
        self::assertFileExists($this->absoluteUploadPath($user->getProfilePicturePath()));
    }

    public function test_upload_rejects_non_image_file(): void
    {
        $client = $this->boot();
        $user = $this->createUser();
        $token = $this->login($client, $user);

        $client->request('POST', '/api/me/profile-picture',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['profilePicture' => $this->makeTextUpload()],
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $user = $this->reload($user->getId());
        self::assertNull($user->getProfilePicturePath());
    }

    public function test_upload_without_file_returns_400(): void
    {
        $client = $this->boot();
        $user = $this->createUser();
        $token = $this->login($client, $user);

        $client->request('POST', '/api/me/profile-picture',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function test_second_upload_replaces_and_deletes_previous_file(): void
    {
        $client = $this->boot();
        $user = $this->createUser();
        $token = $this->login($client, $user);

        $client->request('POST', '/api/me/profile-picture',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['profilePicture' => $this->makePngUpload('first.png')],
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $reloaded = $this->reload($user->getId());
        $firstPath = $reloaded->getProfilePicturePath();
        self::assertNotNull($firstPath);
        $firstAbsolute = $this->absoluteUploadPath($firstPath);
        self::assertFileExists($firstAbsolute);

        $client->request('POST', '/api/me/profile-picture',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['profilePicture' => $this->makePngUpload('second.png')],
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $reloaded = $this->reload($user->getId());
        $secondPath = $reloaded->getProfilePicturePath();

        self::assertNotSame($firstPath, $secondPath);
        self::assertFileDoesNotExist($firstAbsolute, 'Old profile picture file must be deleted on replacement.');
        self::assertFileExists($this->absoluteUploadPath($secondPath));
    }
}
