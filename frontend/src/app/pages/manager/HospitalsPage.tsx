import * as React from "react";
import {
  Avatar, Box, Button, Chip, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, Divider, IconButton, MenuItem,
  Paper, Select, Stack, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import EditIcon         from "@mui/icons-material/Edit";
import DeleteIcon       from "@mui/icons-material/Delete";
import BusinessIcon     from "@mui/icons-material/Business";
import PhotoCameraIcon  from "@mui/icons-material/PhotoCamera";
import CloseIcon        from "@mui/icons-material/Close";
import AddIcon          from "@mui/icons-material/Add";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import { apiClient } from "../../api/apiClient";
import { resolveApiAssetUrl } from "../../api/apiAssetUrl";
import { useToast } from "../../ui/toast/useToast";
import { EmptyState } from "../../ui/EmptyState";
import { PageHeader } from "../../ui/PageHeader";

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// ── Types ──────────────────────────────────────────────────────────────────────
interface Hospital {
  id: number;
  name: string;
  address: string | null;
  timezone: string;
  photoPath: string | null;
  /**
   * Communication des absences chirurgiens (D-114, revue post-déploiement) — coordonnées
   * organisationnelles de l'établissement pour la gestion du bloc opératoire. Éditées ici,
   * jamais depuis Planning → Paramètres → Communication des absences (lecture seule là-bas).
   */
  blockManagementContactEmail: string | null;
  blockManagementContactCc: string[];
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

const EMPTY = { name: "", address: "", timezone: "Europe/Brussels", blockManagementContactEmail: "", blockManagementContactCc: [] as string[] };

// ── Page ───────────────────────────────────────────────────────────────────────
export default function HospitalsPage() {
  const qc    = useQueryClient();
  const toast = useToast();
  const [searchParams, setSearchParams] = useSearchParams();

  const [dialogOpen, setDialogOpen] = React.useState(false);
  const [editing,    setEditing]    = React.useState<Hospital | null>(null);
  const [form,       setForm]       = React.useState(EMPTY);
  const [newCc,      setNewCc]      = React.useState("");
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
    setForm({
      name: h.name, address: h.address ?? "", timezone: h.timezone,
      blockManagementContactEmail: h.blockManagementContactEmail ?? "",
      blockManagementContactCc: h.blockManagementContactCc,
    });
    setNewCc("");
    setPendingPhoto(null);
    setPendingPhotoUrl(resolveApiAssetUrl(h.photoPath) ?? null);
    setDialogOpen(true);
  }
  function closeDialog() {
    setDialogOpen(false);
    setEditing(null);
    setForm(EMPTY);
    setNewCc("");
    setPendingPhoto(null);
    setPendingPhotoUrl(null);
    if (searchParams.has("edit")) {
      searchParams.delete("edit");
      setSearchParams(searchParams, { replace: true });
    }
  }

  // Accès direct depuis Planning → Paramètres → Communication des absences
  // ("Modifier les contacts de l'établissement") — ouvre directement la fiche concernée.
  React.useEffect(() => {
    const editId = searchParams.get("edit");
    if (editId === null || sitesQuery.isLoading || dialogOpen) return;
    const target = sites.find((s) => s.id === Number(editId));
    if (target) openEdit(target);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams, sitesQuery.isLoading, sites]);

  function handlePhotoChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    setPendingPhoto(file);
    setPendingPhotoUrl(URL.createObjectURL(file));
  }
  function addCc() {
    const address = newCc.trim();
    if (address === "" || !EMAIL_RE.test(address)) {
      toast.error("Adresse email invalide.");
      return;
    }
    if (form.blockManagementContactCc.some((c) => c.toLowerCase() === address.toLowerCase()) || address.toLowerCase() === form.blockManagementContactEmail.trim().toLowerCase()) {
      toast.error("Cette adresse figure déjà dans la liste.");
      return;
    }
    setForm((f) => ({ ...f, blockManagementContactCc: [...f.blockManagementContactCc, address] }));
    setNewCc("");
  }
  function removeCc(address: string) {
    setForm((f) => ({ ...f, blockManagementContactCc: f.blockManagementContactCc.filter((c) => c !== address) }));
  }
  const contactEmailValid = form.blockManagementContactEmail.trim() === "" || EMAIL_RE.test(form.blockManagementContactEmail.trim());
  function handleSubmit() {
    if (!form.name.trim() || !contactEmailValid) return;
    const payload = {
      name: form.name.trim(), address: form.address.trim() || null, timezone: form.timezone,
      blockManagementContactEmail: form.blockManagementContactEmail.trim() || null,
      blockManagementContactCc: form.blockManagementContactCc,
    };
    if (editing) updateMutation.mutate({ id: editing.id, ...payload });
    else         createMutation.mutate(payload);
  }
  const isPending = createMutation.isPending || updateMutation.isPending;
  const currentPhoto = pendingPhotoUrl;

  return (
    <Box sx={{ p: 3, maxWidth: 960 }}>

      {/* Header */}
      <PageHeader
        icon={BusinessIcon}
        title="Établissements"
        subtitle="Hôpitaux, cliniques et centres chirurgicaux partenaires de Surgery Hub."
        action={{ label: "Ajouter un établissement", onClick: openCreate }}
      />

      {/* Table */}
      {sitesQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 8 }}><CircularProgress /></Box>
      ) : sites.length === 0 ? (
        <EmptyState
          variant="dashed"
          icon={BusinessIcon}
          title="Aucun établissement enregistré."
          action={{ label: "Ajouter le premier établissement", onClick: openCreate }}
        />
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

            <Divider />

            <Box>
              <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 0.25 }}>
                Contacts du bloc opératoire
              </Typography>
              <Typography variant="caption" color="text.secondary" sx={{ mb: 1.5, display: "block" }}>
                Ces coordonnées sont utilisées pour les communications organisationnelles envoyées
                à la gestion du bloc (notamment « Prévenir automatiquement la gestion du bloc »,
                Planning → Paramètres → Communication des absences).
              </Typography>
              <Stack spacing={1.5}>
                <TextField
                  label="Adresse principale"
                  size="small"
                  fullWidth
                  value={form.blockManagementContactEmail}
                  onChange={(e) => setForm((f) => ({ ...f, blockManagementContactEmail: e.target.value }))}
                  error={!contactEmailValid}
                  helperText={!contactEmailValid ? "Adresse email invalide" : " "}
                  placeholder="bloc@etablissement.be"
                />
                <Box>
                  <Typography variant="caption" fontWeight={600} color="text.secondary" sx={{ mb: 0.75, display: "block" }}>
                    Adresses en copie
                  </Typography>
                  <Stack spacing={0.75} sx={{ mb: 1 }}>
                    {form.blockManagementContactCc.map((address) => (
                      <Stack key={address} direction="row" alignItems="center" spacing={1} sx={{ bgcolor: "grey.50", border: "1px solid", borderColor: "divider", borderRadius: 1, px: 1.25, py: 0.5 }}>
                        <Typography variant="body2" sx={{ flex: 1 }}>{address}</Typography>
                        <IconButton size="small" aria-label={`Supprimer ${address}`} onClick={() => removeCc(address)}>
                          <CloseIcon sx={{ fontSize: 15 }} />
                        </IconButton>
                      </Stack>
                    ))}
                  </Stack>
                  <Stack direction="row" spacing={1}>
                    <TextField
                      size="small" fullWidth placeholder="ajouter une adresse CC"
                      value={newCc} onChange={(e) => setNewCc(e.target.value)}
                      onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addCc(); } }}
                    />
                    <Button size="small" startIcon={<AddIcon sx={{ fontSize: 16 }} />} onClick={addCc} sx={{ textTransform: "none", whiteSpace: "nowrap" }}>
                      Ajouter
                    </Button>
                  </Stack>
                </Box>
              </Stack>
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={closeDialog} color="inherit">Annuler</Button>
          <Button variant="contained" disableElevation onClick={handleSubmit} disabled={!form.name.trim() || !contactEmailValid || isPending}>
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
