<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * H5b (D-132, §27 / §32) — the in-app and push channels of a user notification exist only
 * through NotificationDispatcher, which records the dispatch and every channel (H2). Until
 * H5b, eight handlers / services created NotificationEvent rows and sent raw pushes on their
 * own, invisible to the history. Same approach as EmailThroughDispatcherTest: comment-free
 * PHP source of src/, nominative exceptions, stale exceptions fail.
 */
final class NotificationChannelsThroughDispatcherTest extends TestCase
{
    /** primitive => [regex, files allowed to use it with the reason] */
    private const RULES = [
        'new NotificationEvent' => ['/\bnew\s+NotificationEvent\s*\(/', [
            'src/Notification/NotificationDispatcher.php' => 'the in-app channel of a dispatch, recorded with it',
        ]],
        'push send' => ['/->\s*sendToUser(WithAttempts)?\s*\(/', [
            'src/Service/OutboundNotificationService.php' => 'records the push attempt of a dispatch (recordPushSend)',
        ]],
        'recordPushSend' => ['/->\s*recordPushSend\s*\(/', [
            'src/Notification/NotificationDispatcher.php' => 'push channel of a dispatch',
        ]],
    ];

    public function test_in_app_and_push_only_through_the_dispatcher(): void
    {
        $offenders = [];
        foreach ($this->sources() as $file => $code) {
            foreach (self::RULES as $name => [$regex, $allowed]) {
                if (preg_match($regex, $code) && !isset($allowed[$file])) {
                    $offenders[] = "$file uses $name";
                }
            }
        }

        self::assertSame([], $offenders, 'A user notification (in-app / push) is a NotificationIntent handed to NotificationDispatcher.');
    }

    public function test_exceptions_are_still_needed(): void
    {
        foreach (self::RULES as $name => [$regex, $allowed]) {
            foreach ($allowed as $file => $reason) {
                self::assertNotSame('', trim($reason));
                self::assertMatchesRegularExpression($regex, $this->sources()[$file] ?? '', "$file no longer uses $name — stale exception");
            }
        }
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $root  = realpath(__DIR__ . '/../..');
        $cache = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $cache[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $code;
        }

        return $cache;
    }
}
