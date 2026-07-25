import { Box, Stack } from "@mui/material";
import { PersonAvatar } from "../../../ui/avatar/PersonAvatar";

const GREEN_150 = "#E7EBEF";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const SHADOW_MD = "0 2px 6px rgba(22,32,43,.06), 0 8px 20px rgba(22,32,43,.08)";

function PinIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" />
    </svg>
  );
}
function CalendarIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" />
    </svg>
  );
}

type Props = {
  surgeonName: string | null;
  surgeonPhotoUrl?: string | null;
  specialtyLabel: string | null;
  siteName: string;
  siteAddress?: string | null;
  dateLabel: string;
  scheduledLabel: string;
};

/**
 * prototypes/encodage-react/src/components/MissionReadOnlyCard.tsx — carte lecture
 * seule sous le header : reprend le chirurgien/spécialité et le site/horaire prévu
 * que EncodeHeader affichait auparavant en texte brut (voir screens/encodage/README.md
 * §Audit — nouvelle référence encodage-react). Aucun contrôle, jamais éditable ici.
 */
export function MissionReadOnlyCard({ surgeonName, surgeonPhotoUrl, specialtyLabel, siteName, siteAddress, dateLabel, scheduledLabel }: Props) {
  return (
    <Box sx={{ background: "#fff", borderRadius: "16px", padding: "14px 16px", boxShadow: SHADOW_MD }}>
      <Stack direction="row" alignItems="center" spacing="12px">
        <PersonAvatar name={surgeonName ?? "?"} photoUrl={surgeonPhotoUrl} size="md" />
        <Box sx={{ minWidth: 0 }}>
          <Box sx={{ fontSize: 15, fontWeight: 700, color: "#16202B" }}>{surgeonName ?? "—"}</Box>
          {specialtyLabel && (
            <Box sx={{ fontSize: 12.5, color: GRAY_500 }}>{specialtyLabel}</Box>
          )}
        </Box>
      </Stack>

      <Box sx={{ borderTop: "1px dashed", borderColor: GREEN_150, margin: "12px 0" }} />

      <Stack spacing="8px">
        <Stack direction="row" spacing="9px" sx={{ fontSize: 13.5, color: GRAY_700 }}>
          <Box sx={{ flexShrink: 0, mt: "1px" }}><PinIcon /></Box>
          <Box>
            <Box component="strong" sx={{ fontWeight: 700 }}>{siteName}</Box>
            {siteAddress && <> · {siteAddress}</>}
          </Box>
        </Stack>
        <Stack direction="row" spacing="9px" sx={{ fontSize: 13.5, color: GRAY_700 }}>
          <Box sx={{ flexShrink: 0, mt: "1px" }}><CalendarIcon /></Box>
          <Box sx={{ fontVariantNumeric: "tabular-nums" }}>{dateLabel} · {scheduledLabel}</Box>
        </Stack>
      </Stack>
    </Box>
  );
}
