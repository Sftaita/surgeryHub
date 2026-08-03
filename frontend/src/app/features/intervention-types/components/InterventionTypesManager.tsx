import * as React from "react";
import {
  Autocomplete, Box, Button, Chip, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogContentText, DialogTitle, Divider, IconButton, Paper, Stack,
  Table, TableBody, TableCell, TableContainer, TableHead,
  TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import EditIcon from "@mui/icons-material/Edit";
import DeleteIcon from "@mui/icons-material/Delete";
import CallMergeIcon from "@mui/icons-material/CallMerge";
import MedicalServicesIcon from "@mui/icons-material/MedicalServices";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getInterventionTypes,
  createInterventionType,
  updateInterventionType,
  deleteInterventionType,
  findSimilarInterventionTypes,
  mergeInterventionType,
  type InterventionType,
  type SimilarInterventionTypeCandidate,
} from "../api/interventionTypes.api";
import { useToast } from "../../../ui/toast/useToast";
import { EmptyState } from "../../../ui/EmptyState";
import { PageHeader } from "../../../ui/PageHeader";
import { ActiveBadge } from "../../../ui/StatusBadge";

const CONFIDENCE_LABELS: Record<SimilarInterventionTypeCandidate["confidence"], string> = {
  HIGH: "Correspondance forte",
  MEDIUM: "Correspondance probable",
  LOW: "Correspondance possible",
};

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? "Erreur inconnue";
}

const EMPTY_FORM = { code: "", label: "", specialty: "" };

type InterventionTypesManagerProps = {
  /** Masque le PageHeader (utile quand la page appelante en affiche déjà un, ex. dialog Prestations). */
  showHeader?: boolean;
  /**
   * Catalogue > Prestations, refonte UX — quand fourni, chaque ligne devient cliquable
   * et ouvre le détail (firmes utilisatrices, forfait résolu) dans le contexte GLOBAL
   * du Référentiel. Optionnel et rétrocompatible : comportement inchangé pour les
   * autres usages (InterventionTypesPage, dialog "Gérer les types" historique).
   */
  onOpenDetail?: (type: InterventionType) => void;
};

/**
 * Référentiel médical fermé (Lot 1) — aucune notion financière ici, voir
 * docs/decisions.md. Le code est immuable après création : pas de champ code
 * dans le formulaire d'édition.
 *
 * Extrait d'InterventionTypesPage.tsx pour être réutilisé tel quel dans le
 * dialog "Gérer les types d'intervention" de PrestationsPage (D-079).
 */
export function InterventionTypesManager({ showHeader = true, onOpenDetail }: InterventionTypesManagerProps) {
  const qc = useQueryClient();
  const toast = useToast();

  const [dialogOpen, setDialogOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<InterventionType | null>(null);
  const [form, setForm] = React.useState(EMPTY_FORM);
  const [deleteId, setDeleteId] = React.useState<number | null>(null);
  const [similarCandidates, setSimilarCandidates] = React.useState<SimilarInterventionTypeCandidate[] | null>(null);
  const [checkingSimilar, setCheckingSimilar] = React.useState(false);
  const [mergeSource, setMergeSource] = React.useState<InterventionType | null>(null);
  const [mergeTarget, setMergeTarget] = React.useState<InterventionType | null>(null);

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
  const mergeMutation = useMutation({
    mutationFn: ({ sourceId, targetId }: { sourceId: number; targetId: number }) => mergeInterventionType(sourceId, targetId),
    onSuccess: () => { toast.success("Types fusionnés"); invalidate(); closeMergeDialog(); },
    onError: (e) => toast.error(extractError(e)),
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
  function submitCreate() {
    createMutation.mutate({ code: form.code.trim(), label: form.label.trim(), specialty: form.specialty.trim() || undefined });
  }
  async function handleSubmit() {
    if (editing) {
      if (!form.label.trim()) return;
      updateMutation.mutate({ id: editing.id, label: form.label.trim(), specialty: form.specialty.trim() || undefined });
      return;
    }
    if (!form.code.trim() || !form.label.trim()) return;

    // Task 11, section 6 — suggestion de rapprochement AVANT création, jamais un blocage :
    // le manager choisit "Créer quand même" s'il s'agit réellement d'une intervention distincte.
    setCheckingSimilar(true);
    try {
      const candidates = await findSimilarInterventionTypes(form.label.trim());
      if (candidates.length > 0) {
        setSimilarCandidates(candidates);
        return;
      }
    } finally {
      setCheckingSimilar(false);
    }
    submitCreate();
  }

  const isPending = createMutation.isPending || updateMutation.isPending;

  function openMerge(type: InterventionType) {
    setMergeSource(type);
    setMergeTarget(null);
  }
  function closeMergeDialog() {
    setMergeSource(null);
    setMergeTarget(null);
  }

  return (
    <Box>
      {showHeader ? (
        <PageHeader
          icon={MedicalServicesIcon}
          title="Types d'intervention"
          subtitle="Référentiel médical fermé — indépendant des firmes et des tarifs."
          action={{ label: "Ajouter un type", onClick: openCreate }}
        />
      ) : (
        <Stack direction="row" justifyContent="flex-end" mb={2}>
          <Button variant="contained" disableElevation onClick={openCreate}>Ajouter un type</Button>
        </Stack>
      )}

      {typesQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 8 }}><CircularProgress /></Box>
      ) : types.length === 0 ? (
        <EmptyState
          variant="dashed"
          icon={MedicalServicesIcon}
          title="Aucun type d'intervention enregistré."
          action={{ label: "Ajouter le premier type", onClick: openCreate }}
        />
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow sx={{ "& th": { fontWeight: 700, bgcolor: "grey.50" } }}>
                <TableCell>Code</TableCell>
                <TableCell>Libellé</TableCell>
                <TableCell>Spécialité</TableCell>
                <TableCell align="right">Firmes</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right" sx={{ width: 130 }}>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {types.map((t) => (
                <TableRow
                  key={t.id}
                  hover
                  onClick={onOpenDetail ? () => onOpenDetail(t) : undefined}
                  sx={{
                    "&:last-child td": { borderBottom: 0 },
                    opacity: t.mergedIntoId ? 0.5 : 1,
                    cursor: onOpenDetail ? "pointer" : "default",
                  }}
                >
                  <TableCell>
                    <Typography fontWeight={700} variant="body2" sx={{ fontFamily: "monospace" }}>{t.code}</Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">{t.label}</Typography>
                    {t.mergedIntoId && (
                      <Typography variant="caption" color="text.secondary">Fusionné dans un autre type</Typography>
                    )}
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.secondary">{t.specialty ?? <em style={{ opacity: .5 }}>—</em>}</Typography>
                  </TableCell>
                  <TableCell align="right">
                    <Typography variant="body2" color="text.secondary">{t.firmsCount ?? 0}</Typography>
                  </TableCell>
                  <TableCell onClick={(e) => e.stopPropagation()}>
                    <ActiveBadge
                      active={t.active}
                      onClick={() => toggleActiveMutation.mutate({ id: t.id, active: !t.active })}
                    />
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end" onClick={(e) => e.stopPropagation()}>
                      <Tooltip title="Modifier">
                        <IconButton size="small" onClick={() => openEdit(t)} disabled={!!t.mergedIntoId}>
                          <EditIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title="Fusionner dans un autre type">
                        <IconButton size="small" onClick={() => openMerge(t)} disabled={!!t.mergedIntoId}>
                          <CallMergeIcon fontSize="small" />
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
          <Button variant="contained" disableElevation onClick={handleSubmit} disabled={isPending || checkingSimilar}>
            {isPending || checkingSimilar ? <CircularProgress size={16} /> : editing ? "Enregistrer" : "Créer"}
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

      {/* Task 11, section 6 — suggestion de rapprochement avant création, jamais un blocage. */}
      <Dialog open={similarCandidates !== null} onClose={() => setSimilarCandidates(null)} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
        <DialogTitle fontWeight={700}>Un type proche existe déjà</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>
            {(similarCandidates?.length ?? 0) > 1
              ? "Des types d'intervention au libellé proche existent déjà. Il peut s'agir de la même intervention clinique — vérifiez avant de créer un doublon."
              : "Un type d'intervention au libellé proche existe déjà. Il peut s'agir de la même intervention clinique — vérifiez avant de créer un doublon."}
          </DialogContentText>
          <Stack spacing={1}>
            {similarCandidates?.map((c) => (
              <Paper key={c.type.id} variant="outlined" sx={{ p: 1.5, display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                <Box>
                  <Typography fontWeight={700} variant="body2" sx={{ fontFamily: "monospace" }}>{c.type.code}</Typography>
                  <Typography variant="body2">{c.type.label}</Typography>
                </Box>
                <Chip size="small" label={CONFIDENCE_LABELS[c.confidence]} color={c.confidence === "HIGH" ? "warning" : "default"} />
              </Paper>
            ))}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={() => setSimilarCandidates(null)} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation disabled={createMutation.isPending}
            onClick={() => { setSimilarCandidates(null); submitCreate(); }}
          >
            {createMutation.isPending ? <CircularProgress size={16} /> : "Créer quand même"}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Task 11, section 7 — fusion explicite, jamais automatique. */}
      <Dialog open={mergeSource !== null} onClose={closeMergeDialog} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
        <DialogTitle fontWeight={700}>Fusionner « {mergeSource?.label} »</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>
            Les prestations, missions et règles tarifaires futures de ce type seront rattachées au type
            choisi ci-dessous. Les règles tarifaires déjà effectives et les documents financiers déjà
            émis ne sont jamais modifiés. Cette action est réservée aux doublons confirmés manuellement.
          </DialogContentText>
          <Autocomplete
            options={types.filter((t) => t.id !== mergeSource?.id && !t.mergedIntoId)}
            getOptionLabel={(t) => `${t.code} — ${t.label}`}
            value={mergeTarget}
            onChange={(_, value) => setMergeTarget(value)}
            renderInput={(params) => <TextField {...params} label="Fusionner dans" autoFocus />}
          />
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={closeMergeDialog} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation disabled={!mergeTarget || mergeMutation.isPending}
            onClick={() => mergeSource && mergeTarget && mergeMutation.mutate({ sourceId: mergeSource.id, targetId: mergeTarget.id })}
          >
            {mergeMutation.isPending ? <CircularProgress size={16} /> : "Fusionner"}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}

export default InterventionTypesManager;
