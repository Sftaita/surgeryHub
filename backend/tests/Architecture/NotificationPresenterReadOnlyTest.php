<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * D-188 — guardrail behind the two allow-list entries of NotificationPresenter
 * (OpenMissionPoolSingleEntryTest, AbsenceImpactSingleResolutionTest): it may NAME notification
 * types because it only words what was already produced. It must therefore stay read-only —
 * never build an intent, never dispatch, never write — and the API must not word anything itself.
 * Reads the PHP source with comments stripped, nothing autoloaded.
 */
final class NotificationPresenterReadOnlyTest extends TestCase
{
    private const PRESENTER = 'src/Notification/NotificationPresenter.php';
    private const CONTROLLER = 'src/Controller/Api/NotificationController.php';

    public function test_the_presenter_never_produces_dispatches_nor_writes(): void
    {
        $code = $this->source(self::PRESENTER);

        foreach ([
            'new NotificationIntent', 'NotificationDispatcher', 'MessageBusInterface', '->dispatch(',
            '->persist(', '->flush(', '->remove(', 'executeStatement', 'createQueryBuilder', '->createQuery(',
            'setSeenAt', 'setStatus(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, "NotificationPresenter must stay read-only ($forbidden)");
        }
    }

    public function test_the_api_delegates_all_wording_and_business_state_to_the_presenter(): void
    {
        $code = $this->source(self::CONTROLLER);

        self::assertStringContainsString('presentMany(', $code);
        foreach (['NotificationTargetResolver', "'title'", "'body'", "'businessState' =>", 'getPayload('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, "NotificationController must not word notifications itself ($forbidden)");
        }
    }

    private function source(string $relative): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(realpath(__DIR__ . '/../..') . '/' . $relative)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
