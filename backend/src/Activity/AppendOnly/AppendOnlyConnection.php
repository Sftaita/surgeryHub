<?php

namespace App\Activity\AppendOnly;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** H1 (D-126) — see AppendOnlyDriverMiddleware. */
final class AppendOnlyConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $wrappedConnection)
    {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): Statement
    {
        AppendOnlyGuard::assertAllowed($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        AppendOnlyGuard::assertAllowed($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int
    {
        AppendOnlyGuard::assertAllowed($sql);

        return parent::exec($sql);
    }
}
