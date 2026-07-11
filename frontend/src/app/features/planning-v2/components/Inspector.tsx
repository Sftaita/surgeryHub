import * as React from "react";
import { Box, Button, Stack, TextField, Typography } from "@mui/material";
import CloseOutlinedIcon from "@mui/icons-material/CloseOutlined";
import DeleteOutlineOutlinedIcon from "@mui/icons-material/DeleteOutlineOutlined";
import UnpublishedOutlinedIcon from "@mui/icons-material/UnpublishedOutlined";
import AddCircleOutlineOutlinedIcon from "@mui/icons-material/AddCircleOutlineOutlined";

import type { PreviewLineV2 } from "../api/planningV2.types";
import type { FreedInstrumentist } from "../api/generatePreviewGrouping";
import { SearchableSelect, type SearchableOption } from "./SearchableSelect";
import { planningV2Colors, planningV2Radii } from "../theme/tokens";

export interface InspectorAccent {
  main: string;
  hover: string;
  bg: string;
}

export interface NewMissionDraft {
  date: string;
  startTime: string;
  endTime: string;
  missionType: "BLOCK" | "CONSULTATION";
  surgeonId: number | null;
  siteId: number | null;
  instrumentistId: number | null;
}

interface InspectorProps {
  /** null = nothing selected — shows the empty state. */
  line: PreviewLineV2 | null;
  isDirty: boolean;
  isModification: boolean;
  instrumentistOptions: SearchableOption[];
  freedInstrumentists: FreedInstrumentist[];
  absencesLoading: boolean;
  accent: InspectorAccent;
  onInstrumentistChange: (newId: number | null) => void;
  onScheduleChange: (patch: { startTime?: string; endTime?: string }) => void;
  onCancelMission: () => void;
  onReleaseMission: () => void;
  onReset: () => void;
  // ── Create-new-mission mode (Modification only) ──────────────────────────
  isCreating: boolean;
  surgeonOptions: SearchableOption[];
  siteOptions: SearchableOption[];
  onStartCreate: () => void;
  onSubmitCreate: (draft: NewMissionDraft) => void;
  onCancelCreate: () => void;
}

const MISSION_TYPE_LABEL: Record<string, string> = { BLOCK: "Bloc opératoire", CONSULTATION: "Consultation" };

export function Inspector({
  line, isDirty, isModification, instrumentistOptions, freedInstrumentists, absencesLoading, accent,
  onInstrumentistChange, onScheduleChange, onCancelMission, onReleaseMission, onReset,
  isCreating, surgeonOptions, siteOptions, onStartCreate, onSubmitCreate, onCancelCreate,
}: InspectorProps) {
  const [draft, setDraft] = React.useState<NewMissionDraft>({
    date: "", startTime: "08:00", endTime: "13:00", missionType: "BLOCK",
    surgeonId: null, siteId: null, instrumentistId: null,
  });

  React.useEffect(() => {
    if (isCreating) {
      setDraft({ date: "", startTime: "08:00", endTime: "13:00", missionType: "BLOCK", surgeonId: null, siteId: null, instrumentistId: null });
    }
  }, [isCreating]);

  const canSubmitCreate = draft.date !== "" && draft.surgeonId !== null && draft.siteId !== null;

  return (
    <Box
      sx={{
        width: 320, flexShrink: 0, position: "sticky", top: 16, alignSelf: "flex-start",
        bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg,
        overflow: "hidden", maxHeight: "calc(100vh - 32px)", display: "flex", flexDirection: "column",
      }}
    >
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 2, py: 1.5, borderBottom: `1px solid ${planningV2Colors.divider}`, bgcolor: "#FAFBFC" }}>
        <Typography sx={{ fontSize: 12.5, fontWeight: 700, color: planningV2Colors.textTitle }}>
          {isCreating ? "Nouvelle mission" : "Détail"}
        </Typography>
        {isModification && !isCreating && (
          <Button
            size="small" startIcon={<AddCircleOutlineOutlinedIcon sx={{ fontSize: 15 }} />}
            onClick={onStartCreate}
            sx={{ fontSize: 11.5, fontWeight: 700, textTransform: "none", color: accent.main, minWidth: 0, px: 1 }}
          >
            Ajouter
          </Button>
        )}
      </Stack>

      <Box sx={{ p: 2, overflowY: "auto" }}>
        {isCreating ? (
          <Stack spacing={1.75}>
            <TextField
              label="Date" type="date" size="small" fullWidth InputLabelProps={{ shrink: true }}
              value={draft.date} onChange={(e) => setDraft((d) => ({ ...d, date: e.target.value }))}
            />
            <Stack direction="row" spacing={1.25}>
              <TextField
                label="Début" type="time" size="small" fullWidth InputLabelProps={{ shrink: true }}
                value={draft.startTime} onChange={(e) => setDraft((d) => ({ ...d, startTime: e.target.value }))}
              />
              <TextField
                label="Fin" type="time" size="small" fullWidth InputLabelProps={{ shrink: true }}
                value={draft.endTime} onChange={(e) => setDraft((d) => ({ ...d, endTime: e.target.value }))}
              />
            </Stack>
            <Stack direction="row" spacing={1}>
              {(["BLOCK", "CONSULTATION"] as const).map((t) => (
                <Box
                  key={t} component="button" type="button" onClick={() => setDraft((d) => ({ ...d, missionType: t }))}
                  sx={{
                    flex: 1, height: 34, borderRadius: planningV2Radii.button, border: `1px solid ${draft.missionType === t ? accent.main : planningV2Colors.cardBorder}`,
                    bgcolor: draft.missionType === t ? accent.bg : "#fff", color: draft.missionType === t ? accent.main : planningV2Colors.textBody,
                    fontSize: 12.5, fontWeight: 600, cursor: "pointer", fontFamily: "inherit",
                  }}
                >
                  {MISSION_TYPE_LABEL[t]}
                </Box>
              ))}
            </Stack>
            <SearchableSelect label="Chirurgien" required options={surgeonOptions} value={draft.surgeonId} onChange={(id) => setDraft((d) => ({ ...d, surgeonId: id }))} placeholder="Rechercher un chirurgien…" />
            <SearchableSelect label="Site" required options={siteOptions} value={draft.siteId} onChange={(id) => setDraft((d) => ({ ...d, siteId: id }))} placeholder="Rechercher un site…" />
            <SearchableSelect label="Instrumentiste" options={instrumentistOptions} value={draft.instrumentistId} onChange={(id) => setDraft((d) => ({ ...d, instrumentistId: id }))} placeholder="Rechercher un instrumentiste…" />
            <Stack direction="row" spacing={1} sx={{ pt: 1 }}>
              <Button fullWidth size="small" color="inherit" onClick={onCancelCreate} sx={{ textTransform: "none" }}>Annuler</Button>
              <Button
                fullWidth size="small" variant="contained" disableElevation disabled={!canSubmitCreate}
                onClick={() => onSubmitCreate(draft)}
                sx={{ textTransform: "none", fontWeight: 600, bgcolor: accent.main, "&:hover": { bgcolor: accent.hover } }}
              >
                Ajouter
              </Button>
            </Stack>
          </Stack>
        ) : line === null ? (
          <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, textAlign: "center", py: 4 }}>
            Sélectionnez une ligne pour l&apos;éditer.
          </Typography>
        ) : (
          <Stack spacing={1.75}>
            <Box>
              <Typography sx={{ fontSize: 11, color: planningV2Colors.textSecondary }}>Chirurgien</Typography>
              <Typography sx={{ fontSize: 13.5, fontWeight: 700 }}>{line.surgeonName}</Typography>
            </Box>
            <Box>
              <Typography sx={{ fontSize: 11, color: planningV2Colors.textSecondary }}>Site · Type</Typography>
              <Typography sx={{ fontSize: 13 }}>{line.siteName ?? "—"} · {MISSION_TYPE_LABEL[line.missionType] ?? line.missionType}</Typography>
            </Box>

            {isModification ? (
              <Stack direction="row" spacing={1.25}>
                <TextField
                  label="Début" type="time" size="small" fullWidth InputLabelProps={{ shrink: true }}
                  value={line.startTime} onChange={(e) => onScheduleChange({ startTime: e.target.value })}
                />
                <TextField
                  label="Fin" type="time" size="small" fullWidth InputLabelProps={{ shrink: true }}
                  value={line.endTime} onChange={(e) => onScheduleChange({ endTime: e.target.value })}
                />
              </Stack>
            ) : (
              <Box>
                <Typography sx={{ fontSize: 11, color: planningV2Colors.textSecondary }}>Horaire</Typography>
                <Typography sx={{ fontSize: 13, fontVariantNumeric: "tabular-nums" }}>{line.startTime} – {line.endTime}</Typography>
              </Box>
            )}

            <Box>
              <SearchableSelect
                label="Instrumentiste"
                options={instrumentistOptions}
                value={line.instrumentistId}
                onChange={onInstrumentistChange}
                placeholder="Rechercher un instrumentiste…"
              />
              {absencesLoading && (
                <Typography sx={{ fontSize: 11, color: planningV2Colors.textSecondary, mt: 0.5 }}>
                  Vérification des congés…
                </Typography>
              )}
            </Box>

            {freedInstrumentists.length > 0 && (
              <Box>
                <Typography sx={{ fontSize: 11, fontWeight: 700, color: planningV2Colors.textSecondary, mb: 0.5 }}>
                  Libérés disponibles
                </Typography>
                <Stack spacing={0.75}>
                  {freedInstrumentists.map((f) => (
                    <Stack
                      key={f.id} direction="row" alignItems="center" justifyContent="space-between"
                      sx={{ px: 1, py: 0.75, bgcolor: "#F0FDF4", border: "1px solid #BBF7D0", borderRadius: planningV2Radii.button }}
                    >
                      <Box>
                        <Typography sx={{ fontSize: 12, fontWeight: 600 }}>{f.name}</Typography>
                        <Typography sx={{ fontSize: 10, color: "#2C7D5F" }}>{f.reason}</Typography>
                      </Box>
                      <Button size="small" variant="text" color="success" onClick={() => onInstrumentistChange(f.id)}>
                        Assigner
                      </Button>
                    </Stack>
                  ))}
                </Stack>
              </Box>
            )}

            <Stack spacing={0.75} sx={{ pt: 1, borderTop: `1px dashed ${planningV2Colors.divider}` }}>
              {isModification && line.instrumentistId !== null && (
                <Button
                  size="small" color="inherit" startIcon={<UnpublishedOutlinedIcon sx={{ fontSize: 15 }} />}
                  onClick={onReleaseMission}
                  sx={{ justifyContent: "flex-start", textTransform: "none", fontSize: 12.5 }}
                >
                  Remettre au pool (ouverte)
                </Button>
              )}
              <Button
                size="small" color="error" startIcon={<DeleteOutlineOutlinedIcon sx={{ fontSize: 15 }} />}
                onClick={onCancelMission}
                sx={{ justifyContent: "flex-start", textTransform: "none", fontSize: 12.5 }}
              >
                {isModification
                  ? (line.existingMissionId === null ? "Supprimer" : "Annuler la mission")
                  : "Ignorer cette ligne"}
              </Button>
              <Button
                size="small" color="inherit" disabled={!isDirty} startIcon={<CloseOutlinedIcon sx={{ fontSize: 15 }} />}
                onClick={onReset}
                sx={{ justifyContent: "flex-start", textTransform: "none", fontSize: 12.5 }}
              >
                Réinitialiser la ligne
              </Button>
            </Stack>
          </Stack>
        )}
      </Box>
    </Box>
  );
}
