import * as React from "react";
import {
  Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, FormControl, IconButton, InputLabel, MenuItem, Paper, Select,
  Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import EditIcon from "@mui/icons-material/Edit";
import ContentCopyIcon from "@mui/icons-material/ContentCopy";
import DriveFileRenameOutlineIcon from "@mui/icons-material/DriveFileRenameOutline";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import {
  getTemplates, createTemplate, deleteTemplate, patchTemplate, cloneTemplate,
  type PlanningTemplate, type TemplateType,
} from "../../../features/planning-manager/api/planning.api";
import { fetchSites, type Site } from "../../../features/sites/api/sites.api";
import { useToast } from "../../../ui/toast/useToast";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

const TYPE_LABELS: Record<TemplateType, string> = {
  PAIR:   "Semaines PAIRES",
  IMPAIR: "Semaines IMPAIRES",
  TOUTES: "Toutes semaines",
};

const TYPE_COLORS: Record<TemplateType, "primary" | "secondary" | "default"> = {
  PAIR:   "primary",
  IMPAIR: "secondary",
  TOUTES: "default",
};

export default function PlanningTemplatesPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();

  // Create
  const [createOpen, setCreateOpen] = React.useState(false);
  const [newType, setNewType] = React.useState<TemplateType>("PAIR");
  const [newSiteId, setNewSiteId] = React.useState<number | "">("");
  const [newLabel, setNewLabel] = React.useState("");

  // Edit (rename + type)
  const [renameOpen, setRenameOpen] = React.useState(false);
  const [renameId, setRenameId] = React.useState<number | null>(null);
  const [renameLabel, setRenameLabel] = React.useState("");
  const [renameType, setRenameType] = React.useState<TemplateType>("PAIR");

  // Tutorial
  const [tutorialOpen, setTutorialOpen] = React.useState(false);

  const templatesQuery = useQuery({ queryKey: ["planning-templates"], queryFn: getTemplates });
  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });

  const createMutation = useMutation({
    mutationFn: () => createTemplate({ type: newType, siteId: newSiteId as number, label: newLabel.trim() || undefined }),
    onSuccess: (tpl) => {
      toast.success("Template créé");
      qc.invalidateQueries({ queryKey: ["planning-templates"] });
      setCreateOpen(false);
      setNewSiteId("");
      setNewLabel("");
      navigate(`/app/m/planning/templates/${tpl.id}`);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const renameMutation = useMutation({
    mutationFn: () => patchTemplate(renameId!, { label: renameLabel.trim() || null, type: renameType }),
    onSuccess: () => {
      toast.success("Template mis à jour");
      qc.invalidateQueries({ queryKey: ["planning-templates"] });
      setRenameOpen(false);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteTemplate,
    onSuccess: () => { toast.success("Template supprimé"); qc.invalidateQueries({ queryKey: ["planning-templates"] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  const cloneMutation = useMutation({
    mutationFn: cloneTemplate,
    onSuccess: (tpl) => {
      toast.success("Template dupliqué");
      qc.invalidateQueries({ queryKey: ["planning-templates"] });
      navigate(`/app/m/planning/templates/${tpl.id}`);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const templates = templatesQuery.data ?? [];
  const sites: Site[] = sitesQuery.data ?? [];

  function openRename(tpl: PlanningTemplate) {
    setRenameId(tpl.id);
    setRenameLabel(tpl.label ?? "");
    setRenameType(tpl.type);
    setRenameOpen(true);
  }

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Stack direction="row" alignItems="center" spacing={1}>
          <Typography variant="h6" fontWeight={700}>Templates de planning</Typography>
          <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
            <HelpOutlineIcon fontSize="small" />
          </IconButton>
        </Stack>
        <Button variant="contained" disableElevation startIcon={<AddIcon />} onClick={() => setCreateOpen(true)}>
          Nouveau template
        </Button>
      </Stack>

      {templatesQuery.isLoading ? (
        <CircularProgress size={24} />
      ) : templates.length === 0 ? (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2.5 }}>
          <img src="https://cdn.undraw.co/illustration/online-calendar_ogka.svg" alt="" style={{ width: 240, opacity: 0.85 }} />
          <Typography variant="h6" fontWeight={600} color="text.secondary">Aucun template configuré</Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 380 }}>
            Créez un template PAIR, IMPAIR ou TOUTES pour définir la structure hebdomadaire du planning de chaque site.
          </Typography>
          <Button variant="outlined" startIcon={<AddIcon />} onClick={() => setCreateOpen(true)}>
            Créer un template
          </Button>
        </Box>
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell>Nom</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Site</TableCell>
                <TableCell align="right">Créneaux</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {templates.map((tpl: PlanningTemplate) => (
                <TableRow key={tpl.id} hover>
                  <TableCell>
                    <Stack direction="row" alignItems="center" spacing={0.5}>
                      {tpl.label ? (
                        <Typography variant="body2" fontWeight={600}>{tpl.label}</Typography>
                      ) : (
                        <Typography variant="body2" color="text.disabled" fontStyle="italic">Sans nom</Typography>
                      )}
                      <Tooltip title="Renommer / changer le type">
                        <IconButton size="small" sx={{ opacity: 0.4, "&:hover": { opacity: 1 } }} onClick={() => openRename(tpl)}>
                          <DriveFileRenameOutlineIcon sx={{ fontSize: 15 }} />
                        </IconButton>
                      </Tooltip>
                    </Stack>
                  </TableCell>
                  <TableCell>
                    <Chip
                      label={TYPE_LABELS[tpl.type] ?? tpl.type}
                      size="small"
                      color={TYPE_COLORS[tpl.type] ?? "default"}
                    />
                  </TableCell>
                  <TableCell>{tpl.site?.name ?? "—"}</TableCell>
                  <TableCell align="right">
                    <Chip label={`${tpl.slots?.length ?? 0} créneau(x)`} size="small" variant="outlined" />
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Tooltip title="Dupliquer">
                        <IconButton
                          size="small"
                          onClick={() => cloneMutation.mutate(tpl.id)}
                          disabled={cloneMutation.isPending}
                        >
                          <ContentCopyIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title="Éditer les créneaux">
                        <IconButton size="small" onClick={() => navigate(`/app/m/planning/templates/${tpl.id}`)}>
                          <EditIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title="Supprimer">
                        <IconButton
                          size="small" color="error"
                          onClick={() => { if (confirm("Supprimer ce template et tous ses créneaux ?")) deleteMutation.mutate(tpl.id); }}
                          disabled={deleteMutation.isPending}
                        >
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}

      {/* Create dialog */}
      <Dialog open={createOpen} onClose={() => setCreateOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle fontWeight={700}>Nouveau template</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField
              label="Nom du template (optionnel)"
              placeholder="Ex : Bloc d'hiver, Équipe A..."
              value={newLabel}
              onChange={(e) => setNewLabel(e.target.value)}
              size="small" fullWidth
            />
            <FormControl size="small" fullWidth>
              <InputLabel>Type de semaine</InputLabel>
              <Select
                value={newType}
                label="Type de semaine"
                onChange={(e) => setNewType(e.target.value as TemplateType)}
              >
                <MenuItem value="PAIR">Semaines PAIRES</MenuItem>
                <MenuItem value="IMPAIR">Semaines IMPAIRES</MenuItem>
                <MenuItem value="TOUTES">Toutes semaines</MenuItem>
              </Select>
            </FormControl>
            <FormControl size="small" fullWidth required>
              <InputLabel>Site *</InputLabel>
              <Select
                value={newSiteId}
                label="Site *"
                onChange={(e) => setNewSiteId(e.target.value as number)}
              >
                {sites.map((s) => (
                  <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>
                ))}
              </Select>
            </FormControl>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreateOpen(false)} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            onClick={() => createMutation.mutate()}
            disabled={!newSiteId || createMutation.isPending}
          >
            {createMutation.isPending ? <CircularProgress size={16} /> : "Créer"}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Edit dialog (rename + type) */}
      <Dialog open={renameOpen} onClose={() => setRenameOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle fontWeight={700}>Modifier le template</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField
              label="Nom"
              placeholder="Ex : Bloc d'hiver, Équipe A..."
              value={renameLabel}
              onChange={(e) => setRenameLabel(e.target.value)}
              size="small" fullWidth autoFocus
              helperText="Laissez vide pour supprimer le nom"
            />
            <FormControl size="small" fullWidth>
              <InputLabel>Type de semaine</InputLabel>
              <Select
                value={renameType}
                label="Type de semaine"
                onChange={(e) => setRenameType(e.target.value as TemplateType)}
              >
                <MenuItem value="PAIR">Semaines PAIRES</MenuItem>
                <MenuItem value="IMPAIR">Semaines IMPAIRES</MenuItem>
                <MenuItem value="TOUTES">Toutes semaines</MenuItem>
              </Select>
            </FormControl>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRenameOpen(false)} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            onClick={() => renameMutation.mutate()}
            disabled={renameMutation.isPending}
          >
            {renameMutation.isPending ? <CircularProgress size={16} /> : "Enregistrer"}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Tutorial dialog */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle fontWeight={700}>Comment fonctionnent les templates ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "PAIR, IMPAIR ou TOUTES", desc: "Chaque template couvre un type de semaine selon le numéro ISO. PAIR s'applique aux semaines paires, IMPAIR aux semaines impaires, TOUTES à chaque semaine sans distinction." },
              { n: 2, title: "Lié à un site", desc: "Chaque template est attaché à un site hospitalier. Vous pouvez avoir plusieurs templates par site (ex. : un PAIR et un IMPAIR pour le même site)." },
              { n: 3, title: "Nom personnalisé", desc: "Donnez un nom à chaque template (ex. « Bloc d'hiver », « Équipe A ») pour les identifier facilement." },
              { n: 4, title: "Ajout de créneaux", desc: "Ouvrez un template et ajoutez des créneaux : pour chaque jour (lundi → dimanche), définissez la période (matin/après-midi), le chirurgien, le type de mission et l'instrumentiste par défaut." },
              { n: 5, title: "Génération", desc: "Une fois les templates configurés, allez dans « Générer » pour projeter les missions sur une période donnée." },
            ].map(({ n, title, desc }) => (
              <Stack key={n} direction="row" spacing={2} alignItems="flex-start">
                <Box sx={{ minWidth: 32, height: 32, borderRadius: "50%", bgcolor: "primary.main", color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 14, flexShrink: 0 }}>
                  {n}
                </Box>
                <Box>
                  <Typography variant="subtitle2" fontWeight={700}>{title}</Typography>
                  <Typography variant="body2" color="text.secondary">{desc}</Typography>
                </Box>
              </Stack>
            ))}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTutorialOpen(false)} variant="contained" disableElevation>J'ai compris</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
