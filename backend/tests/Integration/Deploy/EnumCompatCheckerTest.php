<?php

namespace App\Tests\Integration\Deploy;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Service\AuditJournalService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-187 — contrôle de compatibilité des données avant bascule (deploy/rollback/enum-compat.php),
 * sur une vraie base : une valeur d'énumération inconnue du code est signalée, aucune ligne n'est
 * modifiée, et le script CLI (celui que remote.sh exécute dans l'image cible) rend 0 / 3.
 */
final class EnumCompatCheckerTest extends KernelTestCase
{
    private const SCRIPT = __DIR__ . '/../../../../deploy/rollback/enum-compat.php';
    private const UNKNOWN = 'D187_EVENT_FROM_A_NEWER_RELEASE';

    private EntityManagerInterface $em;
    private Connection $db;
    private ?int $actorId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        require_once self::SCRIPT;

        $user = (new User())->setEmail('d187-' . bin2hex(random_bytes(4)) . '@test.local')->setRoles(['ROLE_MANAGER']);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();
        $this->actorId = $user->getId();
    }

    protected function tearDown(): void
    {
        if ($this->actorId !== null) {
            $this->db->executeStatement('DELETE FROM audit_event WHERE actor_id = ?', [$this->actorId]);
            $this->db->executeStatement('DELETE FROM user WHERE id = ?', [$this->actorId]);
        }
        parent::tearDown();
    }

    public function test_a_value_unknown_to_the_code_is_reported_and_nothing_is_modified(): void
    {
        $this->insertAudit(self::UNKNOWN);
        $this->insertAudit(self::UNKNOWN);
        $before = $this->fingerprint();

        $checked = 0;
        $problems = surgicalhub_enum_incompatibilities($this->em, $checked);

        self::assertGreaterThan(10, $checked, 'toutes les colonnes enumType du mapping sont vérifiées');
        $audit = array_values(array_filter($problems, fn (array $p) => $p['table'] === 'audit_event'));
        self::assertSame([[
            'table' => 'audit_event', 'column' => 'event_type', 'enum' => AuditEventType::class,
            'value' => self::UNKNOWN, 'rows' => 2,
        ]], $audit);
        self::assertSame($before, $this->fingerprint(), 'les événements historiques sont intacts');
        self::assertFalse($this->db->isTransactionActive(), 'la transaction de lecture est refermée');
    }

    public function test_current_release_reads_financial_statistics_export_events_and_the_journal_stays_readable(): void
    {
        $this->insertAudit(AuditEventType::FINANCIAL_STATISTICS_EXPORTED->value);

        $problems = array_filter(surgicalhub_enum_incompatibilities($this->em), fn (array $p) => $p['value'] === AuditEventType::FINANCIAL_STATISTICS_EXPORTED->value);
        self::assertSame([], $problems);

        $page = static::getContainer()->get(AuditJournalService::class)->search(['actorId' => $this->actorId]);
        self::assertSame(1, $page['total']);
        self::assertSame('FINANCIAL_STATISTICS_EXPORTED', $page['items'][0]['eventType']);
    }

    public function test_cli_exit_codes_are_0_when_compatible_and_3_when_a_value_is_unknown(): void
    {
        [$code, $out] = $this->runScript();
        self::assertSame(0, $code, $out);
        self::assertStringStartsWith('compatible : ', $out);

        $this->insertAudit(self::UNKNOWN);
        $before = $this->fingerprint();
        [$code, $out] = $this->runScript();
        self::assertSame(3, $code, $out);
        self::assertStringContainsString('INCOMPATIBLE', $out);
        self::assertStringContainsString('audit_event.event_type = ' . self::UNKNOWN . ' (1 ligne(s)) : inconnue de App\Enum\AuditEventType', $out);
        self::assertSame($before, $this->fingerprint());
    }

    public function test_cli_reports_an_error_code_when_the_check_cannot_run(): void
    {
        [$code, $out] = $this->runScript(['DATABASE_URL' => 'mysql://nobody:wrong@127.0.0.1:1/none?serverVersion=8.0.32']);
        self::assertNotContains($code, [0, 3], 'un contrôle impossible ne doit jamais passer pour « compatible »');
        self::assertStringContainsString('contrôle de compatibilité impossible', $out);
    }

    private function insertAudit(string $type): void
    {
        $this->db->insert('audit_event', [
            'actor_id' => $this->actorId, 'mission_id' => null, 'event_type' => $type, 'payload' => '{"view":"overview"}',
            'created_at' => '2026-11-06 18:00:00', 'updated_at' => '2026-11-06 18:00:00',
        ]);
    }

    private function fingerprint(): string
    {
        return json_encode($this->db->fetchAllAssociative('SELECT * FROM audit_event WHERE actor_id = ? ORDER BY id', [$this->actorId]));
    }

    /** @return array{0: int, 1: string} */
    private function runScript(array $env = []): array
    {
        $backend = dirname(__DIR__, 3);
        $proc = proc_open([PHP_BINARY, '-d', 'date.timezone=UTC', realpath(self::SCRIPT)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $backend,
            $env + ['APP_ENV' => 'test', 'DATABASE_URL' => $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL')] + getenv());
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }
}
