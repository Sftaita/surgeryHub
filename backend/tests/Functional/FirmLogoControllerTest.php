<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Catalogue > Prestations, refonte UX — POST/DELETE /api/firms/{id}/logo. Mirrors
 * MeProfilePictureControllerTest (même validation, même convention de stockage) —
 * voir FirmLogoStorage. MANAGE-gated (pas IS_AUTHENTICATED_FULLY comme le profil :
 * le logo est une donnée de catalogue partagée, pas "sa propre ressource").
 */
final class FirmLogoControllerTest extends WebTestCase
{
    private const PASSWORD = 'FirmLogo25!';

    // 1x1 transparent PNG, valid enough for Symfony's Assert\Image (real mime-type detection).
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private EntityManagerInterface $em;
    private array $createdUserIds = [];
    private array $createdFirmIds = [];
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
            $this->em->clear();
            foreach ($this->createdFirmIds as $id) {
                $f = $this->em->find(Firm::class, $id);
                if ($f !== null) {
                    if ($f->getLogoPath() !== null) {
                        $this->deleteUploadedFile($f->getLogoPath());
                    }
                    $this->em->remove($f);
                }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                $u = $this->em->find(User::class, $id);
                if ($u !== null) {
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
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('firmlogo-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function createFirm(): Firm
    {
        $f = new Firm();
        $f->setName('LogoFirm-' . bin2hex(random_bytes(4)));
        $this->em->persist($f);
        $this->em->flush();
        $this->createdFirmIds[] = $f->getId();
        return $f;
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

    private function makePngUpload(string $name = 'logo.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'firmlogo_') . '.png';
        file_put_contents($tmp, base64_decode(self::PNG_BASE64));
        $this->tmpFiles[] = $tmp;
        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function makeTextUpload(string $name = 'not-an-image.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'firmlogo_') . '.txt';
        file_put_contents($tmp, 'this is not an image');
        $this->tmpFiles[] = $tmp;
        return new UploadedFile($tmp, $name, 'text/plain', null, true);
    }

    private function deleteUploadedFile(string $publicPath): void
    {
        $uploadDir = static::getContainer()->getParameter('app.firm_logo.upload_dir');
        $basename = basename($publicPath);
        $abs = rtrim((string) $uploadDir, '/\\') . DIRECTORY_SEPARATOR . $basename;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    private function absoluteUploadPath(string $publicPath): string
    {
        $uploadDir = static::getContainer()->getParameter('app.firm_logo.upload_dir');
        return rtrim((string) $uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($publicPath);
    }

    private function reloadFirm(int $firmId): Firm
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        $firm = $this->em->find(Firm::class, $firmId);
        self::assertNotNull($firm);
        return $firm;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_upload_requires_authentication(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();

        $client->request('POST', "/api/firms/{$firm->getId()}/logo", files: ['logo' => $this->makePngUpload()]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function test_instrumentist_cannot_upload_logo(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['logo' => $this->makePngUpload()],
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function test_manager_can_upload_logo(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['logo' => $this->makePngUpload()],
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertNotNull($data['logoPath'] ?? null);
        self::assertStringContainsString('/uploads/firm-logos/', $data['logoPath']);

        $reloaded = $this->reloadFirm($firm->getId());
        self::assertNotNull($reloaded->getLogoPath());
        self::assertFileExists($this->absoluteUploadPath($reloaded->getLogoPath()));
    }

    public function test_admin_can_upload_logo(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['logo' => $this->makePngUpload()],
        );

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function test_upload_rejects_non_image_file(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['logo' => $this->makeTextUpload()],
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $reloaded = $this->reloadFirm($firm->getId());
        self::assertNull($reloaded->getLogoPath());
    }

    public function test_upload_without_file_returns_400(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function test_second_upload_replaces_and_deletes_previous_file(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['logo' => $this->makePngUpload('first.png')],
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $reloaded = $this->reloadFirm($firm->getId());
        $firstPath = $reloaded->getLogoPath();
        $firstAbsolute = $this->absoluteUploadPath($firstPath);
        self::assertFileExists($firstAbsolute);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['logo' => $this->makePngUpload('second.png')],
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $reloaded = $this->reloadFirm($firm->getId());
        $secondPath = $reloaded->getLogoPath();

        self::assertNotSame($firstPath, $secondPath);
        self::assertFileDoesNotExist($firstAbsolute, 'Old firm logo file must be deleted on replacement.');
        self::assertFileExists($this->absoluteUploadPath($secondPath));
    }

    public function test_manager_can_delete_logo(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $client->request('POST', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            files: ['logo' => $this->makePngUpload()],
        );
        $reloaded = $this->reloadFirm($firm->getId());
        $path = $reloaded->getLogoPath();
        $absolute = $this->absoluteUploadPath($path);
        self::assertFileExists($absolute);

        $client->request('DELETE', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertNull($data['logoPath']);

        self::assertFileDoesNotExist($absolute, 'Logo file must be deleted from disk.');
        $reloaded = $this->reloadFirm($firm->getId());
        self::assertNull($reloaded->getLogoPath());
    }

    public function test_instrumentist_cannot_delete_logo(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $client->request('DELETE', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function test_delete_when_no_logo_is_a_harmless_no_op(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $client->request('DELETE', "/api/firms/{$firm->getId()}/logo",
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function test_firm_list_serializes_logo_path(): void
    {
        $client = $this->boot();
        $firm = $this->createFirm();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $client->request('GET', '/api/firms', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $row = null;
        foreach ($data as $r) {
            if ($r['id'] === $firm->getId()) { $row = $r; break; }
        }
        self::assertNotNull($row);
        self::assertArrayHasKey('logoPath', $row);
        self::assertNull($row['logoPath']);
    }
}
