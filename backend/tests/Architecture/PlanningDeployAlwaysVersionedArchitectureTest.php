<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * D-152 (réserve 2) — the historical « Planning V1 » branch of PlanningDeploymentService::deploy()
 * (no versionId: every DRAFT of a period becomes OPEN, without revalidation, eligibility nor
 * D-150 affectation) must stay unreachable. Only PlanningV2GenerationController may call the
 * service, and always with the version it has loaded; any new caller fails here and must be
 * classified consciously (route it through the versioned path, or neutralise the branch).
 */
final class PlanningDeployAlwaysVersionedArchitectureTest extends TestCase
{
    private const ONLY_CALLER = 'src/Controller/Api/PlanningV2GenerationController.php';

    public function test_only_the_v2_controller_deploys_and_always_with_a_version(): void
    {
        $root = dirname(__DIR__, 2);
        $callers = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'src/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/src/')));
            if ($relative === 'src/Service/PlanningDeploymentService.php') {
                continue;
            }
            $code = $this->stripComments((string) file_get_contents($file->getPathname()));
            if (preg_match('/\bPlanningDeploymentService\b/', $code) === 1) {
                $callers[] = $relative;
            }
        }

        self::assertSame([self::ONLY_CALLER], $callers, 'PlanningDeploymentService is only injected into the V2 deploy endpoint.');

        $code = $this->stripComments((string) file_get_contents($root . '/' . self::ONLY_CALLER));
        self::assertSame(1, preg_match_all('/->deploymentService->deploy\s*\(/', $code), 'a single deploy() call');
        self::assertMatchesRegularExpression('/->deploymentService->deploy\s*\((?:(?!\);).)*versionId:\s*\$version->getId\(\)/s', $code, 'deploy() always receives the loaded version');
        self::assertMatchesRegularExpression("/planningVersionId est requis/", $code, 'a request without planningVersionId is refused before deploy()');
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
