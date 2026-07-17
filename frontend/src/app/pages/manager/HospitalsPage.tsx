import * as React from "react";
import {
  Avatar, Box, Button, Chip, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, Divider, IconButton, MenuItem,
  Paper, Select, Stack, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import AddIcon          from "@mui/icons-material/Add";
import EditIcon         from "@mui/icons-material/Edit";
import DeleteIcon       from "@mui/icons-material/Delete";
import BusinessIcon     from "@mui/icons-material/Business";
import PhotoCameraIcon  from "@mui/icons-material/PhotoCamera";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "../../api/apiClient";
import { resolveApiAssetUrl } from "../../api/apiAssetUrl";
import { useToast } from "../../ui/toast/useToast";

// ── Types ──────────────────────────────────────────────────────────────────────
interface Hospital {
  id: number;
  name: string;
  address: string | null;
  timezone: string;
  photoPath: string | null;
}

// ── API ────────────────────────────────────────────────────────────────────────
const fetchSites  = async (): Promise<Hospital[]>               => (await apiClient.get("/api/sites")).data;
const createSite  = async (d: Omit<Hospital, "id" | "photoPath">): Promise<Hospital> => (await apiClient.post("/api/sites", d)).data;
const updateSite  = async ({ id, ...d }: Omit<Hospital, "photoPath"> & { id: number }): Promise<Hospital> => (await apiClient.patch(`/api/sites/${id}`, d)).data;
const deleteSite  = async (id: number): Promise<void>           => { await apiClient.delete(`/api/sites/${id}`); };
const uploadPhoto = async ({ id, file }: { id: number; file: File }): Promise<{ photoPath: string }> => {
  const fd = new FormData();
  fd.append("photo", file);
  return (await apiClient.post(`/api/sites/${id}/photo`, fd, { headers: { "Content-Type": "multipart/form-data" } })).data;
};

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? "Erreur inconnue";
}


// ── Timezones ──────────────────────────────────────────────────────────────────
const TIMEZONES = [
  "Europe/Brussels",
  "Europe/Paris",
  "Europe/Luxembourg",
  "Europe/Amsterdam",
  "Europe/London",
  "Europe/Zurich",
];

const EMPTY = { name: "", address: "", timezone: "Europe/Brussels" };

// ── Page ───────────────────────────────────────────────────────────────────────
export default function HospitalsPage() {
  const qc    = useQueryClient();
  const toast = useToast();

  const [dialogOpen, setDialogOpen] = React.useState(false);
  const [editing,    setEditing]    = React.useState<Hospital | null>(null);
  const [form,       setForm]       = React.useState(EMPTY);
  const [deleteId,   setDeleteId]   = React.useState<number | null>(null);

  // Photo upload state (pendant la session édition/création)
  const [pendingPhoto,    setPendingPhoto]    = React.useState<File | null>(null);
  const [pendingPhotoUrl, setPendingPhotoUrl] = React.useState<string | null>(null);
  const fileInputRef = React.useRef<HTMLInputElement>(null);

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const sites      = sitesQuery.data ?? [];
  const invalidate = () => qc.invalidateQueries({ queryKey: ["sites"] });

  const createMutation = useMutation({
    mutationFn: createSite,
    onSuccess: async (created) => {
      if (pendingPhoto) {
        try { await uploadPhoto({ id: created.id, file: pendingPhoto }); } catch {}
      }
      toast.success("Établissement créé");
      invalidate();
      closeDialog();
    },
    onError: (e) => toast.error(extractError(e)),
  });
  const updateMutation = useMutation({
    mutationFn: updateSite,
    onSuccess: async (updated) => {
      if (pendingPhoto) {
        try { await uploadPhoto({ id: updated.id, file: pendingPhoto }); } catch {}
      }
      toast.success("Établissement mis à jour");
      invalidate();
      closeDialog();
    },
    onError: (e) => toast.error(extractError(e)),
  });
  const deleteMutation = useMutation({
    mutationFn: deleteSite,
    onSuccess: () => { toast.success("Établissement supprimé"); invalidate(); setDeleteId(null); },
    onError: (e) => { toast.error(extractError(e)); setDeleteId(null); },
  });

  function openCreate() {
    setEditing(null);
    setForm(EMPTY);
    setPendingPhoto(null);
    setPendingPhotoUrl(null);
    setDialogOpen(true);
  }
  function openEdit(h: Hospital) {
    setEditing(h);
    setForm({ name: h.name, address: h.address ?? "", timezone: h.timezone });
    setPendingPhoto(null);
    setPendingPhotoUrl(resolveApiAssetUrl(h.photoPath) ?? null);
    setDialogOpen(true);
  }
  function closeDialog() {
    setDialogOpen(false);
    setEditing(null);
    setForm(EMPTY);
    setPendingPhoto(null);
    setPendingPhotoUrl(null);
  }
  function handlePhotoChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    setPendingPhoto(file);
    setPendingPhotoUrl(URL.createObjectURL(file));
  }
  function handleSubmit() {
    if (!form.name.trim()) return;
    const payload = { name: form.name.trim(), address: form.address.trim() || null, timezone: form.timezone };
    if (editing) updateMutation.mutate({ id: editing.id, ...payload });
    else         createMutation.mutate(payload);
  }
  const isPending = createMutation.isPending || updateMutation.isPending;
  const currentPhoto = pendingPhotoUrl;

  return (
    <Box sx={{ p: 3, maxWidth: 960 }}>

      {/* Header */}
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start" mb={3}>
        <Box>
          <Stack direction="row" alignItems="center" spacing={1.5} mb={0.5}>
            <BusinessIcon sx={{ color: "primary.main", fontSize: 28 }} />
            <Typography variant="h5">Établissements</Typography>
          </Stack>
          <Typography variant="body2" color="text.secondary">
            Hôpitaux, cliniques et centres chirurgicaux partenaires de Surgery Hub.
          </Typography>
        </Box>
        <Button variant="contained" startIcon={<AddIcon />} onClick={openCreate} disableElevation>
          Ajouter un établissement
        </Button>
      </Stack>

      {/* Table */}
      {sitesQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 8 }}><CircularProgress /></Box>
      ) : sites.length === 0 ? (
        <Paper variant="outlined" sx={{ py: 8, textAlign: "center", borderStyle: "dashed" }}>
          <BusinessIcon sx={{ fontSize: 48, color: "text.disabled", mb: 1 }} />
          <Typography color="text.secondary">Aucun établissement enregistré.</Typography>
          <Button onClick={openCreate} sx={{ mt: 2 }} variant="contained" disableElevation>
            Ajouter le premier établissement
          </Button>
        </Paper>
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow sx={{ "& th": { fontWeight: 700, bgcolor: "grey.50" } }}>
                <TableCell sx={{ width: 56 }} />
                <TableCell>Nom</TableCell>
                <TableCell>Adresse</TableCell>
                <TableCell>Fuseau horaire</TableCell>
                <TableCell align="right" sx={{ width: 100 }}>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {sites.map((h) => {
                const url = resolveApiAssetUrl(h.photoPath) ?? null;
                return (
                  <TableRow key={h.id} hover sx={{ "&:last-child td": { borderBottom: 0 } }}>
                    <TableCell>
                      <Avatar
                        src={url ?? undefined}
                        variant="rounded"
                        sx={{ width: 40, height: 40, bgcolor: "primary.light" }}
                      >
                        <BusinessIcon sx={{ fontSize: 20 }} />
                      </Avatar>
                    </TableCell>
                    <TableCell>
                      <Typography fontWeight={600} variant="body2">{h.name}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" color="text.secondary">
                        {h.address ?? <em style={{ opacity: .5 }}>—</em>}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Chip label={h.timezone} size="small" variant="outlined" sx={{ fontSize: ".72rem" }} />
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                        <Tooltip title="Modifier">
                          <IconButton size="small" onClick={() => openEdit(h)}>
                            <EditIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                        <Tooltip title="Supprimer">
                          <IconButton size="small" color="error" onClick={() => setDeleteId(h.id)}>
                            <DeleteIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      </Stack>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {/* ── Create / Edit dialog ─────────────────────── */}
      <Dialog open={dialogOpen} onClose={closeDialog} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
        <DialogTitle fontWeight={700}>
          {editing ? "Modifier l'établissement" : "Nouvel établissement"}
        </DialogTitle>
        <Divider />
        <DialogContent>
          <Stack spacing={2.5} sx={{ pt: 2 }}>

            {/* Photo upload */}
            <Box>
              <Typography variant="caption" fontWeight={600} color="text.secondary" sx={{ mb: 1, display: "block" }}>
                Photo de l'établissement
              </Typography>
              <Stack direction="row" spacing={2} alignItems="center">
                <Avatar
                  src={currentPhoto ?? undefined}
                  variant="rounded"
                  sx={{ width: 80, height: 80, bgcolor: "grey.100", border: "1.5px dashed", borderColor: "divider" }}
                >
                  <BusinessIcon sx={{ fontSize: 32, color: "text.disabled" }} />
                </Avatar>
                <Box>
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    style={{ display: "none" }}
                    onChange={handlePhotoChange}
                  />
                  <Button
                    variant="outlined"
                    size="small"
                    startIcon={<PhotoCameraIcon />}
                    onClick={() => fileInputRef.current?.click()}
                  >
                    {currentPhoto ? "Changer la photo" : "Ajouter une photo"}
                  </Button>
                  <Typography variant="caption" color="text.secondary" sx={{ display: "block", mt: 0.5 }}>
                    JPEG, PNG ou WebP — max 5 Mo
                  </Typography>
                </Box>
              </Stack>
            </Box>

            <Divider />

            <TextField
              label="Nom de l'établissement *"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              fullWidth
              autoFocus
              error={form.name.trim() === ""}
              helperText={form.name.trim() === "" ? "Le nom est obligatoire" : ""}
            />
            <TextField
              label="Adresse"
              value={form.address}
              onChange={(e) => setForm((f) => ({ ...f, address: e.target.value }))}
              fullWidth
              placeholder="Rue, ville, code postal"
              multiline
              rows={2}
            />
            <Box>
              <Typography variant="caption" fontWeight={600} color="text.secondary" sx={{ mb: 0.75, display: "block" }}>
                Fuseau horaire
              </Typography>
              <Select
                value={form.timezone}
                onChange={(e) => setForm((f) => ({ ...f, timezone: e.target.value }))}
                fullWidth size="small"
              >
                {TIMEZONES.map((tz) => <MenuItem key={tz} value={tz}>{tz}</MenuItem>)}
              </Select>
            </Box>
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
        <DialogTitle fontWeight={700}>Supprimer l'établissement</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary">
            Cette action est irréversible. L'établissement ne peut être supprimé que s'il n'est lié à aucune mission.
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
