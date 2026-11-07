<?php

namespace App\Tests\Unit\Release;

use App\Release\ReleaseRegistry;
use PHPUnit\Framework\TestCase;

/** D-162 — version exécutée vs version officielle, à travers tout le cycle de déploiement. */
final class ReleaseRegistryTest extends TestCase
{
    private const OLD = '4fe4422c0ffee0000000000000000000000000aa';
    private const NEW = 'f1ee4013815068147116ef29e73823c4629f5e25';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/release-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,*/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        @rmdir($this->dir . '/var/release');
        @rmdir($this->dir . '/var');
        @rmdir($this->dir);
    }

    private function registry(): ReleaseRegistry
    {
        return new ReleaseRegistry($this->dir);
    }

    private function deploy(string $revision): void
    {
        file_put_contents($this->dir . '/REVISION', $revision . "\n");
    }

    public function test_a_working_copy_is_development_and_never_official(): void
    {
        $this->deploy('$Format:%H$');
        self::assertSame(['status' => 'DEVELOPMENT', 'version' => null, 'commit' => null], $this->registry()->current());

        $this->expectException(\RuntimeException::class);
        $this->registry()->mark('v2026.10.21-prod', 'f1ee401');
    }

    public function test_missing_revision_is_development(): void
    {
        self::assertSame('DEVELOPMENT', $this->registry()->current()['status']);
    }

    public function test_full_cycle_deploy_pending_then_official_then_next_deploy_then_rollback(): void
    {
        // Version précédente, validée.
        $this->deploy(self::OLD);
        self::assertTrue($this->registry()->mark('v2026.10.20-prod-2', '4fe4422'));
        self::assertSame(['status' => 'OFFICIAL', 'version' => 'v2026.10.20-prod-2', 'commit' => '4fe4422'], $this->registry()->current());

        // Nouveau déploiement, tests de santé pas encore passés : jamais annoncé officiel.
        $this->deploy(self::NEW);
        self::assertSame(['status' => 'PENDING_VALIDATION', 'version' => null, 'commit' => 'f1ee401'], $this->registry()->current());

        // Tag créé après validation : la version officielle apparaît sans redéployer.
        self::assertTrue($this->registry()->mark('v2026.10.21-prod', 'f1ee4013815068147116ef29e73823c4629f5e25'));
        self::assertSame(['status' => 'OFFICIAL', 'version' => 'v2026.10.21-prod', 'commit' => 'f1ee401'], $this->registry()->current());
        self::assertFalse($this->registry()->mark('v2026.10.21-prod', 'f1ee401'), 'idempotent');
        self::assertCount(2, $this->registry()->entries());

        // Rollback vers l'image précédente : la version restaurée, jamais celle abandonnée.
        $this->deploy(self::OLD);
        self::assertSame(['status' => 'OFFICIAL', 'version' => 'v2026.10.20-prod-2', 'commit' => '4fe4422'], $this->registry()->current());
    }

    public function test_a_failed_deployment_that_is_rolled_back_before_its_tag_never_appears(): void
    {
        $this->deploy(self::OLD);
        $this->registry()->mark('v2026.10.20-prod-2', '4fe4422');
        $this->deploy(self::NEW); // échec des tests de santé : jamais marqué
        $this->deploy(self::OLD); // rollback
        self::assertSame('v2026.10.20-prod-2', $this->registry()->current()['version']);
        self::assertSame(['v2026.10.20-prod-2'], array_column($this->registry()->entries(), 'tag'));
    }

    public function test_mark_refuses_an_invalid_tag_another_commit_or_a_tag_already_used(): void
    {
        $this->deploy(self::NEW);
        $registry = $this->registry();

        foreach (['2026.10.21-prod', 'v2026.10.21', 'v2026.10.21-prod-0', 'v2026.10.21-prod-2 ', 'latest'] as $bad) {
            try {
                $registry->mark($bad, 'f1ee401');
                self::fail("tag $bad accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        foreach (['4fe4422', 'f1ee40', 'zzzzzzz', ''] as $wrong) {
            try {
                $registry->mark('v2026.10.21-prod', $wrong);
                self::fail("commit $wrong accepted");
            } catch (\RuntimeException) {
            }
        }
        self::assertSame([], $registry->entries(), 'nothing written by a refused mark');

        $registry->mark('v2026.10.21-prod', 'F1EE401'); // casse indifférente
        $this->deploy(self::OLD);
        $this->expectExceptionMessage('un tag ne change jamais de commit');
        $this->registry()->mark('v2026.10.21-prod', '4fe4422');
    }

    public function test_a_corrupt_registry_is_reported_never_guessed(): void
    {
        $this->deploy(self::NEW);
        mkdir($this->dir . '/var/release', 0777, true);
        file_put_contents($this->dir . '/var/release/releases.json', '{not json');

        $this->expectException(\RuntimeException::class);
        $this->registry()->current();
    }
}
