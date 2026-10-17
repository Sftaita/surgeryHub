<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * D-150 — the instrumentist of a PUBLISHED mission is only ever written by
 * MissionAssignmentService, which keeps the MissionAssignment history exact. Reads the PHP
 * source of src/ (comments stripped) and fails as soon as a file calls `->setInstrumentist(`
 * or writes the instrumentist column in DQL/SQL without being one of the NOMINATIVE
 * exceptions below — each a precise file, an exact number of calls and a reason.
 *
 * The exceptions are either DRAFT-only writers (planning being prepared: no affectation
 * exists yet, PlanningDeploymentService opens them at publication) or setters of OTHER
 * entities that also have an "instrumentist" (MissionClaim, ratings, rates…). A new call,
 * or an exception whose count changed, fails: the change must be classified consciously.
 */
final class MissionInstrumentistWriteArchitectureTest extends TestCase
{
    /** file => [exact number of `->setInstrumentist(` calls, reason] */
    private const EXCEPTIONS = [
        'src/Service/MissionAssignmentService.php' => [3, 'D-150 — the single writer for published missions (assign / unassign / same-person continuation)'],
        'src/Service/MissionService.php' => [3, 'DRAFT only — create() builds a DRAFT, assignInstrumentistDraft() guards status DRAFT'],
        'src/Service/PlanningDraftService.php' => [2, 'DRAFT only — planning editor of a version not yet deployed'],
        'src/Service/PlanningGeneratorServiceV2.php' => [4, 'DRAFT only — generator of a version not yet deployed'],
        'src/Service/PlanningAlertActionService.php' => [2, 'DRAFT-only branches; every published status goes through MissionAssignmentService'],
        'src/Service/MissionPostDeployService.php' => [1, 'MissionClaim (pool-claim history, D-117) — not Mission'],
        'src/Service/FinancialCorrectionService.php' => [1, 'FinancialCorrection — not Mission'],
        'src/Service/InstrumentistRateService.php' => [2, 'InstrumentistRate — not Mission'],
        'src/Service/InstrumentistStatementService.php' => [1, 'InstrumentistStatement — not Mission'],
        'src/Service/RatingService.php' => [2, 'InstrumentistRating / SurgeonRatingByInstrumentist — not Mission'],
        'src/Service/SurgeonSchedulePostService.php' => [2, 'SurgeonSchedulePost (recurring post template) — not Mission'],
    ];

    /** DQL `SET m.instrumentist =` / SQL `SET instrumentist_id =` — never allowed. */
    private const BULK_WRITE = '/\bSET\b[^;\'"]*?\binstrumentist(_id)?\s*=/i';

    public function test_only_classified_files_write_an_instrumentist(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $bulk = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'src/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/src/')));
            $code = $this->stripComments((string) file_get_contents($file->getPathname()));

            $count = preg_match_all('/->setInstrumentist\s*\(/', $code);
            if ($count > 0) {
                $found[$relative] = $count;
            }
            if (preg_match(self::BULK_WRITE, $code) === 1) {
                $bulk[] = $relative;
            }
        }

        self::assertSame([], $bulk, 'No DQL/SQL bulk write of a mission instrumentist (D-150): go through MissionAssignmentService.');

        $unexpected = array_diff_key($found, self::EXCEPTIONS);
        self::assertSame([], $unexpected, 'Unclassified instrumentist write(s) — a published mission must go through MissionAssignmentService (D-150), otherwise classify the file here with its reason.');

        foreach (self::EXCEPTIONS as $file => [$expected, $reason]) {
            self::assertSame($expected, $found[$file] ?? 0, sprintf('%s: expected %d call(s) (%s) — classify the change consciously.', $file, $expected, $reason));
        }
    }

    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
