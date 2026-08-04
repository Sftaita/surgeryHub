import * as React from "react";
import {
  Box, Button, Chip, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, Divider, IconButton, InputAdornment,
  Paper, Stack, Switch, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import EditIcon       from "@mui/icons-material/Edit";
import DeleteIcon     from "@mui/icons-material/Delete";
import BusinessIcon   from "@mui/icons-material/Business";
import AddCircleOutlineIcon from "@mui/icons-material/AddCircleOutline";
import RemoveCircleOutlineIcon from "@mui/icons-material/RemoveCircleOutline";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "../../api/apiClient";
import { useToast } from "../../ui/toast/useToast";
import { EmptyState } from "../../ui/EmptyState";
import { PageHeader } from "../../ui/PageHeader";
import { ActiveBadge } from "../../ui/StatusBadge";
import { FirmAvatar } from "../../features/manager-catalogue/components/FirmAvatar";

// ── Types ──────────────────────────────────────────────────────────────────────
interface Firm {
  id: number;
  name: string;
  active: boolean;
  billingEmail: string | null;
  billingEmailCc: string[];
  country: string | null;
  representative: string | null;
  phone: string | null;
  logoPath: string | null;
}

// ── API ────────────────────────────────────────────────────────────────────────
const fetchFirms  = async (): Promise<Firm[]>    => (await apiClient.get("/api/firms")).data;
const createFirm  = async (d: Omit<Firm, "id">): Promise<Firm> => (await apiClient.post("/api/firms", d)).data;
const updateFirm  = async ({ id, ...d }: Firm): Promise<Firm>  => (await apiClient.patch(`/api/firms/${id}`, d)).data;
const deleteFirm  = async (id: number): Promise<void>          => { await apiClient.delete(`/api/firms/${id}`); };

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? "Erreur inconnue";
}

// ── Defaults ───────────────────────────────────────────────────────────────────
const EMPTY_FORM = { name: "", active: true, billingEmail: "", billingEmailCc: [] as string[], country: "", representative: "", phone: "" };

// ── Page ───────────────────────────────────────────────────────────────────────
export default function FirmsPage() {
  const qc    = useQueryClient();
  const toast = useToast();

  const [dialogOpen, setDialogOpen] = React.useState(false);
  const [editing,    setEditing]    = React.useState<Firm | null>(null);
  const [form,       setForm]       = React.useState(EMPTY_FORM);
  const [ccDraft,    setCcDraft]    = React.useState("");   // champ temporaire pour ajouter un CC
  const [deleteId,   setDeleteId]   = React.useState<number | null>(null);

  const firmsQuery = useQuery({ queryKey: ["firms"], queryFn: fetchFirms });
  const firms      = firmsQuery.data ?? [];
  const invalidate = () => qc.invalidateQueries({ queryKey: ["firms"] });

  const createMutation = useMutation({
    mutationFn: createFirm,
    onSuccess: () => { toast.success("Firme créée"); invalidate(); closeDialog(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const updateMutation = useMutation({
    mutationFn: updateFirm,
    onSuccess: () => { toast.success("Firme mise à jour"); invalidate(); closeDialog(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const deleteMutation = useMutation({
    mutationFn: deleteFirm,
    onSuccess: () => { toast.success("Firme supprimée"); invalidate(); setDeleteId(null); },
    onError: (e) => { toast.error(extractError(e)); setDeleteId(null); },
  });

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setCcDraft("");
    setDialogOpen(true);
  }
  function openEdit(f: Firm) {
    setEditing(f);
    setForm({ name: f.name, active: f.active, billingEmail: f.billingEmail ?? "", billingEmailCc: f.billingEmailCc, country: f.country ?? "", representative: f.representative ?? "", phone: f.phone ?? "" });
    setCcDraft("");
    setDialogOpen(true);
  }
  function closeDialog() {
    setDialogOpen(false);
    setEditing(null);
    setForm(EMPTY_FORM);
    setCcDraft("");
  }

  function addCc() {
    const email = ccDraft.trim();
    if (!email || form.billingEmailCc.includes(email)) return;
    setForm((f) => ({ ...f, billingEmailCc: [...f.billingEmailCc, email] }));
    setCcDraft("");
  }
  function removeCc(email: string) {
    setForm((f) => ({ ...f, billingEmailCc: f.billingEmailCc.filter((e) => e !== email) }));
  }

  function handleSubmit() {
    if (!form.name.trim()) return;
    const payload: Omit<Firm, "id"> = {
      name:           form.name.trim(),
      active:         form.active,
      billingEmail:   form.billingEmail.trim() || null,
      billingEmailCc: form.billingEmailCc,
      country:        form.country.trim() || null,
      representative: form.representative.trim() || null,
      phone:          form.phone.trim() || null,
      // Logo géré uniquement via son propre endpoint dédié (voir FirmAvatar/
      // Prestations) — jamais ce formulaire ; on repropage la valeur existante en
      // édition (ignorée par le backend ici de toute façon) pour satisfaire le typage.
      logoPath:       editing?.logoPath ?? null,
    };
    if (editing) updateMutation.mutate({ id: editing.id, ...payload });
    else         createMutation.mutate(payload);
  }

  const isPending = createMutation.isPending || updateMutation.isPending;

  return (
    <Box sx={{ p: 3, maxWidth: 960 }}>

      {/* Header */}
      <PageHeader
        icon={BusinessIcon}
        title="Firmes partenaires"
        subtitle="Sociétés de matériel et fournisseurs avec qui Surgery Hub collabore."
        helpTopicId="firms"
        action={{ label: "Ajouter une firme", onClick: openCreate }}
      />

      {/* Table */}
      {firmsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 8 }}><CircularProgress /></Box>
      ) : firms.length === 0 ? (
        <EmptyState
          variant="dashed"
          icon={BusinessIcon}
          title="Aucune firme enregistrée."
          action={{ label: "Ajouter la première firme", onClick: openCreate }}
        />
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow sx={{ "& th": { fontWeight: 700, bgcolor: "grey.50" } }}>
                <TableCell>Nom</TableCell>
                <TableCell>Représentant</TableCell>
                <TableCell>Téléphone</TableCell>
                <TableCell>Email facturation</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right" sx={{ width: 100 }}>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {firms.map((f) => (
                <TableRow key={f.id} hover sx={{ "&:last-child td": { borderBottom: 0 } }}>
                  <TableCell>
                    <Stack direction="row" spacing={1.5} alignItems="center">
                      <FirmAvatar name={f.name} logoPath={f.logoPath} size="sm" />
                      <Box>
                        <Typography fontWeight={600} variant="body2">{f.name}</Typography>
                        {f.country && <Typography variant="caption" color="text.secondary">{f.country}</Typography>}
                      </Box>
                    </Stack>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">{f.representative ?? <em style={{ opacity: .5 }}>—</em>}</Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.secondary">{f.phone ?? <em style={{ opacity: .5 }}>—</em>}</Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.secondary">
                      {f.billingEmail ?? <em style={{ opacity: .5 }}>—</em>}
                    </Typography>
                    {f.billingEmailCc.length > 0 && (
                      <Chip label={`+${f.billingEmailCc.length} CC`} size="small" variant="outlined" sx={{ fontSize: ".68rem", mt: 0.25 }} />
                    )}
                  </TableCell>
                  <TableCell>
                    <ActiveBadge
                      active={f.active}
                      activeLabel="Active"
                      inactiveLabel="Inactive"
                      filledWhenActive
                      sx={{ fontSize: ".72rem" }}
                    />
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Tooltip title="Modifier">
                        <IconButton size="small" onClick={() => openEdit(f)}>
                          <EditIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title="Supprimer">
                        <IconButton size="small" color="error" onClick={() => setDeleteId(f.id)}>
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

      {/* ── Dialog create / edit ─────────────────────── */}
      <Dialog open={dialogOpen} onClose={closeDialog} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
        <DialogTitle fontWeight={700}>
          {editing ? "Modifier la firme" : "Nouvelle firme partenaire"}
        </DialogTitle>
        <Divider />
        <DialogContent>
          <Stack spacing={2.5} sx={{ pt: 2 }}>

            <TextField
              label="Nom de la firme *"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              fullWidth autoFocus
              error={form.name.trim() === ""}
              helperText={form.name.trim() === "" ? "Le nom est obligatoire" : ""}
            />

            <Stack direction="row" spacing={2}>
              <TextField
                label="Pays"
                value={form.country}
                onChange={(e) => setForm((f) => ({ ...f, country: e.target.value }))}
                fullWidth
                placeholder="Belgique"
              />
              <TextField
                label="Téléphone"
                value={form.phone}
                onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))}
                fullWidth
                placeholder="+32 2 000 00 00"
              />
            </Stack>

            <TextField
              label="Représentant"
              value={form.representative}
              onChange={(e) => setForm((f) => ({ ...f, representative: e.target.value }))}
              fullWidth
              placeholder="Nom du représentant commercial"
            />

            <TextField
              label="Email de facturation"
              type="email"
              value={form.billingEmail}
              onChange={(e) => setForm((f) => ({ ...f, billingEmail: e.target.value }))}
              fullWidth
              placeholder="facturation@firme.be"
              helperText="Cet email reçoit les factures générées."
            />

            {/* CC emails */}
            <Box>
              <Typography variant="caption" fontWeight={600} color="text.secondary" sx={{ mb: 1, display: "block" }}>
                Emails en copie (CC)
              </Typography>

              {/* Existing CC chips */}
              {form.billingEmailCc.length > 0 && (
                <Stack direction="row" flexWrap="wrap" gap={0.75} mb={1.5}>
                  {form.billingEmailCc.map((email) => (
                    <Chip
                      key={email}
                      label={email}
                      size="small"
                      onDelete={() => removeCc(email)}
                      deleteIcon={<RemoveCircleOutlineIcon />}
                      variant="outlined"
                    />
                  ))}
                </Stack>
              )}

              {/* Add CC input */}
              <TextField
                size="small"
                fullWidth
                type="email"
                placeholder="autre@firme.be"
                value={ccDraft}
                onChange={(e) => setCcDraft(e.target.value)}
                onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addCc(); } }}
                InputProps={{
                  endAdornment: (
                    <InputAdornment position="end">
                      <Tooltip title="Ajouter">
                        <IconButton size="small" onClick={addCc} disabled={!ccDraft.trim()}>
                          <AddCircleOutlineIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                    </InputAdornment>
                  ),
                }}
                helperText="Appuyez sur Entrée ou cliquez + pour ajouter."
              />
            </Box>

            {/* Actif toggle */}
            <Stack direction="row" alignItems="center" justifyContent="space-between"
              sx={{ bgcolor: "grey.50", borderRadius: 2, px: 2, py: 1.25 }}
            >
              <Box>
                <Typography variant="body2" fontWeight={600}>Firme active</Typography>
                <Typography variant="caption" color="text.secondary">
                  Une firme inactive n'apparaît plus dans les sélections de missions.
                </Typography>
              </Box>
              <Switch
                checked={form.active}
                onChange={(e) => setForm((f) => ({ ...f, active: e.target.checked }))}
                color="primary"
              />
            </Stack>

          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={closeDialog} color="inherit">Annuler</Button>
          <Button variant="contained" disableElevation onClick={handleSubmit} disabled={!form.name.trim() || isPending}>
            {isPending ? <CircularProgress size={16} /> : editing ? "Enregistrer" : "Créer"}
          </Button>
        </DialogActions>
      </Dialog>

      {/* ── Confirm delete ───────────────────────────── */}
      <Dialog open={deleteId !== null} onClose={() => setDeleteId(null)} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
        <DialogTitle fontWeight={700}>Supprimer la firme</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary">
            Cette action est irréversible.
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
