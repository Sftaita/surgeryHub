<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Release\ReleaseRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** D-162 — `GET /api/version` et `app:release:mark` / `app:release:show`. */
final class VersionControllerTest extends WebTestCase
{
    private const PASSWORD = 'VersionTest123!';
    private const SHA = 'f1ee4013815068147116ef29e73823c4629f5e25';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $dir;
    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->dir = sys_get_temp_dir() . '/version-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->users !== []) {
            $ids = implode(',', $this->users);
            $this->em->getConnection()->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'd162-%'");
            $this->em->getConnection()->executeStatement("DELETE FROM user WHERE id IN ($ids)");
        }
        @unlink($this->dir . '/var/release/releases.json');
        @unlink($this->dir . '/REVISION');
        @rmdir($this->dir . '/var/release');
        @rmdir($this->dir . '/var');
        @rmdir($this->dir);
        parent::tearDown();
    }

    #[WithoutErrorHandler]
    public function test_anonymous_access_is_refused(): void
    {
        $this->client->request('GET', '/api/version');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_a_working_copy_reports_development_never_cached(): void
    {
        // tests run from a clone: backend/REVISION keeps its literal $Format:%H$.
        $this->get('ROLE_SURGEON');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['application' => 'SurgicalHub', 'status' => 'DEVELOPMENT', 'version' => null, 'commit' => null], $this->json());
        $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
    }

    #[WithoutErrorHandler]
    public function test_same_version_for_the_three_roles_pending_then_official_after_mark_then_rollback(): void
    {
        file_put_contents($this->dir . '/REVISION', self::SHA . "\n");
        static::getContainer()->set(ReleaseRegistry::class, new ReleaseRegistry($this->dir));

        $tokens = array_map(fn (string $role) => $this->login($role), ['ROLE_MANAGER', 'ROLE_SURGEON', 'ROLE_INSTRUMENTIST']);

        foreach ($tokens as $token) {
            $this->client->request('GET', '/api/version', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertSame(['application' => 'SurgicalHub', 'status' => 'PENDING_VALIDATION', 'version' => null, 'commit' => 'f1ee401'], $this->json(), 'deployed, not yet validated: never official');
        }

        $tester = new CommandTester((new Application(static::$kernel))->find('app:release:mark'));
        self::assertSame(2, $tester->execute(['tag' => 'v2026.10.21-prod']), '--commit is mandatory');
        self::assertSame(1, $tester->execute(['tag' => 'v2026.10.21-prod', '--commit' => '4fe4422']), 'another commit is refused');
        self::assertSame(0, $tester->execute(['tag' => 'v2026.10.21-prod', '--commit' => 'f1ee401']), $tester->getDisplay());
        self::assertStringContainsString('SurgicalHub · v2026.10.21-prod · f1ee401', preg_replace('/\s+/', ' ', $tester->getDisplay()));

        foreach ($tokens as $token) {
            $this->client->request('GET', '/api/version', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertSame(['application' => 'SurgicalHub', 'status' => 'OFFICIAL', 'version' => 'v2026.10.21-prod', 'commit' => 'f1ee401'], $this->json(), 'the official version appears without redeploying');
        }

        $show = new CommandTester((new Application(static::$kernel))->find('app:release:show'));
        $show->execute([]);
        self::assertStringContainsString(self::SHA, $show->getDisplay());
        self::assertStringContainsString('OFFICIAL', $show->getDisplay());

        // Rollback to an image whose commit was never validated here: never the abandoned tag.
        file_put_contents($this->dir . '/REVISION', str_repeat('a', 40) . "\n");
        $this->client->request('GET', '/api/version', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens[0]]);
        self::assertSame(['application' => 'SurgicalHub', 'status' => 'PENDING_VALIDATION', 'version' => null, 'commit' => 'aaaaaaa'], $this->json());
    }

    #[WithoutErrorHandler]
    public function test_an_unreadable_registry_never_claims_an_official_version(): void
    {
        file_put_contents($this->dir . '/REVISION', self::SHA);
        mkdir($this->dir . '/var/release', 0777, true);
        file_put_contents($this->dir . '/var/release/releases.json', 'corrupt');
        static::getContainer()->set(ReleaseRegistry::class, new ReleaseRegistry($this->dir));

        $this->get('ROLE_INSTRUMENTIST');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['application' => 'SurgicalHub', 'status' => 'PENDING_VALIDATION', 'version' => null, 'commit' => 'f1ee401'], $this->json());
    }

    private function get(string $role): void
    {
        $this->client->request('GET', '/api/version', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($role)]);
    }

    private function login(string $role): string
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('d162-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->users[] = (int) $user->getId();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = $this->json();
        self::assertArrayHasKey('token', $data, (string) $this->client->getResponse()->getContent());

        return $data['token'];
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
