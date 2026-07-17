import * as React from "react";
import {
  Box, Button, Chip, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, Divider, IconButton, Paper, Stack,
  Table, TableBody, TableCell, TableContainer, TableHead,
  TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import EditIcon from "@mui/icons-material/Edit";
import DeleteIcon from "@mui/icons-material/Delete";
import MedicalServicesIcon from "@mui/icons-material/MedicalServices";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getInterventionTypes,
  createInterventionType,
  updateInterventionType,
  deleteInterventionType,
  type InterventionType,
} from "../../features/intervention-types/api/interventionTypes.api";
import { useToast } from "../../ui/toast/useToast";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? "Erreur inconnue";
}

const EMPTY_FORM = { code: "", label: "", specialty: "" };

/**
 * Référentiel médical fermé (Lot 1) — aucune notion financière ici, voir
 * docs/decisions.md. Le code est immuable après création : pas de champ code
 * dans le formulaire d'édition.
 */
export default function InterventionTypesPage() {
  const qc = useQueryClient();
  const toast = useToast();

  const [dialogOpen, setDialogOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<InterventionType | null>(null);
  const [form, setForm] = React.useState(EMPTY_FORM);
  const [deleteId, setDeleteId] = React.useState<number | null>(null);

  const typesQuery = useQuery({ queryKey: ["intervention-types"], queryFn: () => getInterventionTypes() });
  const types = typesQuery.data ?? [];
  const invalidate = () => qc.invalidateQueries({ queryKey: ["intervention-types"] });

  const createMutation = useMutation({
    mutationFn: createInterventionType,
    onSuccess: () => { toast.success("Type d'intervention créé"); invalidate(); closeDialog(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const updateMutation = useMutation({
    mutationFn: ({ id, ...body }: { id: number; label?: string; specialty?: string; active?: boolean }) =>
      updateInterventionType(id, body),
    onSuccess: () => { toast.success("Type d'intervention mis à jour"); invalidate(); closeDialog(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const toggleActiveMutation = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => updateInterventionType(id, { active }),
    onSuccess: invalidate,
    onError: (e) => toast.error(extractError(e)),
  });
  const deleteMutation = useMutation({
    mutationFn: deleteInterventionType,
    onSuccess: () => { toast.success("Type d'intervention supprimé"); invalidate(); setDeleteId(null); },
    onError: (e) => { toast.error(extractError(e)); setDeleteId(null); },
  });

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setDialogOpen(true);
  }
  function openEdit(t: InterventionType) {
    setEditing(t);
    setForm({ code: t.code, label: t.label, specialty: t.specialty ?? "" });
    setDialogOpen(true);
  }
  function closeDialog() {
    setDialogOpen(false);
    setEditing(null);
    setForm(EMPTY_FORM);
  }
  function handleSubmit() {
    if (editing) {
      if (!form.label.trim()) return;
      updateMutation.mutate({ id: editing.id, label: form.label.trim(), specialty: form.specialty.trim() || undefined });
    } else {
      if (!form.code.trim() || !form.label.trim()) return;
      createMutation.mutate({ code: form.code.trim(), label: form.label.trim(), specialty: form.specialty.trim() || undefined });
    }
  }

  const isPending = createMutation.isPending || updateMutation.isPending;

  return (
    <Box sx={{ p: 3, maxWidth: 900 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start" mb={3}>
        <Box>
          <Stack direction="row" alignItems="center" spacing={1.5} mb={0.5}>
            <MedicalServicesIcon sx={{ color: "primary.main", fontSize: 28 }} />
            <Typography variant="h5">Types d'intervention</Typography>
          </Stack>
          <Typography variant="body2" color="text.secondary">
            Référentiel médical fermé — indépendant des firmes et des tarifs.
          </Typography>
        </Box>
        <Button variant="contained" startIcon={<AddIcon />} onClick={openCreate} disableElevation>
          Ajouter un type
        </Button>
      </Stack>

      {typesQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 8 }}><CircularProgress /></Box>
      ) : types.length === 0 ? (
        <Paper variant="outlined" sx={{ py: 8, textAlign: "center", borderStyle: "dashed" }}>
          <MedicalServicesIcon sx={{ fontSize: 48, color: "text.disabled", mb: 1 }} />
          <Typography color="text.secondary">Aucun type d'intervention enregistré.</Typography>
          <Button onClick={openCreate} sx={{ mt: 2 }} variant="contained" disableElevation>
            Ajouter le premier type
          </Button>
        </Paper>
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow sx={{ "& th": { fontWeight: 700, bgcolor: "grey.50" } }}>
                <TableCell>Code</TableCell>
                <TableCell>Libellé</TableCell>
                <TableCell>Spécialité</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right" sx={{ width: 100 }}>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {types.map((t) => (
                <TableRow key={t.id} hover sx={{ "&:last-child td": { borderBottom: 0 } }}>
                  <TableCell>
                    <Typography fontWeight={700} variant="body2" sx={{ fontFamily: "monospace" }}>{t.code}</Typography>
                  </TableCell>
                  <TableCell><Typography variant="body2">{t.label}</Typography></TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.secondary">{t.specialty ?? <em style={{ opacity: .5 }}>—</em>}</Typography>
                  </TableCell>
                  <TableCell>
                    <Chip
                      label={t.active ? "Actif" : "Inactif"}
                      size="small"
                      color={t.active ? "success" : "default"}
                      onClick={() => toggleActiveMutation.mutate({ id: t.id, active: !t.active })}
                      sx={{ cursor: "pointer" }}
                    />
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Tooltip title="Modifier">
                        <IconButton size="small" onClick={() => openEdit(t)}>
                          <EditIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title="Supprimer">
                        <IconButton size="small" color="error" onClick={() => setDeleteId(t.id)}>
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <Dialog open={dialogOpen} onClose={closeDialog} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
        <DialogTitle fontWeight={700}>{editing ? "Modifier le type d'intervention" : "Nouveau type d'intervention"}</DialogTitle>
        <Divider />
        <DialogContent>
          <Stack spacing={2.5} sx={{ pt: 2 }}>
            {!editing && (
              <TextField
                label="Code *"
                value={form.code}
                onChange={(e) => setForm((f) => ({ ...f, code: e.target.value.toUpperCase() }))}
                fullWidth autoFocus
                placeholder="Ex : LCA-PRIMAIRE"
                inputProps={{ style: { fontFamily: "monospace", fontWeight: 700 } }}
                helperText="Immuable après création."
              />
            )}
            <TextField
              label="Libellé *"
              value={form.label}
              onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))}
              fullWidth
              autoFocus={!!editing}
              placeholder="Ex : LCA primaire"
            />
            <TextField
              label="Spécialité"
              value={form.specialty}
              onChange={(e) => setForm((f) => ({ ...f, specialty: e.target.value }))}
              fullWidth
              placeholder="Indicatif uniquement (optionnel)"
            />
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={closeDialog} color="inherit">Annuler</Button>
          <Button variant="contained" disableElevation onClick={handleSubmit} disabled={isPending}>
            {isPending ? <CircularProgress size={16} /> : editing ? "Enregistrer" : "Créer"}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={deleteId !== null} onClose={() => setDeleteId(null)} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
        <DialogTitle fontWeight={700}>Supprimer le type d'intervention</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary">
            Impossible s'il est utilisé par une prestation ou une règle tarifaire — désactivez-le dans ce cas plutôt.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={() => setDeleteId(null)} color="inherit">Annuler</Button>
          <Button variant="contained" color="error" disableElevation disabled={deleteMutation.isPending}
            onClick={() => deleteId !== null && deleteMutation.mutate(deleteId)}>
            {deleteMutation.isPending ? <CircularProgress size={16} /> : "Supprimer"}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
