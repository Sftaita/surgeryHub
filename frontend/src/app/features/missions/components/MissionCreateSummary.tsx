import {
  Autocomplete,
  Box,
  CircularProgress,
  Divider,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";

import type { PublishScope } from "../api/missions.requests";
import { fetchInstrumentists } from "../api/missions.api";
import type {
  InstrumentistListItem,
  InstrumentistsResponse,
} from "../api/missions.types";

type FormState = {
  siteId?: number;
  surgeonUserId?: number;
  type: "BLOCK" | "CONSULTATION";
  schedulePrecision: "EXACT" | "APPROXIMATE";
  startLocal: string;
  endLocal: string;
  publishScope: PublishScope;
  targetUserId?: number;
};

type Props = {
  state: FormState;
  sites: Array<{ id: number; name: string }>;
  surgeons: Array<{ id: number; label: string }>;
  onChange: (next: Partial<FormState>) => void;
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

function instrumentistLabel(u: InstrumentistListItem): string {
  const dn = (u.displayName ?? "").trim();
  if (dn) return dn;

  const fn = (u.firstname ?? "").trim();
  const ln = (u.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  return full || u.email || `User #${u.id}`;
}

export default function MissionCreateSummary(props: Props) {
  const { state, sites, surgeons, onChange } = props;

  const instrumentistsQ = useQuery<InstrumentistsResponse>({
    queryKey: ["instrumentists", { page: 1, limit: 200 }],
    queryFn: () => fetchInstrumentists({ page: 1, limit: 200 }),
    enabled: state.publishScope === "TARGETED",
  });

  const instrumentists = instrumentistsQ.data?.items ?? [];

  const selectedInstrumentist =
    state.publishScope === "TARGETED" && state.targetUserId
      ? instrumentists.find((u) => u.id === state.targetUserId) ?? null
      : null;

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
          Publication (si tu cliques « Créer et publier »)
        </Typography>

        <FormControl fullWidth>
          <InputLabel id="publish-scope-label">Destination</InputLabel>
          <Select
            labelId="publish-scope-label"
            label="Destination"
            value={state.publishScope}
            onChange={(e) =>
              onChange({
                publishScope: e.target.value as PublishScope,
                targetUserId: undefined,
              })
            }
          >
            <MenuItem value="POOL">Pool d’instrumentistes</MenuItem>
            <MenuItem value="TARGETED">Instrumentiste ciblé</MenuItem>
          </Select>
        </FormControl>

        {state.publishScope === "TARGETED" ? (
          <Box>
            <Autocomplete
              options={instrumentists}
              value={selectedInstrumentist}
              loading={instrumentistsQ.isLoading}
              getOptionLabel={(o) => instrumentistLabel(o)}
              isOptionEqualToValue={(a, b) => a.id === b.id}
              // active=false affiché mais non sélectionnable
              getOptionDisabled={(o) => o.active === false}
              onChange={(_, value) =>
                onChange({ targetUserId: value ? value.id : undefined })
              }
              renderInput={(params) => (
                <TextField
                  {...params}
                  label="Instrumentiste cible"
                  placeholder="Rechercher…"
                  InputProps={{
                    ...params.InputProps,
                    endAdornment: (
                      <>
                        {instrumentistsQ.isLoading ? (
                          <CircularProgress size={18} />
                        ) : null}
                        {params.InputProps.endAdornment}
                      </>
                    ),
                  }}
                />
              )}
            />

            {instrumentistsQ.isError ? (
              <Typography variant="body2" color="error" sx={{ mt: 1 }}>
                Impossible de charger les instrumentistes (/api/instrumentists).
              </Typography>
            ) : null}

            <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
              Les instrumentistes peuvent être multi-sites. L’éligibilité à une
              publication TARGETED est décidée par le backend.
            </Typography>
          </Box>
        ) : null}
      </Stack>
    </Box>
  );
}
