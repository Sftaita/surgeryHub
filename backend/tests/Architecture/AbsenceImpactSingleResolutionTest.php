<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * H4 (D-130) — guardrails of the absence-impact chain. Reads the PHP source of src/ (comments
 * stripped, nothing autoloaded) and fails if:
 *   - an absence notification bypasses NotificationDispatcher (an untraced
 *     SendBillingEmailMessage, a hand-built NotificationEvent, a direct push);
 *   - the N1 / N2 information types are produced anywhere but AbsenceImpactNotifier;
 *   - the N1 / N2 / N3 resolution (habitual occurrences of a post vs existing Missions) is
 *     re-implemented outside AbsenceImpactResolver — the source of "N1 sent although a DRAFT
 *     or a published mission exists for the occurrence";
 *   - an absence service computes pool candidates itself (H3 owns the pool).
 */
final class AbsenceImpactSingleResolutionTest extends TestCase
{
    /** The absence domain: every file that informs people about an absence. */
    private const ABSENCE_NOTIFICATION_FILES = [
        'src/Absence/AbsenceImpactNotifier.php',
        'src/Absence/AbsenceImpactCoordinator.php',
        'src/MessageHandler/AbsenceImpactNotificationMessageHandler.php',
        'src/MessageHandler/AbsenceMissionsReactedMessageHandler.php',
        'src/MessageHandler/AbsenceImpactSummaryMessageHandler.php',
        'src/MessageHandler/AbsenceSelfDeclaredMessageHandler.php',
        'src/MessageHandler/PlanningRestoredAfterAbsenceMessageHandler.php',
        'src/Service/AbsenceImpactService.php',
        'src/Service/AbsenceMissionReactionService.php',
        'src/Service/AbsenceImpactSummaryService.php',
        'src/Service/AbsenceImpactReconciliationService.php',
        'src/Service/SurgeonAbsenceOccurrenceImpactService.php',
        'src/Service/InstrumentistAbsenceOccurrenceImpactService.php',
    ];

    public function test_absence_notifications_never_bypass_the_dispatcher(): void
    {
        $offenders = [];
        foreach (self::ABSENCE_NOTIFICATION_FILES as $file) {
            $code = $this->sources()[$file] ?? null;
            self::assertNotNull($code, "$file is listed but missing — update the list");
            if (preg_match('/SendBillingEmailMessage|new\s+NotificationEvent\b|->sendToUser(WithAttempts)?\s*\(|->recordPushSend\s*\(|PlanningAlertRaisedMessage/', $code)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'Absence notifications go through NotificationDispatcher only (channels from preferences, every outcome traced).');
    }

    public function test_n1_n2_information_types_are_produced_only_by_the_impact_notifier(): void
    {
        $allowed = [
            'src/Absence/AbsenceImpactNotifier.php',
            'src/Enum/NotificationType.php',
            'src/Service/DefaultNotificationPreferenceResolver.php',
            'src/Service/NotificationTargetResolver.php',
            'src/Activity/Recorder/NotificationActivityRecorder.php',
        ];
        $offenders = [];
        foreach ($this->sources() as $file => $code) {
            if (preg_match('/ABSENCE_OCCURRENCE_(CANCELLED|UNCOVERED)\b/', $code) && !in_array($file, $allowed, true)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders);
    }

    public function test_the_n1_n2_n3_resolution_lives_only_in_the_resolver(): void
    {
        // theoreticalOccurrenceDates() = "which habitual occurrences does this post have".
        // Outside the generator itself, only the resolver may turn that into absence impacts;
        // D-104 restoration and D-114 block communications use it for their own, unrelated
        // purposes (they never inform people about an impact level).
        $allowed = [
            'src/Service/PlanningGeneratorServiceV2.php',
            'src/Absence/AbsenceImpactResolver.php',
            'src/Service/AbsenceImpactReconciliationService.php',
            'src/Service/SurgeonAbsenceBlockOccurrenceResolver.php',
        ];
        $offenders = [];
        foreach ($this->sources() as $file => $code) {
            if (str_contains($code, 'theoreticalOccurrenceDates(') && !in_array($file, $allowed, true)) {
                $offenders[] = $file;
            }
            if (preg_match('/function\s+hasExistingMission\s*\(/', $code)) {
                $offenders[] = $file . ' (hasExistingMission)';
            }
        }

        self::assertSame([], $offenders, 'Deciding N1 vs N2 vs N3 for an occurrence is AbsenceImpactResolver\'s job only.');
    }

    public function test_the_absence_domain_never_computes_pool_candidates(): void
    {
        $offenders = [];
        foreach (self::ABSENCE_NOTIFICATION_FILES as $file) {
            if (preg_match('/findEligibleByMission|evaluateAllCandidates|OPEN_MISSION_AVAILABLE/', $this->sources()[$file])) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'A mission an absence opens is solicited by OpenMissionPoolNotifier (H3), never here.');
    }

    /** @return array<string, string> repo-relative path => code without comments */
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
