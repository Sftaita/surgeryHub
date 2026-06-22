import * as React from "react";
import { Box, Chip, Stack, Typography } from "@mui/material";
import AutoAwesomeOutlinedIcon from "@mui/icons-material/AutoAwesomeOutlined";

import { PlanningV2Tabs, type PlanningV2TabKey } from "../../../features/planning-v2/components/PlanningV2Tabs";
import { SurgeonPostsTab } from "../../../features/planning-v2/tabs/SurgeonPostsTab";
import { GeneratePlanningTab } from "../../../features/planning-v2/tabs/GeneratePlanningTab";
import { PlanningAlertsTab } from "../../../features/planning-v2/tabs/PlanningAlertsTab";
import { PlanningSettingsTab } from "../../../features/planning-v2/tabs/PlanningSettingsTab";
import { useQuery } from "@tanstack/react-query";
import { getAlerts } from "../../../features/planning-v2/api/planningV2.api";

/**
 * Planning V2 — the official manager planning UI as of the Batch 13 cutover. The sidebar
 * "Planning" entry and the bare /app/m/planning route both point here now. V1's pages
 * (PlanningTemplatesPage, PlanningGeneratePage, PlanningVersionsListPage,
 * PlanningVersionDetailPage, PlanningSchedulePage, SpecialtiesPage) are kept reachable
 * by direct URL only — no longer linked from the sidebar — as a rollback fallback per
 * docs/decisions.md's V2 cutover ADR.
 */
export default function PlanningV2Page() {
  const [tab, setTab] = React.useState<PlanningV2TabKey>("posts");

  // Light badge count — open alerts only, just to draw attention to the tab. Failure
  // to load is silent here (the Alertes tab itself shows the real error state).
  const openAlertsQuery = useQuery({
    queryKey: ["planning-v2", "alerts", "badge-count"],
    queryFn: () => getAlerts({ status: "OPEN", limit: 1 }),
    staleTime: 30_000,
  });

  return (
    <Stack spacing={2.5}>
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Stack direction="row" alignItems="center" spacing={1.25}>
          <Typography variant="h6" fontWeight={700}>Planning</Typography>
          <Chip
            size="small"
            icon={<AutoAwesomeOutlinedIcon fontSize="small" />}
            label="Planning V2 — nouveau module"
            color="primary"
            variant="outlined"
          />
        </Stack>
      </Stack>

      <Box sx={{ bgcolor: "background.paper", borderRadius: 2, border: "1px solid", borderColor: "divider" }}>
        <PlanningV2Tabs value={tab} onChange={setTab} alertBadgeCount={openAlertsQuery.data?.total} />
        <Box sx={{ p: { xs: 2, md: 3 } }}>
          {tab === "posts" && <SurgeonPostsTab />}
          {tab === "generate" && <GeneratePlanningTab />}
          {tab === "alerts" && <PlanningAlertsTab />}
          {tab === "settings" && <PlanningSettingsTab />}
        </Box>
      </Box>
    </Stack>
  );
}
