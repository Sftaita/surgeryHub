import { Box, Divider, Stack, Typography } from "@mui/material";

import MissionDispatchFields from "../dispatch/MissionDispatchFields";
import type { DispatchSlot, MissionDispatchChoice } from "../dispatch/missionDispatch.api";

type FormState = {
  siteId?: number;
  surgeonUserId?: number;
  type: "BLOCK" | "CONSULTATION";
  schedulePrecision: "EXACT" | "APPROXIMATE";
  startLocal: string;
  endLocal: string;
  dispatch: MissionDispatchChoice;
};

type Props = {
  state: FormState;
  sites: Array<{ id: number; name: string }>;
  surgeons: Array<{ id: number; label: string }>;
  onChange: (next: Partial<FormState>) => void;
  /** D-125 — null until site + valid schedule are known (candidates are site/slot-scoped). */
  slot: DispatchSlot | null;
};

function labelSite(sites: Props["sites"], id?: number) {
  const found = sites.find((s) => s.id === id);
  return found ? found.name : "—";
}

function labelUser(users: Array<{ id: number; label: string }>, id?: number) {
  const found = users.find((u) => u.id === id);
  return found ? found.label : "—";
}

function labelType(type: FormState["type"]) {
  switch (type) {
    case "BLOCK":
      return "Bloc opératoire";
    case "CONSULTATION":
      return "Consultation";
    default:
      return type;
  }
}

function labelPrecision(precision: FormState["schedulePrecision"]) {
  switch (precision) {
    case "EXACT":
      return "Horaire exact";
    case "APPROXIMATE":
      return "Horaire estimé (à confirmer)";
    default:
      return precision;
  }
}

export default function MissionCreateSummary(props: Props) {
  const { state, sites, surgeons, onChange, slot } = props;

  return (
    <Box>
      <Typography variant="subtitle1" sx={{ mb: 1 }}>
        Récapitulatif (lecture seule)
      </Typography>

      <Stack spacing={1.25}>
        <Typography>
          <strong>Site :</strong> {labelSite(sites, state.siteId)}
        </Typography>

        <Typography>
          <strong>Chirurgien :</strong>{" "}
          {labelUser(surgeons, state.surgeonUserId)}
        </Typography>

        <Typography>
          <strong>Activité :</strong> {labelType(state.type)}
        </Typography>

        <Typography>
          <strong>Précision :</strong> {labelPrecision(state.schedulePrecision)}
        </Typography>

        <Typography>
          <strong>Début :</strong> {state.startLocal || "—"}
        </Typography>

        <Typography>
          <strong>Fin :</strong> {state.endLocal || "—"}
        </Typography>

        <Divider sx={{ my: 1 }} />

        <Typography variant="subtitle2">
          Diffusion (si tu cliques « Créer et diffuser »)
        </Typography>

        <MissionDispatchFields
          value={state.dispatch}
          onChange={(dispatch) => onChange({ dispatch })}
          slot={slot}
        />
      </Stack>
    </Box>
  );
}
