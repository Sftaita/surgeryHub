import { Box, Stack, Typography } from "@mui/material";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";

import type { SurgeonSchedulePostV2 } from "../api/planningV2.types";
import { alertSeverityTokens, planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

/**
 * "Fin de poste proche" is NOT a PlanningAlert — there is no backend entity for it (the
 * date math is purely client-side, derived from each post's stored endDate). Per the
 * Batch 13 launch-safety decision, this card lives in the Postes tab (not Alertes) and
 * carries no action buttons at all, so it can never be confused with a real backend
 * alert that requires acknowledge/resolve/reassign/open-as-available. To extend the
 * post, use the normal "Modifier" action on its PostCard.
 */
interface Props {
  post: SurgeonSchedulePostV2;
}

export function EndingSoonAlertCard({ post }: Props) {
  const sev = alertSeverityTokens.info;
  const endLabel = post.endDate ? new Date(post.endDate + "T00:00:00").toLocaleDateString("fr-FR", { day: "numeric", month: "long" }) : "";

  return (
    <Stack direction="row" sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.card, boxShadow: planningV2Shadows.card, overflow: "hidden" }}>
      <Box sx={{ width: 4, alignSelf: "stretch", bgcolor: sev.dot, flex: "none" }} />
      <Stack direction="row" alignItems="center" spacing={1.5} sx={{ flex: 1, minWidth: 0, p: 1.75 }}>
        <Box sx={{ display: "inline-flex", alignItems: "center", gap: 0.6, fontSize: 11, fontWeight: 700, letterSpacing: "0.03em", textTransform: "uppercase", color: sev.fg, bgcolor: sev.bg, px: 1, py: 0.35, borderRadius: planningV2Radii.pill, flex: "none" }}>
          <InfoOutlinedIcon sx={{ fontSize: 13 }} />
          Information
        </Box>
        <Typography sx={{ fontSize: 13, color: planningV2Colors.textStrong, flex: 1, minWidth: 0 }}>
          Ce poste arrive bientôt à échéance. <Box component="span" sx={{ color: planningV2Colors.textSecondary }}>
            {post.surgeon.name ?? post.surgeon.email} · {post.site.name} · jusqu'au {endLabel}
          </Box>
        </Typography>
      </Stack>
    </Stack>
  );
}
