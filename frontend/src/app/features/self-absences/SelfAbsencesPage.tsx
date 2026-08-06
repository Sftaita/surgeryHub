import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Alert, Box, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, Stack, Typography } from "@mui/material";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { fetchMyAbsences, createMyAbsence, updateMyAbsence, deleteMyAbsence } from "./api/selfAbsences.api";
import type { SelfAbsence } from "./api/selfAbsences.types";
import { AbsenceFormSheet, type AbsenceFormSubmitBody } from "./components/AbsenceFormSheet";
import { AbsenceListItem } from "./components/AbsenceListItem";
import { useToast } from "../../ui/toast/useToast";

dayjs.locale("fr");

const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? "Une erreur est survenue.";
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function monthGroupLabel(dateYmd: string): string {
  return capitalize(dayjs(dateYmd).format("MMMM YYYY"));
}

type Scope = "upcoming" | "past";

// ── Bascule légère À venir | Passées — agit sur le jeu déjà chargé, aucune requête
// supplémentaire (§7, §15 — cohérent avec le planning chirurgien du Lot 2).
function ScopeControl({ value, onChange }: { value: Scope; onChange: (v: Scope) => void }) {
  const seg = (key: Scope, label: string) => (
    <Box
      component="button" type="button" onClick={() => onChange(key)}
      sx={{
        height: 34, px: "16px", borderRadius: "10px", border: "none", fontFamily: "inherit",
        fontSize: 13.5, fontWeight: value === key ? 700 : 600, cursor: "pointer",
        background: value === key ? "#F1F4F7" : "transparent",
        color: value === key ? "#16202B" : GRAY_600,
      }}
    >
      {label}
    </Box>
  );
  return (
    <Box sx={{ display: "flex", gap: "4px", background: "#fff", borderRadius: "13px", padding: "4px", boxShadow: "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)", width: "max-content" }}>
      {seg("upcoming", "À venir")}
      {seg("past", "Passées")}
    </Box>
  );
}

function EmptyState({ scope, onAdd }: { scope: Scope; onAdd: () => void }) {
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: "0 1px 2px rgba(22,32,43,.05)", px: 2, py: 3, textAlign: "center" }}>
      <Typography sx={{ fontSize: 14, fontWeight: 700, color: "#16202B" }}>
        {scope === "upcoming" ? "Aucune absence prévue" : "Aucune absence passée"}
      </Typography>
      {scope === "upcoming" && (
        <Box
          component="button" type="button" onClick={onAdd}
          sx={{
            mt: "14px", height: 40, px: "18px", border: "none", borderRadius: "10px",
            background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 13.5, fontWeight: 700,
            cursor: "pointer",
          }}
        >
          Ajouter une absence
        </Box>
      )}
    </Box>
  );
}

function ConfirmDeleteDialog({
  absence,
  onCancel,
  onConfirm,
  pending,
}: {
  absence: SelfAbsence | null;
  onCancel: () => void;
  onConfirm: () => void;
  pending: boolean;
}) {
  if (!absence) return null;
  const isSingleDay = absence.dateStart === absence.dateEnd;
  const label = isSingleDay
    ? capitalize(dayjs(absence.dateStart).format("D MMMM"))
    : `${dayjs(absence.dateStart).format("D MMM")} → ${dayjs(absence.dateEnd).format("D MMM")}`;

  return (
    <Dialog open onClose={pending ? undefined : onCancel} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ fontWeight: 700 }}>Supprimer cette absence ?</DialogTitle>
      <DialogContent>
        <Typography sx={{ fontWeight: 700, mb: 0.5 }}>{label}</Typography>
        <Typography variant="body2" color="text.secondary">Cette absence sera retirée.</Typography>
      </DialogContent>
      <DialogActions>
        <Box component="button" type="button" onClick={onCancel} disabled={pending} sx={{ border: "none", background: "transparent", color: GRAY_600, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer", px: 2, py: 1 }}>
          Annuler
        </Box>
        <Box component="button" type="button" onClick={onConfirm} disabled={pending} sx={{ border: "none", background: "#E5484D", color: "#fff", fontFamily: "inherit", fontSize: 14, fontWeight: 700, cursor: "pointer", borderRadius: "10px", px: 2.5, py: 1 }}>
          {pending ? "…" : "Supprimer"}
        </Box>
      </DialogActions>
    </Dialog>
  );
}

/**
 * Self-service absence management (Lot 3, D-097) — the ONE page consumed by both
 * /app/s/absences and /app/i/absences (never SurgeonAbsencesPage/InstrumentistAbsencesPage,
 * behavior is identical for both roles; see docs/decisions.md). Everything role-specific
 * lives entirely server-side (ownership, self-service Voter) — this component has no idea
 * which role is viewing it.
 */
export default function SelfAbsencesPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const [scope, setScope] = React.useState<Scope>("upcoming");
  const [formOpen, setFormOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<SelfAbsence | null>(null);
  const [deleting, setDeleting] = React.useState<SelfAbsence | null>(null);

  const absencesQuery = useQuery({ queryKey: ["self-absences"], queryFn: fetchMyAbsences });

  // §15 — une mutation qui modifie le planning invalide aussi les queries missions/planning
  // pertinentes, pour que l'utilisateur voie immédiatement la conséquence (ex. une mission
  // annulée/libérée par AbsenceMissionReactionService doit disparaître de son planning sans
  // qu'il ait à rafraîchir manuellement).
  function invalidateAfterMutation() {
    qc.invalidateQueries({ queryKey: ["self-absences"] });
    qc.invalidateQueries({ queryKey: ["missions"] });
  }

  const createMutation = useMutation({
    mutationFn: (body: AbsenceFormSubmitBody) => createMyAbsence(body),
    onSuccess: (result) => {
      setFormOpen(false);
      invalidateAfterMutation();
      toast.success(
        result.missionsImpactedCount > 0
          ? `Absence enregistrée — ${result.missionsImpactedCount} mission${result.missionsImpactedCount > 1 ? "s" : ""} du planning ${result.missionsImpactedCount > 1 ? "sont concernées" : "est concernée"}, le manager en a été informé.`
          : "Absence enregistrée",
      );
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const updateMutation = useMutation({
    mutationFn: (vars: { id: number; body: AbsenceFormSubmitBody }) => updateMyAbsence(vars.id, vars.body),
    onSuccess: () => {
      setFormOpen(false);
      setEditing(null);
      invalidateAfterMutation();
      toast.success("Absence modifiée");
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteMyAbsence(id),
    onSuccess: () => {
      setDeleting(null);
      invalidateAfterMutation();
      toast.success("Absence supprimée");
    },
    onError: (err) => {
      setDeleting(null);
      toast.error(extractError(err));
    },
  });

  const absences = absencesQuery.data ?? [];
  const todayYmd = React.useMemo(() => dayjs().format("YYYY-MM-DD"), []);

  const { upcoming, past } = React.useMemo(() => {
    const up: SelfAbsence[] = [];
    const pa: SelfAbsence[] = [];
    for (const a of absences) {
      if (a.dateEnd >= todayYmd) up.push(a);
      else pa.push(a);
    }
    up.sort((a, b) => a.dateStart.localeCompare(b.dateStart));
    pa.sort((a, b) => b.dateStart.localeCompare(a.dateStart));
    return { upcoming: up, past: pa };
  }, [absences, todayYmd]);

  const visible = scope === "upcoming" ? upcoming : past;

  const grouped = React.useMemo(() => {
    const groups = new Map<string, SelfAbsence[]>();
    for (const a of visible) {
      const key = dayjs(a.dateStart).format("YYYY-MM");
      const list = groups.get(key);
      if (list) list.push(a);
      else groups.set(key, [a]);
    }
    return Array.from(groups.entries());
  }, [visible]);

  function openCreate() {
    setEditing(null);
    setFormOpen(true);
  }

  function openEdit(absence: SelfAbsence) {
    setEditing(absence);
    setFormOpen(true);
  }

  function handleSubmit(body: AbsenceFormSubmitBody) {
    if (editing) {
      updateMutation.mutate({ id: editing.id, body });
    } else {
      createMutation.mutate(body);
    }
  }

  const submitting = createMutation.isPending || updateMutation.isPending;
  const submitError = createMutation.isError
    ? extractError(createMutation.error)
    : updateMutation.isError
      ? extractError(updateMutation.error)
      : null;

  if (absencesQuery.isLoading) {
    return (
      <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  if (absencesQuery.isError) {
    return <Alert severity="error">Impossible de charger vos absences.</Alert>;
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700 }}>
          MES ABSENCES
        </Typography>
        <Box
          component="button" type="button" onClick={openCreate}
          sx={{
            height: 36, px: "14px", border: "none", borderRadius: "10px",
            background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 13, fontWeight: 700,
            cursor: "pointer", display: "flex", alignItems: "center", gap: "6px",
          }}
        >
          + Ajouter
        </Box>
      </Stack>

      <ScopeControl value={scope} onChange={setScope} />

      {visible.length === 0 ? (
        <EmptyState scope={scope} onAdd={openCreate} />
      ) : (
        <Stack spacing={2.5}>
          {grouped.map(([monthKey, items]) => (
            <Stack key={monthKey} spacing={1.25}>
              <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GRAY_500 }}>
                {monthGroupLabel(items[0].dateStart).toUpperCase()}
              </Typography>
              <Stack spacing={1.25}>
                {items.map((a) => (
                  <AbsenceListItem key={a.id} absence={a} onEdit={() => openEdit(a)} onDelete={() => setDeleting(a)} />
                ))}
              </Stack>
            </Stack>
          ))}
        </Stack>
      )}

      <AbsenceFormSheet
        open={formOpen}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        initial={editing}
        onSubmit={handleSubmit}
        submitting={submitting}
        submitError={submitError}
      />

      <ConfirmDeleteDialog
        absence={deleting}
        onCancel={() => setDeleting(null)}
        onConfirm={() => { if (deleting) deleteMutation.mutate(deleting.id); }}
        pending={deleteMutation.isPending}
      />
    </Stack>
  );
}
