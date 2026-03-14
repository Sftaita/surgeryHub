<?php

namespace App\Dto\Request\Response;

final class InstrumentistSiteMembershipResponse
{
    public function __construct(
        public int $id,
        public SiteSummaryResponse $site,
        public string $siteRole,
    ) {
    }
}