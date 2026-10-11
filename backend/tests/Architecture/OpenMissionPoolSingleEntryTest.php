<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * H3 (D-129) — guardrail: OpenMissionPoolNotifier is the ONLY way to solicit the pool about
 * an OPEN mission. Before H3, five handlers each owned a divergent pool implementation
 * (site-wide push with no eligibility, per-site union of candidates, a parallel D-108 email
 * fallback…). This test reads the PHP source of src/ (comments stripped, nothing
 * autoloaded) and fails if any of those shortcuts reappears.
 */
final class OpenMissionPoolSingleEntryTest extends TestCase
{
    private const NOTIFIER = 'src/Notification/OpenMissionPoolNotifier.php';

    /** Files allowed to NAME the type without sending it (declaration, defaults, routing, labels). */
    private const OPEN_MISSION_AVAILABLE_ALLOWED = [
        self::NOTIFIER,
        'src/Enum/NotificationType.php',
        'src/Service/DefaultNotificationPreferenceResolver.php',
        'src/Service/NotificationTargetResolver.php',
        'src/Activity/Recorder/NotificationActivityRecorder.php',
    ];

    public function test_only_the_notifier_builds_open_mission_available(): void
    {
        $offenders = [];
        foreach ($this->sources() as $file => $code) {
            if (str_contains($code, 'OPEN_MISSION_AVAILABLE') && !in_array($file, self::OPEN_MISSION_AVAILABLE_ALLOWED, true)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'OPEN_MISSION_AVAILABLE must only be produced by OpenMissionPoolNotifier.');
    }

    public function test_the_pool_push_type_exists_only_in_the_notifier(): void
    {
        $offenders = array_keys(array_filter(
            $this->sources(),
            static fn (string $code, string $file) => str_contains($code, 'PLANNING_OPEN_MISSIONS_AVAILABLE') && $file !== self::NOTIFIER,
            ARRAY_FILTER_USE_BOTH,
        ));

        self::assertSame([], $offenders, 'No handler may push the pool directly any more.');
    }

    public function test_the_aggregated_per_site_eligibility_is_gone_and_per_mission_eligibility_is_used_only_by_the_notifier(): void
    {
        $perSite    = [];
        $perMission = [];
        foreach ($this->sources() as $file => $code) {
            if (preg_match('/->findEligible\s*\(/', $code)) {
                $perSite[] = $file;
            }
            if (preg_match('/->findEligibleByMission\s*\(/', $code) && $file !== self::NOTIFIER) {
                $perMission[] = $file;
            }
        }

        self::assertSame([], $perSite, 'The per-site union findEligible() must never come back.');
        self::assertSame([], $perMission, 'Pool candidates are computed by OpenMissionPoolNotifier only.');
    }

    public function test_no_bulk_push_bypassing_the_preference_resolver(): void
    {
        $offenders = [];
        foreach ($this->sources() as $file => $code) {
            if (preg_match('/function\s+sendToUsers\s*\(|->sendToUsers\s*\(|sendToSiteInstrumentists/', $code)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'Every push goes through NotificationDispatcher, one recipient at a time, after the resolver.');
    }

    public function test_the_former_pool_handlers_own_no_pool_logic(): void
    {
        foreach ([
            'src/MessageHandler/MissionLifecycleChangedMessageHandler.php',
            'src/MessageHandler/PlanningDeployPdfsMessageHandler.php',
            'src/MessageHandler/MissionPublishedMessageHandler.php',
            'src/MessageHandler/AbsenceMissionsReactedMessageHandler.php',
            'src/MessageHandler/PlanningModificationAppliedMessageHandler.php',
        ] as $file) {
            $code = $this->sources()[$file];
            // The only eligibility call a former pool handler may keep is the D-124 personal
            // offer's single evaluate() (one person, one mission) — never a candidate list.
            preg_match_all('/->eligibilityService->(\w+)\s*\(/', $code, $calls);
            self::assertSame([], array_values(array_diff(array_unique($calls[1]), ['evaluate'])), "$file must not compute pool candidates itself.");
            if ($file !== 'src/MessageHandler/MissionLifecycleChangedMessageHandler.php') {
                self::assertStringNotContainsString('MissionEligibilityService', $code, "$file must not depend on eligibility any more.");
            }
            self::assertStringNotContainsString('ABSENCE_POOL_MISSION_AVAILABLE', $code, "$file must not revive the D-108 parallel fallback.");
        }
        self::assertStringContainsString('OpenMissionPoolNotifier', $this->sources()['src/MessageHandler/PlanningDeployPdfsMessageHandler.php']);
        self::assertStringContainsString('OpenMissionPoolNotifier', $this->sources()['src/MessageHandler/MissionPublishedMessageHandler.php']);
        self::assertStringContainsString('OpenMissionPoolNotifier', $this->sources()['src/MessageHandler/MissionLifecycleChangedMessageHandler.php']);
    }

    /** @return array<string, string> repo-relative path => code without comments */
    private function sources(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $root = realpath(__DIR__ . '/../..');
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
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $cache[$relative] = $code;
        }

        return $cache;
    }
}
