import * as React from "react";
import {
  Box, Button, Checkbox, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, FormControlLabel, IconButton, InputAdornment, Paper, Stack, ToggleButton, ToggleButtonGroup,
  Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import CloseIcon from "@mui/icons-material/Close";
import SearchOutlinedIcon from "@mui/icons-material/SearchOutlined";
import MailOutlineIcon from "@mui/icons-material/MailOutline";
import FactCheckOutlinedIcon from "@mui/icons-material/FactCheckOutlined";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getAbsences, createAbsence, createIsolatedDayAbsences, deleteAbsence, type Absence, type PersonRole,
} from "../../../features/planning-manager/api/planning.api";
import { useToast } from "../../../ui/toast/useToast";
import { Avatar } from "../../../ui/avatar/Avatar";
import {
  PersonSearchSelect, type PersonOption, personOptionsQueryKey, fetchActivePersonOptions,
} from "../../../features/planning-manager/components/PersonSearchSelect";
import { AbsenceReminderDialog } from "../../../features/planning-manager/components/AbsenceReminderDialog";

type AbsenceMode = "period" | "isolatedDays";
type QuickFilter = "ALL" | PersonRole;

const ROLE_LABELS: Record<PersonRole, string> = { INSTRUMENTIST: "Instrumentiste", SURGEON: "Chirurgien" };

function personDisplayName(p: { firstname?: string | null; lastname?: string | null; email: string }): string {
  return `${p.firstname ?? ""} ${p.lastname ?? ""}`.trim() || p.email;
}

/** Role (instrumentists first) → lastname → firstname — the default sort everywhere a person list appears. */
function personSortKey(p: { role: PersonRole | null; lastname?: string | null; firstname?: string | null }): [number, string, string] {
  return [p.role === "INSTRUMENTIST" ? 0 : 1, (p.lastname ?? "").toLowerCase(), (p.firstname ?? "").toLowerCase()];
}

function comparePersonKeys(a: [number, string, string], b: [number, string, string]): number {
  return a[0] - b[0] || a[1].localeCompare(b[1]) || a[2].localeCompare(b[2]);
}

function todayISO(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * Merges the in-progress date field into the chip list, deduplicated and sorted — the single
 * source of truth for "what gets submitted" in isolated-days mode. Used both by the manual
 * "Ajouter" button and by "Enregistrer" directly, so a date typed but never explicitly added
 * is never silently dropped (the bug reported in prod: field had a date, chip list was empty,
 * Enregistrer stayed disabled).
 */
export function getIsolatedDatesToSubmit(isolatedDates: string[], nextIsolatedDate: string): string[] {
  if (!nextIsolatedDate || isolatedDates.includes(nextIsolatedDate)) return isolatedDates;
  return [...isolatedDates, nextIsolatedDate].sort();
}

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

let optimisticIdSeq = -1;

/** Fabricates a temporary Absence row for optimistic display — overwritten by the real row on refetch. */
function optimisticAbsence(person: PersonOption, dateStart: string, dateEnd: string, reason: string): Absence {
  return {
    id: optimisticIdSeq--,
    user: { id: person.id, firstname: person.firstname, lastname: person.lastname, email: person.email, role: person.role },
    dateStart, dateEnd,
    reason: reason || null,
    createdAt: new Date().toISOString(),
  };
}

export default function AbsencesPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const [createOpen, setCreateOpen] = React.useState(false);
  const [mode, setMode] = React.useState<AbsenceMode>("period");
  const [selectedPerson, setSelectedPerson] = React.useState<PersonOption | null>(null);
  const [dateStart, setDateStart] = React.useState(todayISO());
  const [dateEnd, setDateEnd] = React.useState(todayISO());
  const [isolatedDates, setIsolatedDates] = React.useState<string[]>([]);
  const [nextIsolatedDate, setNextIsolatedDate] = React.useState(todayISO());
  const [reason, setReason] = React.useState("");
  const [search, setSearch] = React.useState("");
  const [quickFilter, setQuickFilter] = React.useState<QuickFilter>("ALL");
  const [showHistory, setShowHistory] = React.useState(false);
  const [requestDialogOpen, setRequestDialogOpen] = React.useState(false);
  const [confirmDialogOpen, setConfirmDialogOpen] = React.useState(false);

  // Warm the PersonSearchSelect cache as soon as the page loads, so the "Nouvelle absence"
  // dialog never has to wait on it later — React Query dedupes this with the component's own
  // useQuery() call on the same key.
  React.useEffect(() => {
    qc.prefetchQuery({ queryKey: personOptionsQueryKey("all"), queryFn: () => fetchActivePersonOptions("all") });
  }, [qc]);

  function addIsolatedDate() {
    setIsolatedDates((prev) => getIsolatedDatesToSubmit(prev, nextIsolatedDate));
  }

  function removeIsolatedDate(date: string) {
    setIsolatedDates((prev) => prev.filter((d) => d !== date));
  }

  function resetCreateForm() {
    setSelectedPerson(null); setReason(""); setIsolatedDates([]); setMode("period");
  }

  // Future-only by default (dateEnd >= today) — past absences only load when "Afficher
  // l'historique" is checked. The backend already supports `from` on GET /api/absences.
  const absencesKey = ["absences", showHistory] as const;
  const absencesQuery = useQuery({
    queryKey: absencesKey,
    queryFn: () => getAbsences(showHistory ? undefined : { from: todayISO() }),
  });

  // Synchronous re-entrancy guard for "Enregistrer" — same pattern as AbsenceReminderDialog's
  // sendingRef. createMutation.isPending alone is NOT enough: it only takes effect once React
  // has re-rendered with the disabled button, and a fast double-click can land both clicks
  // before that happens. This ref is checked/set synchronously inside submitCreate, before
  // anything async runs, and is only ever cleared in onSettled (success or error).
  const submittingRef = React.useRef(false);

  const createMutation = useMutation({
    mutationFn: async (isolatedDatesOverride?: string[]): Promise<Absence[]> => mode === "period"
      ? [await createAbsence({ userId: selectedPerson!.id, dateStart, dateEnd, reason: reason.trim() || undefined })]
      : createIsolatedDayAbsences({ userId: selectedPerson!.id, dates: isolatedDatesOverride ?? isolatedDates, reason: reason.trim() || undefined }),
    onMutate: async (isolatedDatesOverride?: string[]) => {
      await qc.cancelQueries({ queryKey: absencesKey });
      const previous = qc.getQueryData<Absence[]>(absencesKey);
      const person = selectedPerson!;
      const optimisticRows = mode === "period"
        ? [optimisticAbsence(person, dateStart, dateEnd, reason.trim())]
        : (isolatedDatesOverride ?? isolatedDates).map((d) => optimisticAbsence(person, d, d, reason.trim()));
      qc.setQueryData<Absence[]>(absencesKey, (old) => [...(old ?? []), ...optimisticRows]);
      setCreateOpen(false);
      resetCreateForm();
      return { previous };
    },
    onSuccess: (created) => {
      toast.success(created.length > 1 ? `${created.length} absences enregistrées` : "Absence enregistrée");
    },
    onError: (err, _vars, ctx) => {
      if (ctx?.previous) qc.setQueryData(absencesKey, ctx.previous);
      toast.error(extractError(err));
    },
    onSettled: () => {
      submittingRef.current = false;
      qc.invalidateQueries({ queryKey: ["absences"] });
    },
  });

  // What would actually be submitted right now in isolated-days mode — includes the date
  // still sitting in the field even if "Ajouter" was never clicked (see getIsolatedDatesToSubmit).
  const isolatedDatesToSubmit = getIsolatedDatesToSubmit(isolatedDates, nextIsolatedDate);

  const canSubmitCreate = !!selectedPerson && !createMutation.isPending
    && (mode === "period" ? !!dateStart && !!dateEnd : isolatedDatesToSubmit.length > 0);

  function submitCreate() {
    if (submittingRef.current) return;
    // Defensive guard — not a substitute for root-causing the "Cannot read properties of
    // null (reading 'id')" report, but it turns a crash into a clear, recoverable message if
    // selectedPerson is ever null here for a reason not yet identified.
    if (!selectedPerson) {
      toast.error("Veuillez sélectionner une personne");
      return;
    }
    submittingRef.current = true;
    if (mode === "isolatedDays") {
      createMutation.mutate(isolatedDatesToSubmit);
    } else {
      createMutation.mutate(undefined);
    }
  }

  const deleteMutation = useMutation({
    mutationFn: deleteAbsence,
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: absencesKey });
      const previous = qc.getQueryData<Absence[]>(absencesKey);
      qc.setQueryData<Absence[]>(absencesKey, (old) => (old ?? []).filter((a) => a.id !== id));
      return { previous };
    },
    onSuccess: () => toast.success("Absence supprimée"),
    onError: (err, _id, ctx) => {
      if (ctx?.previous) qc.setQueryData(absencesKey, ctx.previous);
      toast.error(extractError(err));
    },
    onSettled: () => { qc.invalidateQueries({ queryKey: ["absences"] }); },
  });

  const absences = absencesQuery.data ?? [];

  const visibleAbsences = React.useMemo(() => {
    const q = search.trim().toLowerCase();
    return absences
      .filter((abs) => quickFilter === "ALL" || abs.user.role === quickFilter)
      .filter((abs) => {
        if (!q) return true;
        const roleLabel = abs.user.role ? ROLE_LABELS[abs.user.role] : "";
        return [abs.user.firstname, abs.user.lastname, abs.user.email, roleLabel]
          .some((field) => (field ?? "").toLowerCase().includes(q));
      })
      .sort((a, b) => {
        const byPerson = comparePersonKeys(personSortKey(a.user), personSortKey(b.user));
        return byPerson || a.dateStart.localeCompare(b.dateStart);
      });
  }, [absences, search, quickFilter]);

  const countsByRole = React.useMemo(() => {
    const counts = { ALL: absences.length, INSTRUMENTIST: 0, SURGEON: 0 };
    for (const abs of absences) {
      if (abs.user.role === "INSTRUMENTIST") counts.INSTRUMENTIST++;
      if (abs.user.role === "SURGEON") counts.SURGEON++;
    }
    return counts;
  }, [absences]);

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" flexWrap="wrap" gap={1.5}>
        <Typography variant="h6" fontWeight={700}>Gestion des absences</Typography>
        <Stack direction="row" spacing={1.25}>
          <Button variant="outlined" startIcon={<MailOutlineIcon />} onClick={() => setRequestDialogOpen(true)}>
            Demander les congés
          </Button>
          <Button variant="outlined" startIcon={<FactCheckOutlinedIcon />} onClick={() => setConfirmDialogOpen(true)}>
            Confirmer les congés encodés
          </Button>
          <Button variant="contained" disableElevation startIcon={<AddIcon />} onClick={() => setCreateOpen(true)}>
            Nouvelle absence
          </Button>
        </Stack>
      </Stack>

      {/* Filters */}
      <Stack direction="row" spacing={1.5} flexWrap="wrap" alignItems="center">
        <TextField
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          size="small"
          placeholder="Rechercher (nom, email, rôle)…"
          sx={{ minWidth: 260 }}
          slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlinedIcon fontSize="small" /></InputAdornment> } }}
        />
        <Stack direction="row" spacing={1}>
          {([
            ["ALL", "Tous", countsByRole.ALL],
            ["INSTRUMENTIST", "Instrumentistes", countsByRole.INSTRUMENTIST],
            ["SURGEON", "Chirurgiens", countsByRole.SURGEON],
          ] as const).map(([key, label, count]) => (
            <Chip
              key={key}
              label={`${label} (${count})`}
              size="small"
              color={quickFilter === key ? "primary" : "default"}
              variant={quickFilter === key ? "filled" : "outlined"}
              onClick={() => setQuickFilter(key)}
            />
          ))}
        </Stack>
        <FormControlLabel
          control={<Checkbox size="small" checked={showHistory} onChange={(e) => setShowHistory(e.target.checked)} />}
          label={<Typography variant="body2">Afficher l'historique</Typography>}
        />
      </Stack>

      {/* List */}
      {absencesQuery.isLoading ? (
        <CircularProgress size={24} />
      ) : absences.length === 0 ? (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2 }}>
          <img src="https://cdn.undraw.co/illustration/time-management_30iu.svg" alt="" style={{ width: 220, opacity: 0.85 }} />
          <Typography variant="h6" fontWeight={600} color="text.secondary">
            {showHistory ? "Aucune absence enregistrée" : "Aucune absence à venir"}
          </Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 360 }}>
            Les absences permettent à SurgicalHub de les exclure automatiquement lors de la génération du planning.
            {!showHistory && " Cochez « Afficher l'historique » pour voir les absences passées."}
          </Typography>
        </Box>
      ) : visibleAbsences.length === 0 ? (
        <Typography variant="body2" color="text.secondary" sx={{ py: 4, textAlign: "center" }}>
          Aucune absence ne correspond à ce filtre.
        </Typography>
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell>Personne</TableCell>
                <TableCell>Du</TableCell>
                <TableCell>Au</TableCell>
                <TableCell>Durée</TableCell>
                <TableCell>Motif</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {visibleAbsences.map((abs: Absence) => {
                const days = Math.round(
                  (new Date(abs.dateEnd).getTime() - new Date(abs.dateStart).getTime()) / 86400000 + 1
                );
                return (
                  <TableRow key={abs.id} hover sx={{ opacity: abs.id < 0 ? 0.6 : 1 }}>
                    <TableCell>
                      <Stack direction="row" spacing={1.25} alignItems="center">
                        <Avatar name={personDisplayName(abs.user)} size={30} />
                        <Box sx={{ minWidth: 0 }}>
                          <Typography sx={{ fontWeight: 600, fontSize: 13.5 }} noWrap>
                            {personDisplayName(abs.user)}
                            {abs.user.role && (
                              <Typography component="span" sx={{ fontWeight: 500, color: "text.secondary", fontSize: 12.5 }}>
                                {" "}({ROLE_LABELS[abs.user.role]})
                              </Typography>
                            )}
                          </Typography>
                          <Typography sx={{ fontSize: 11.5, color: "text.secondary" }} noWrap>{abs.user.email}</Typography>
                        </Box>
                      </Stack>
                    </TableCell>
                    <TableCell>{new Date(abs.dateStart + "T00:00:00").toLocaleDateString("fr-BE")}</TableCell>
                    <TableCell>{new Date(abs.dateEnd + "T00:00:00").toLocaleDateString("fr-BE")}</TableCell>
                    <TableCell>
                      <Chip label={`${days} jour${days > 1 ? "s" : ""}`} size="small" variant="outlined" />
                    </TableCell>
                    <TableCell>{abs.reason ?? <Typography component="span" color="text.disabled">—</Typography>}</TableCell>
                    <TableCell align="right">
                      <IconButton
                        size="small" color="error"
                        aria-label="Supprimer"
                        onClick={() => deleteMutation.mutate(abs.id)}
                        disabled={deleteMutation.isPending || abs.id < 0}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Paper>
      )}

      {/* Create dialog */}
      <Dialog open={createOpen} onClose={() => { setCreateOpen(false); resetCreateForm(); }} maxWidth="xs" fullWidth>
        <DialogTitle fontWeight={700}>Nouvelle absence</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <PersonSearchSelect
              label="Personne"
              scope="all"
              value={selectedPerson}
              onChange={setSelectedPerson}
            />

            <ToggleButtonGroup
              value={mode}
              exclusive
              size="small"
              onChange={(_, v) => { if (v) setMode(v); }}
              fullWidth
            >
              <ToggleButton value="period">Période</ToggleButton>
              <ToggleButton value="isolatedDays">Jours isolés</ToggleButton>
            </ToggleButtonGroup>

            {mode === "period" ? (
              <Stack direction="row" spacing={1}>
                <TextField
                  label="Du" type="date" value={dateStart}
                  onChange={(e) => setDateStart(e.target.value)}
                  size="small" InputLabelProps={{ shrink: true }} fullWidth
                />
                <TextField
                  label="Au" type="date" value={dateEnd}
                  onChange={(e) => setDateEnd(e.target.value)}
                  size="small" InputLabelProps={{ shrink: true }} fullWidth
                />
              </Stack>
            ) : (
              <Stack spacing={1}>
                <Stack direction="row" spacing={1}>
                  <TextField
                    label="Ajouter une date" type="date" value={nextIsolatedDate}
                    onChange={(e) => setNextIsolatedDate(e.target.value)}
                    size="small" InputLabelProps={{ shrink: true }} fullWidth
                  />
                  <Button variant="outlined" onClick={addIsolatedDate} disabled={!nextIsolatedDate || isolatedDates.includes(nextIsolatedDate)}>
                    Ajouter
                  </Button>
                </Stack>
                {isolatedDates.length === 0 ? (
                  <Typography variant="body2" color="text.secondary">
                    {nextIsolatedDate
                      ? "Cliquez sur « Ajouter » pour préparer plusieurs jours, ou directement sur « Enregistrer » pour ce seul jour."
                      : "Aucun jour ajouté pour le moment."}
                  </Typography>
                ) : (
                  <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                    {isolatedDates.map((date) => (
                      <Chip
                        key={date}
                        label={new Date(date + "T00:00:00").toLocaleDateString("fr-BE")}
                        onDelete={() => removeIsolatedDate(date)}
                        deleteIcon={<CloseIcon fontSize="small" />}
                        size="small"
                      />
                    ))}
                  </Stack>
                )}
              </Stack>
            )}

            <TextField
              label="Motif (optionnel)" value={reason}
              onChange={(e) => setReason(e.target.value)}
              size="small" fullWidth
              placeholder="Congés, formation..."
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => { setCreateOpen(false); resetCreateForm(); }} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            onClick={submitCreate}
            disabled={!canSubmitCreate}
          >
            {createMutation.isPending ? <CircularProgress size={16} /> : "Enregistrer"}
          </Button>
        </DialogActions>
      </Dialog>

      <AbsenceReminderDialog mode="missing" open={requestDialogOpen} onClose={() => setRequestDialogOpen(false)} />
      <AbsenceReminderDialog mode="encoded" open={confirmDialogOpen} onClose={() => setConfirmDialogOpen(false)} />
    </Stack>
  );
}
