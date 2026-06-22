<?php

namespace App\Dto\Request\Response;

/** Mirrors PlanningDeploymentService::deploy()'s return shape exactly — no V2-specific deploy logic. */
final class DeployResponse
{
    public function __construct(
        public ?int $deploymentId,
        public int $missionCount,
        public int $openPoolCount,
    ) {
    }
}
