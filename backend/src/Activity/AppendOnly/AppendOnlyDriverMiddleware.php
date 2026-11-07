<?php

namespace App\Activity\AppendOnly;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * H1 (D-126) — DBAL driver middleware making activity_event append-only for the
 * application: every SQL statement going through the app's connection is inspected and
 * any UPDATE / DELETE / TRUNCATE / REPLACE targeting activity_event is refused, whatever
 * produced it (ORM unit of work, DQL bulk statement, raw DBAL call).
 *
 * Why not database triggers: production MySQL runs with binary logging on and
 * log_bin_trust_function_creators=0, and the application user has no SUPER privilege —
 * CREATE TRIGGER is refused there (error 1419). An application-level guard is the
 * strongest mechanism available without changing the database server's configuration.
 * It does not stop a DBA working directly in a SQL client — that is by design (manual
 * administration stays possible outside the normal application flow).
 *
 * Exceptional maintenance (a future H8 backfill rollback, for instance) goes through
 * AppendOnlyGuard::runMaintenance(), which lifts the guard for one explicit callback.
 */
#[AsMiddleware]
final class AppendOnlyDriverMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Driver\Connection
            {
                return new AppendOnlyConnection(parent::connect($params));
            }
        };
    }
}
