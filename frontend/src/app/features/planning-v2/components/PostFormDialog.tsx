import * as React from "react";
import {
  Box, Button, Dialog, IconButton, Stack, ToggleButton, ToggleButtonGroup, Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import CalendarTodayOutlinedIcon from "@mui/icons-material/CalendarTodayOutlined";
import RepeatOutlinedIcon from "@mui/icons-material/RepeatOutlined";
import PersonOutlineOutlinedIcon from "@mui/icons-material/PersonOutlineOutlined";
import PlaceOutlinedIcon from "@mui/icons-material/PlaceOutlined";

import type { SurgeonSchedulePostV2, SurgeonPostInput, MissionType, ShiftPeriod } from "../api/planningV2.types";
import type { Site } from "../../sites/api/sites.api";
import { SearchableSelect, type SearchableOption } from "./SearchableSelect";
import {
  RECURRENCE_PRESET_OPTIONS, LAUNCH_RECURRENCE_PRESET_OPTIONS, presetIsMonthly, presetToRecurrence, recurrenceToPreset,
  type RecurrencePresetKey,
} from "../api/recurrencePresets";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

export type SimplePersonOption = SearchableOption;

interface Props {
  open: boolean;
  onClose: () => void;
  onSubmit: (input: SurgeonPostInput) => void;
  submitting: boolean;
  sites: Site[];
  surgeons: SearchableOption[];
  instrumentists: SearchableOption[];
  /** When set, the dialog edits this post; otherwise it creates a new one. */
  editingPost?: SurgeonSchedulePostV2 | null;
  /** Pre-fills the surgeon field when opened from "Ajouter un poste pour {Prénom}". */
  preselectedSurgeonId?: number | null;
}

const WEEKDAYS: Array<{ value: number; label: string }> = [
  { value: 1, label: "Lun" }, { value: 2, label: "Mar" }, { value: 3, label: "Mer" },
  { value: 4, label: "Jeu" }, { value: 5, label: "Ven" },
];

const WEEKDAYS_FULL: Array<{ value: number; label: string }> = [
  { value: 1, label: "Lundi" }, { value: 2, label: "Mardi" }, { value: 3, label: "Mercredi" },
  { value: 4, label: "Jeudi" }, { value: 5, label: "Vendredi" }, { value: 6, label: "Samedi" }, { value: 7, label: "Dimanche" },
];

const MONTH_WEEKS: Array<{ value: number; label: string }> = [
  { value: 1, label: "1er" }, { value: 2, label: "2e" }, { value: 3, label: "3e" }, { value: 4, label: "4e" }, { value: 5, label: "5e" },
];

const PERIODS: Array<{ value: ShiftPeriod; label: string; sub: string }> = [
  { value: "MATIN", label: "Matin", sub: "08h–13h" },
  { value: "APRES_MIDI", label: "Après-midi", sub: "13h–18h" },
  { value: "JOURNEE", label: "Journée", sub: "08h–18h" },
];

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function PostFormDialog({
  open, onClose, onSubmit, submitting, sites, surgeons, instrumentists, editingPost, preselectedSurgeonId,
}: Props) {
  const isEdit = !!editingPost;

  const [surgeonId, setSurgeonId] = React.useState<number | null>(null);
  const [siteId, setSiteId] = React.useState<number | null>(null);
  const [type, setType] = React.useState<MissionType>("BLOCK");
  const [period, setPeriod] = React.useState<ShiftPeriod>("MATIN");
  const [instrumentistId, setInstrumentistId] = React.useState<number | null>(null);
  const [startDate, setStartDate] = React.useState(today());
  const [endDate, setEndDate] = React.useState("");
  const [preset, setPreset] = React.useState<RecurrencePresetKey>("WEEKLY");
  const [weekdays, setWeekdays] = React.useState<number[]>([1]);
  const [monthWeeks, setMonthWeeks] = React.useState<number[]>([1]);

  React.useEffect(() => {
    if (!open) return;
    if (editingPost) {
      setSurgeonId(editingPost.surgeon.id);
      setSiteId(editingPost.site.id);
      setType(editingPost.type);
      setPeriod(editingPost.period);
      setInstrumentistId(editingPost.instrumentist?.id ?? null);
      setStartDate(editingPost.startDate);
      setEndDate(editingPost.endDate ?? "");
      setPreset(recurrenceToPreset(editingPost.recurrence));
      setWeekdays(editingPost.recurrence.weekdays.length ? editingPost.recurrence.weekdays : [1]);
      setMonthWeeks(editingPost.recurrence.monthWeeks.length ? editingPost.recurrence.monthWeeks : [1]);
    } else {
      setSurgeonId(preselectedSurgeonId ?? null);
      setSiteId(null);
      setType("BLOCK");
      setPeriod("MATIN");
      setInstrumentistId(null);
      setStartDate(today());
      setEndDate("");
      setPreset("WEEKLY");
      setWeekdays([1]);
      setMonthWeeks([1]);
    }
  }, [open, editingPost, preselectedSurgeonId]);

  const monthly = presetIsMonthly(preset);
  const canSubmit = surgeonId !== null && siteId !== null && startDate !== ""
    && weekdays.length > 0 && (!monthly || monthWeeks.length > 0);

  const surgeonName = surgeons.find((s) => s.id === surgeonId)?.label ?? "";
  const siteName = sites.find((s) => s.id === siteId)?.name ?? "";
  const periodLabel = PERIODS.find((p) => p.value === period)?.label ?? "";
  const presetLabel = RECURRENCE_PRESET_OPTIONS.find((p) => p.key === preset)?.label ?? "";

  const formSummary = surgeonId && siteId
    ? `${surgeonName} · ${siteName} · ${periodLabel} · ${presetLabel.toLowerCase()}`
    : "Complétez le formulaire pour voir le résumé.";

  function handleSubmit() {
    if (!canSubmit) return;
    onSubmit({
      surgeonId: surgeonId as number,
      siteId: siteId as number,
      type,
      period,
      instrumentistId: instrumentistId ?? null,
      startDate,
      endDate: endDate || null,
      recurrence: presetToRecurrence(preset, weekdays, startDate, monthWeeks),
    });
  }

  const siteOptions: SearchableOption[] = sites.map((s) => ({ id: s.id, label: s.name }));

  return (
    <Dialog
      open={open}
      onClose={onClose}
      maxWidth="sm"
      fullWidth
      slotProps={{ paper: { sx: { borderRadius: planningV2Radii.modal, boxShadow: planningV2Shadows.modal, overflow: "hidden" } } }}
    >
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 2.75, py: 2.25, borderBottom: `1px solid ${planningV2Colors.divider}` }}>
        <Box>
          <Typography sx={{ fontSize: 16, fontWeight: 700 }}>{isEdit ? "Modifier le poste" : "Ajouter un poste"}</Typography>
          <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textSecondary, mt: 0.25 }}>
            Un créneau récurrent pour un chirurgien
          </Typography>
        </Box>
        <IconButton onClick={onClose} sx={{ bgcolor: "#F1F4F7", "&:hover": { bgcolor: "#E7EBEF" } }}>
          <CloseIcon fontSize="small" />
        </IconButton>
      </Stack>

      <Box sx={{ px: 2.75, py: 2.5, maxHeight: "64vh", overflowY: "auto", display: "flex", flexDirection: "column", gap: 2.25 }}>
        <SearchableSelect
          label="Chirurgien"
          required
          icon={<PersonOutlineOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.textSecondary, mr: 0.5 }} />}
          options={surgeons}
          value={surgeonId}
          onChange={setSurgeonId}
          placeholder="Rechercher un chirurgien…"
        />

        <Stack direction="row" spacing={2} flexWrap="wrap" useFlexGap>
          <Box sx={{ flex: 1, minWidth: 200 }}>
            <SearchableSelect
              label="Site"
              required
              icon={<PlaceOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.textSecondary, mr: 0.5 }} />}
              options={siteOptions}
              value={siteId}
              onChange={setSiteId}
              placeholder="Rechercher un site…"
            />
          </Box>
          <Box>
            <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}>Activité</Typography>
            <ToggleButtonGroup exclusive value={type} onChange={(_, v) => v && setType(v)} size="small">
              <ToggleButton value="BLOCK">Bloc</ToggleButton>
              <ToggleButton value="CONSULTATION">Consultation</ToggleButton>
            </ToggleButtonGroup>
          </Box>
        </Stack>

        <Box>
          <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}>Période</Typography>
          <ToggleButtonGroup exclusive value={period} onChange={(_, v) => v && setPeriod(v)} size="small">
            {PERIODS.map((p) => (
              <ToggleButton key={p.value} value={p.value} sx={{ flexDirection: "column", lineHeight: 1.3, py: 0.75 }}>
                <span>{p.label}</span>
                <Typography component="span" sx={{ fontSize: 11, opacity: 0.7, fontVariantNumeric: "tabular-nums" }}>{p.sub}</Typography>
              </ToggleButton>
            ))}
          </ToggleButtonGroup>
        </Box>

        <SearchableSelect
          label="Récurrence"
          required
          icon={<RepeatOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.textSecondary, mr: 0.5 }} />}
          options={LAUNCH_RECURRENCE_PRESET_OPTIONS.map((o) => ({ id: hashKey(o.key), label: o.label }))}
          value={hashKey(preset)}
          onChange={(id) => {
            const found = LAUNCH_RECURRENCE_PRESET_OPTIONS.find((o) => hashKey(o.key) === id);
            if (found) setPreset(found.key);
          }}
        />

        {monthly ? (
          <Stack spacing={1.75}>
            <Box>
              <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}>Occurrences</Typography>
              <ToggleButtonGroup value={monthWeeks} onChange={(_, v) => setMonthWeeks(v)} size="small">
                {MONTH_WEEKS.map((w) => (
                  <ToggleButton key={w.value} value={w.value}>{w.label}</ToggleButton>
                ))}
              </ToggleButtonGroup>
            </Box>
            <Box>
              <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}>Jours</Typography>
              <ToggleButtonGroup value={weekdays} onChange={(_, v) => setWeekdays(v)} size="small">
                {WEEKDAYS_FULL.map((d) => (
                  <ToggleButton key={d.value} value={d.value}>{d.label}</ToggleButton>
                ))}
              </ToggleButtonGroup>
            </Box>
          </Stack>
        ) : (
          <Box>
            <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}>Jours</Typography>
            <ToggleButtonGroup value={weekdays} onChange={(_, v) => setWeekdays(v)} size="small">
              {WEEKDAYS.map((d) => (
                <ToggleButton key={d.value} value={d.value}>{d.label}</ToggleButton>
              ))}
            </ToggleButtonGroup>
          </Box>
        )}

        <Stack direction="row" spacing={2}>
          <Box sx={{ flex: 1 }}>
            <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}>Date de début</Typography>
            <DateField icon={<CalendarTodayOutlinedIcon sx={{ fontSize: 15, color: planningV2Colors.textSecondary }} />} value={startDate} onChange={setStartDate} />
          </Box>
          <Box sx={{ flex: 1 }}>
            <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody, mb: 1 }}>
              Date de fin <Typography component="span" sx={{ fontWeight: 500, color: planningV2Colors.textSecondary }}>· optionnelle</Typography>
            </Typography>
            <DateField icon={<CalendarTodayOutlinedIcon sx={{ fontSize: 15, color: planningV2Colors.textSecondary }} />} value={endDate} onChange={setEndDate} placeholder="Sans fin" />
          </Box>
        </Stack>

        <SearchableSelect
          label="Instrumentiste"
          icon={<PersonOutlineOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.textSecondary, mr: 0.5 }} />}
          options={instrumentists}
          value={instrumentistId}
          onChange={setInstrumentistId}
          placeholder="Rechercher une instrumentiste…"
        />
      </Box>

      <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1.5}
        sx={{ px: 2.75, py: 2, borderTop: `1px solid ${planningV2Colors.divider}`, bgcolor: "#F8FAFC" }}
      >
        <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, flex: 1, minWidth: 0 }} noWrap>
          {formSummary}
        </Typography>
        <Stack direction="row" spacing={1.25}>
          <Button onClick={onClose} sx={{ height: 40, px: 2, borderRadius: planningV2Radii.button, border: `1px solid #DDE2E8`, color: planningV2Colors.textStrong, textTransform: "none", fontWeight: 600 }}>
            Annuler
          </Button>
          <Button
            variant="contained" disableElevation disabled={!canSubmit || submitting} onClick={handleSubmit}
            sx={{
              height: 40, px: 2.25, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600,
              bgcolor: planningV2Colors.brand, boxShadow: planningV2Shadows.button,
              "&:hover": { bgcolor: planningV2Colors.brandHover },
            }}
          >
            {isEdit ? "Enregistrer" : "Enregistrer le poste"}
          </Button>
        </Stack>
      </Stack>
    </Dialog>
  );
}

function DateField({ icon, value, onChange }: { icon: React.ReactNode; value: string; onChange: (v: string) => void; placeholder?: string }) {
  return (
    <Box
      sx={{
        display: "flex", alignItems: "center", gap: 1.1, height: 42, px: 1.6,
        border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.button, bgcolor: "#F8FAFC",
      }}
    >
      {icon}
      <Box
        component="input"
        type="date"
        value={value}
        onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value)}
        sx={{
          border: "none", background: "transparent", outline: "none", fontFamily: "inherit",
          fontSize: 13.5, fontWeight: 600, color: value ? planningV2Colors.textTitle : planningV2Colors.textSecondary,
          width: "100%", fontVariantNumeric: "tabular-nums",
        }}
      />
    </Box>
  );
}

// Stable small-int key for preset strings, so they can flow through SearchableSelect's
// numeric id contract without introducing a second option-shape just for this field.
function hashKey(key: string): number {
  let h = 0;
  for (let i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) | 0;
  return Math.abs(h);
}
