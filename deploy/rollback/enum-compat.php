<?php
/**
 * D-187 — compatibilité des données avec une release, avant toute bascule (activate / rollback).
 *
 * Exécuté par deploy/remote.sh DANS L'IMAGE DE LA RELEASE CIBLE (`compose run`, cache jetable),
 * sur la base de production, en lecture seule. Chaque colonne que le code de la cible lit comme
 * une énumération PHP (`enumType` Doctrine) ne doit contenir que des valeurs connues de cette
 * énumération : sinon l'hydratation échoue (ValueError) et l'écran qui lit la ligne tombe en 500.
 * Cas d'origine : `ad45ef6` ne connaît pas `audit_event.event_type = FINANCIAL_STATISTICS_EXPORTED`
 * (D-186), et le journal d'activité relit les derniers événements par Doctrine.
 *
 * Le mapping lu est celui de la cible (son code, ses entités) : le contrôle ne suppose rien de la
 * release en service. Aucune écriture : transaction READ ONLY, uniquement des SELECT.
 *
 * Codes de sortie : 0 compatible · 3 incompatible (valeurs listées) · autre = contrôle impossible
 * (la bascule doit alors être refusée : jamais d'échec silencieux).
 *
 * Volontairement autonome (aucune classe du dépôt) : il doit fonctionner avec le code d'une release
 * antérieure à son écriture.
 */

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @return list<array{table: string, column: string, enum: string, value: string, rows: int}>
 */
function surgicalhub_enum_incompatibilities(EntityManagerInterface $em, ?int &$checkedColumns = null): array
{
    $connection = $em->getConnection();
    $platform = $connection->getDatabasePlatform();
    $columns = [];

    /** @var ClassMetadata $meta */
    foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
        if ($meta->isMappedSuperclass || $meta->isEmbeddedClass) {
            continue;
        }
        foreach ($meta->getFieldNames() as $field) {
            $mapping = $meta->getFieldMapping($field);
            $enum = is_array($mapping) ? ($mapping['enumType'] ?? null) : ($mapping->enumType ?? null);
            if ($enum === null || str_contains($field, '.')) {   // champs d'embeddable : hors périmètre
                continue;
            }
            $type = is_array($mapping) ? $mapping['type'] : $mapping->type;
            $key = $meta->getTableName() . '.' . $meta->getColumnName($field);
            $columns[$key] ??= ['table' => $meta->getTableName(), 'column' => $meta->getColumnName($field), 'enum' => $enum, 'type' => $type];
        }
    }
    ksort($columns);
    $checkedColumns = count($columns);

    $problems = [];
    surgicalhub_read_only($connection, function () use ($connection, $platform, $columns, &$problems): void {
        foreach ($columns as $c) {
            $rows = $connection->fetchAllAssociative(sprintf(
                'SELECT %1$s AS v, COUNT(*) AS n FROM %2$s WHERE %1$s IS NOT NULL GROUP BY %1$s',
                $platform->quoteSingleIdentifier($c['column']),
                $platform->quoteSingleIdentifier($c['table']),
            ));
            $unknown = [];
            foreach ($rows as $row) {
                foreach (surgicalhub_stored_values((string) $row['v'], $c['type']) as $value) {
                    if (!surgicalhub_enum_accepts($c['enum'], $value)) {
                        $unknown[$value] = ($unknown[$value] ?? 0) + (int) $row['n'];
                    }
                }
            }
            ksort($unknown);
            foreach ($unknown as $value => $count) {
                $problems[] = ['table' => $c['table'], 'column' => $c['column'], 'enum' => $c['enum'], 'value' => (string) $value, 'rows' => $count];
            }
        }
    });

    return $problems;
}

/** Valeurs stockées : une seule, ou plusieurs pour une collection d'énumérations (json, simple_array). */
function surgicalhub_stored_values(string $raw, string $type): array
{
    if ($type === 'json') {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_map('strval', array_values($decoded)) : [$raw];
    }
    if ($type === 'simple_array') {
        return $raw === '' ? [] : explode(',', $raw);
    }
    return [$raw];
}

function surgicalhub_enum_accepts(string $enum, string $value): bool
{
    if (!enum_exists($enum)) {
        return false;
    }
    $backing = (new ReflectionEnum($enum))->getBackingType()?->getName();
    if ($backing === 'int') {
        return preg_match('/^-?\d+$/', $value) === 1 && $enum::tryFrom((int) $value) !== null;
    }
    return $backing === 'string' && $enum::tryFrom($value) !== null;
}

function surgicalhub_read_only(Connection $connection, callable $read): void
{
    $connection->executeStatement('START TRANSACTION READ ONLY');
    try {
        $read();
    } finally {
        $connection->executeStatement('ROLLBACK');
    }
}

/** @param list<array{table: string, column: string, enum: string, value: string, rows: int}> $problems */
function surgicalhub_enum_report(array $problems, int $checkedColumns): int
{
    if ($problems === []) {
        echo "compatible : {$checkedColumns} colonne(s) d'énumération vérifiée(s), toutes les valeurs sont connues de cette release.\n";
        return 0;
    }
    echo "INCOMPATIBLE : cette release ne sait pas lire " . count($problems) . " valeur(s) présente(s) en base :\n";
    foreach ($problems as $p) {
        printf("  - %s.%s = %s (%d ligne(s)) : inconnue de %s\n", $p['table'], $p['column'], $p['value'], $p['rows'], $p['enum']);
    }
    return 3;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        $root = getcwd();
        require $root . '/vendor/autoload.php';
        if (is_file($root . '/.env') && class_exists(Symfony\Component\Dotenv\Dotenv::class)) {
            (new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');
        }
        $kernel = new App\Kernel($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod', false);
        $kernel->boot();
        $checked = 0;
        $problems = surgicalhub_enum_incompatibilities($kernel->getContainer()->get('doctrine')->getManager(), $checked);
        exit(surgicalhub_enum_report($problems, $checked));
    } catch (Throwable $e) {
        fwrite(STDERR, 'contrôle de compatibilité impossible : ' . $e::class . ' — ' . $e->getMessage() . "\n");
        exit(1);
    }
}
