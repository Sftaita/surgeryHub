import * as React from "react";
import { Alert, Box, FormControlLabel, Radio, RadioGroup, Stack, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";

import { SearchableSelect, type SearchableOption } from "../../planning-v2/components/SearchableSelect";
import { candidateGhostLabel } from "../../planning-v2/api/eligibilityReasons";
import { fetchDispatchCandidates, type DispatchMode, type DispatchSlot, type MissionDispatchChoice } from "./missionDispatch.api";

const MODES: Array<{ mode: DispatchMode; label: string; help: string }> = [
  {
    mode: "POOL",
    label: "Proposer au pool",
    help: "Visible par tous les instrumentistes éligibles ; le premier qui la prend est attribué.",
  },
  {
    mode: "TARGETED",
    label: "Demander à un instrumentiste",
    help: "L'instrumentiste reçoit une demande et doit l'accepter ou la refuser. La mission reste à couvrir en attendant.",
  },
  {
    mode: "DIRECT",
    label: "Attribuer directement",
    help: "Accord déjà obtenu (téléphone, WhatsApp, au bloc…) : la mission est attribuée tout de suite, l'instrumentiste reçoit une simple confirmation.",
  },
];

type Props = {
  value: MissionDispatchChoice;
  onChange: (next: MissionDispatchChoice) => void;
  /** The mission's slot — candidates are the instrumentists of THIS site, evaluated for THIS time. */
  slot: DispatchSlot | null;
  disabled?: boolean;
};

/**
 * D-125 — one shared UI for "how is this mission put into play", used identically by the
 * manual creation wizard, the mission page and the acceptance of a surgeon request (never
 * three implementations). The instrumentist picker lists the mission site's instrumentists
 * by name (id stays technical), with the backend's eligibility: a temporarily ineligible
 * person is shown disabled with the reason, never filtered by a client-side rule.
 */
export default function MissionDispatchFields({ value, onChange, slot, disabled }: Props) {
  const needsInstrumentist = value.mode !== "POOL";

  const candidatesQuery = useQuery({
    queryKey: ["dispatch-candidates", slot?.siteId, slot?.startAt, slot?.endAt, slot?.missionId ?? null],
    queryFn: () => fetchDispatchCandidates(slot as DispatchSlot),
    enabled: needsInstrumentist && slot !== null,
    staleTime: 30_000,
  });

  const options: SearchableOption[] = React.useMemo(
    () =>
      (candidatesQuery.data ?? []).map((c) => ({
        id: c.id,
        label: c.name,
        sub: c.email,
        disabled: !c.selectable,
        badge: c.selectable ? undefined : (candidateGhostLabel(c) ?? undefined),
      })),
    [candidatesQuery.data],
  );

  return (
    <Stack spacing={1.5}>
      <RadioGroup
        value={value.mode}
        onChange={(e) => onChange({ mode: e.target.value as DispatchMode, instrumentistId: value.instrumentistId })}
      >
        {MODES.map((m) => (
          <FormControlLabel
            key={m.mode}
            value={m.mode}
            disabled={disabled}
            control={<Radio size="small" />}
            sx={{ alignItems: "flex-start", mb: 0.5 }}
            label={
              <Box sx={{ pt: 0.75 }}>
                <Typography variant="body2" fontWeight={600}>{m.label}</Typography>
                <Typography variant="caption" color="text.secondary">{m.help}</Typography>
              </Box>
            }
          />
        ))}
      </RadioGroup>

      {needsInstrumentist && (
        <Box>
          {slot === null ? (
            <Alert severity="info">Choisissez d'abord le site et l'horaire de la mission.</Alert>
          ) : candidatesQuery.isError ? (
            <Alert severity="error">Impossible de charger les instrumentistes du site.</Alert>
          ) : (
            <SearchableSelect
              label="Instrumentiste"
              placeholder={candidatesQuery.isLoading ? "Chargement…" : "Rechercher par prénom ou nom…"}
              options={options}
              value={value.instrumentistId}
              onChange={(id) => onChange({ mode: value.mode, instrumentistId: id })}
              disabled={disabled || candidatesQuery.isLoading}
              required
            />
          )}
          {candidatesQuery.isSuccess && options.length === 0 && (
            <Typography variant="caption" color="text.secondary">
              Aucun instrumentiste n'est rattaché à ce site.
            </Typography>
          )}
        </Box>
      )}
    </Stack>
  );
}
