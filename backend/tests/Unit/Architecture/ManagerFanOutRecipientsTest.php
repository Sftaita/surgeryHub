<?php

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * D-149 — toute notification « tous les managers » choisit ses destinataires via
 * UserRepository::findOperationalManagers() (actifs, hors comptes techniques
 * `@surgicalhub.internal`, une fois par User). Un appel direct à findManagersAndAdmins()
 * hors du repository réintroduirait les comptes de test jetables parmi les destinataires.
 */
final class ManagerFanOutRecipientsTest extends TestCase
{
    public function test_no_code_outside_the_repository_selects_managers_with_find_managers_and_admins(): void
    {
        $src = \dirname(__DIR__, 3) . '/src';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'UserRepository.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'findManagersAndAdmins(')) {
                $offenders[] = substr($file->getPathname(), \strlen($src) + 1);
            }
        }

        self::assertSame([], $offenders, 'utiliser UserRepository::findOperationalManagers()');
    }
}
