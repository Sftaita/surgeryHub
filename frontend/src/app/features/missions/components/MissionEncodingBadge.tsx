import { Box, Stack, Tooltip, Typography } from "@mui/material";
import LockIcon from "@mui/icons-material/Lock";
import LockOutlinedIcon from "@mui/icons-material/LockOutlined";

import type { MissionEncodingActor, MissionLock } from "../api/missions.types";

const ROLE_LABEL: Record<string, string> = { INSTRUMENTIST: "instrumentiste", SURGEON: "chirurgien", MANAGER: "manager" };

export function encodingActorLabel(actor: MissionEncodingActor | null | undefined): string | null {
  if (!actor?.name) return null;
  return actor.role ? `${actor.name} (${ROLE_LABEL[actor.role] ?? actor.role})` : actor.name;
}

/**
 * D-172 — cadenas d'une mission : seulement des verrous réels servis par le backend, chacun
 * avec son niveau et sa raison. Cadenas plein dès qu'un verrou comptable existe (calcul,
 * document, clôture) ; contour pour le seul verrou d'encodage (réouvrable).
 */
export function MissionLocksIndicator({ locks }: { locks: MissionLock[] }) {
  if (locks.length === 0) return null;
  const accounting = locks.some((l) => l.level !== "ENCODING");
  const summary = locks.map((l) => `${l.label} : ${l.reason}`).join(" ");
  const Icon = accounting ? LockIcon : LockOutlinedIcon;

  return (
    <Tooltip
      describeChild
      title={
        <Stack spacing={0.75} sx={{ py: 0.5 }}>
          {locks.map((l) => (
            <Box key={l.level}>
              <Typography variant="caption" fontWeight={700} display="block">{l.label}</Typography>
              <Typography variant="caption" display="block">{l.reason}</Typography>
            </Box>
          ))}
        </Stack>
      }
    >
      <Box
        component="span"
        role="img"
        tabIndex={0}
        aria-label={summary}
        data-testid="mission-lock"
        data-lock-levels={locks.map((l) => l.level).join(",")}
        sx={{ display: "inline-flex", color: accounting ? "text.primary" : "text.secondary", cursor: "help" }}
      >
        <Icon sx={{ fontSize: 16 }} />
      </Box>
    </Tooltip>
  );
}
