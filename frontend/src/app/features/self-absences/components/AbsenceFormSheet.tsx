import * as React from "react";
import { Box, Stack, TextField, Typography } from "@mui/material";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { SheetModal } from "../../../ui/sheet/SheetModal";
import { AbsenceImpactPreview } from "./AbsenceImpactPreview";
import type { SelfAbsence } from "../api/selfAbsences.types";

dayjs.locale("fr");

const GREEN_800 = "#1F6B4F";
const GRAY_75 = "#F1F4F7";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";
const RED_600 = "#E5484D";

type DayMode = "single" | "period";

const REASON_SUGGESTIONS = ["Congé", "Congrès / formation", "Garde / récupération", "Indisponibilité", "Autre"];

function todayYmd(): string {
  return dayjs().format("YYYY-MM-DD");
}

function durationDays(from: string, to: string): number {
  return Math.round((dayjs(to).valueOf() - dayjs(from).valueOf()) / 86_400_000) + 1;
}

// ── Segmented control [Jour unique] [Période] — UX prescrite (§4), jamais exposée en
// "dateStart === dateEnd" côté utilisateur, uniquement la représentation interne.
function DayModeControl({ value, onChange, disabled }: { value: DayMode; onChange: (v: DayMode) => void; disabled: boolean }) {
  const seg = (key: DayMode, label: string) => (
    <Box
      component="button"
      type="button"
      disabled={disabled}
      onClick={() => onChange(key)}
      sx={{
        flex: 1, height: 40, border: "none", borderRadius: "10px", fontFamily: "inherit",
        fontSize: 13.5, fontWeight: value === key ? 700 : 600, cursor: disabled ? "default" : "pointer",
        background: value === key ? "#fff" : "transparent",
        color: value === key ? GRAY_900 : GRAY_600,
        boxShadow: value === key ? "0 1px 2px rgba(22,32,43,.05)" : "none",
        opacity: disabled ? 0.6 : 1,
      }}
    >
      {label}
    </Box>
  );

  return (
    <Box sx={{ display: "flex", gap: "4px", background: GRAY_75, borderRadius: "13px", padding: "4px" }}>
      {seg("single", "Jour unique")}
      {seg("period", "Période")}
    </Box>
  );
}

export type AbsenceFormSubmitBody = { dateStart: string; dateEnd: string; reason: string | null };

/**
 * Shared form (Lot 3, D-097, §5) — the ONE component consumed by both /app/s/absences and
 * /app/i/absences, in both create and edit mode. `initial === null` means create; a non-null
 * `initial` reopens preselecting the correct segment from dateStart === dateEnd (§5, never the
 * reverse — a period is never guessed as single-day unless the dates are truly equal).
 */
export function AbsenceFormSheet({
  open,
  onClose,
  initial,
  onSubmit,
  submitting,
  submitError,
}: {
  open: boolean;
  onClose: () => void;
  initial: SelfAbsence | null;
  onSubmit: (body: AbsenceFormSubmitBody) => void;
  submitting: boolean;
  submitError?: string | null;
}) {
  const isEdit = initial !== null;

  const [dayMode, setDayMode] = React.useState<DayMode>("single");
  const [dateSingle, setDateSingle] = React.useState(todayYmd());
  const [dateFrom, setDateFrom] = React.useState(todayYmd());
  const [dateTo, setDateTo] = React.useState(todayYmd());
  const [reason, setReason] = React.useState("");

  // Re-seed local form state every time the sheet (re)opens — covers both "create" (blank
  // form) and "edit" (prefilled from `initial`, segment chosen from dateStart === dateEnd).
  React.useEffect(() => {
    if (!open) return;
    if (initial) {
      const single = initial.dateStart === initial.dateEnd;
      setDayMode(single ? "single" : "period");
      setDateSingle(initial.dateStart);
      setDateFrom(initial.dateStart);
      setDateTo(initial.dateEnd);
      setReason(initial.reason ?? "");
    } else {
      setDayMode("single");
      setDateSingle(todayYmd());
      setDateFrom(todayYmd());
      setDateTo(todayYmd());
      setReason("");
    }
  }, [open, initial]);

  const effectiveStart = dayMode === "single" ? dateSingle : dateFrom;
  const effectiveEnd = dayMode === "single" ? dateSingle : dateTo;

  const rangeError =
    dayMode === "period" && dateFrom && dateTo && dayjs(dateTo).isBefore(dayjs(dateFrom), "day")
      ? "La date de fin doit être postérieure ou égale à la date de début."
      : null;

  const hasValidDates = dayMode === "single" ? !!dateSingle : !!dateFrom && !!dateTo && !rangeError;

  const previewEnabled = hasValidDates;

  function handleSubmit() {
    if (!hasValidDates) return;
    onSubmit({ dateStart: effectiveStart, dateEnd: effectiveEnd, reason: reason.trim() || null });
  }

  return (
    <SheetModal
      open={open}
      title={isEdit ? "Modifier l'absence" : "Nouvelle absence"}
      onClose={onClose}
      closeDisabled={submitting}
    >
      <Stack spacing={2} sx={{ mt: "18px" }}>
        <DayModeControl value={dayMode} onChange={setDayMode} disabled={submitting} />

        {dayMode === "single" ? (
          <TextField
            label="Date" type="date" value={dateSingle}
            onChange={(e) => setDateSingle(e.target.value)}
            size="small" fullWidth disabled={submitting}
            slotProps={{ inputLabel: { shrink: true } }}
          />
        ) : (
          <Stack spacing={1.25}>
            <Stack direction="row" spacing={1.25}>
              <TextField
                label="Du" type="date" value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                size="small" fullWidth disabled={submitting}
                slotProps={{ inputLabel: { shrink: true } }}
              />
              <TextField
                label="Au" type="date" value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                size="small" fullWidth disabled={submitting}
                slotProps={{ inputLabel: { shrink: true } }}
              />
            </Stack>
            {rangeError ? (
              <Typography sx={{ fontSize: 12.5, color: RED_600 }}>{rangeError}</Typography>
            ) : dateFrom && dateTo ? (
              <Typography sx={{ fontSize: 12.5, color: GRAY_500 }}>
                {durationDays(dateFrom, dateTo)} jour{durationDays(dateFrom, dateTo) > 1 ? "s" : ""}
              </Typography>
            ) : null}
          </Stack>
        )}

        <Stack spacing={1}>
          <TextField
            label="Motif (optionnel)" value={reason}
            onChange={(e) => setReason(e.target.value)}
            size="small" fullWidth disabled={submitting}
            placeholder="Congés, formation…"
          />
          <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
            {REASON_SUGGESTIONS.map((s) => (
              <Box
                key={s}
                component="button"
                type="button"
                disabled={submitting}
                onClick={() => setReason(s === "Autre" ? "" : s)}
                sx={{
                  height: 30, px: "12px", borderRadius: "999px", border: `1px solid ${GRAY_75}`,
                  background: GRAY_75, color: GRAY_600, fontFamily: "inherit", fontSize: 12.5,
                  fontWeight: 600, cursor: submitting ? "default" : "pointer",
                }}
              >
                {s}
              </Box>
            ))}
          </Stack>
        </Stack>

        <AbsenceImpactPreview dateStart={effectiveStart} dateEnd={effectiveEnd} enabled={previewEnabled} />

        {submitError && (
          <Typography sx={{ fontSize: 12.5, color: RED_600 }}>{submitError}</Typography>
        )}

        <Box
          component="button"
          type="button"
          onClick={handleSubmit}
          disabled={!hasValidDates || submitting}
          sx={{
            mt: "4px", width: "100%", height: 50, border: "none", borderRadius: "12px",
            background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
            cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
            "&:disabled": { opacity: 0.55, cursor: "default", boxShadow: "none" },
          }}
        >
          {submitting ? "…" : isEdit ? "Enregistrer" : "Créer l'absence"}
        </Box>
        <Box
          component="button"
          type="button"
          onClick={onClose}
          disabled={submitting}
          sx={{ width: "100%", height: 42, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
        >
          Annuler
        </Box>
      </Stack>
    </SheetModal>
  );
}
