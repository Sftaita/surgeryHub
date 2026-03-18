import * as React from "react";
import {
  Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, Divider, IconButton, MenuItem, Paper, Select, Stack,
  Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import EditIcon from "@mui/icons-material/Edit";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import {
  getTemplates, createTemplate, deleteTemplate,
  type PlanningTemplate, type TemplateType,
} from "../../../features/planning-manager/api/planning.api";
import { useToast } from "../../../ui/toast/useToast";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

export default function PlanningTemplatesPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();

  const [createOpen, setCreateOpen] = React.useState(false);
  const [tutorialOpen, setTutorialOpen] = React.useState(false);
  const [newType, setNewType] = React.useState<TemplateType>("PAIR");
  const [newDateStart, setNewDateStart] = React.useState(new Date().toISOString().slice(0, 10));
  const [newDateEnd, setNewDateEnd] = React.useState("");

  const templatesQuery = useQuery({ queryKey: ["planning-templates"], queryFn: getTemplates });

  const createMutation = useMutation({
    mutationFn: () => createTemplate({ type: newType, dateStart: newDateStart, dateEnd: newDateEnd || null }),
    onSuccess: (tpl) => {
      toast.success("Template créé");
      qc.invalidateQueries({ queryKey: ["planning-templates"] });
      setCreateOpen(false);
      navigate(`/app/m/planning/templates/${tpl.id}`);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteTemplate,
    onSuccess: () => { toast.success("Template supprimé"); qc.invalidateQueries({ queryKey: ["planning-templates"] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  const templates = templatesQuery.data ?? [];

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
            Créez un template PAIR et un template IMPAIR pour définir la structure hebdomadaire du planning.
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
                <TableCell>Type</TableCell>
                <TableCell>Début</TableCell>
                <TableCell>Fin</TableCell>
                <TableCell align="right">Créneaux</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {templates.map((tpl: PlanningTemplate) => (
                <TableRow key={tpl.id} hover>
                  <TableCell>
                    <Chip
                      label={`Semaine ${tpl.type}`}
                      size="small"
                      color={tpl.type === "PAIR" ? "primary" : "secondary"}
                    />
                  </TableCell>
                  <TableCell>{tpl.dateStart}</TableCell>
                  <TableCell>{tpl.dateEnd ?? <Typography variant="body2" color="text.secondary">Indéfinie</Typography>}</TableCell>
                  <TableCell align="right">
                    <Chip label={`${tpl.slots?.length ?? 0} créneau(x)`} size="small" variant="outlined" />
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
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
            <Select value={newType} onChange={(e) => setNewType(e.target.value as TemplateType)} size="small" fullWidth>
              <MenuItem value="PAIR">Semaines PAIRES</MenuItem>
              <MenuItem value="IMPAIR">Semaines IMPAIRES</MenuItem>
            </Select>
            <TextField
              label="Date de début" type="date" value={newDateStart}
              onChange={(e) => setNewDateStart(e.target.value)}
              size="small" InputLabelProps={{ shrink: true }} fullWidth
            />
            <TextField
              label="Date de fin (optionnel)" type="date" value={newDateEnd}
              onChange={(e) => setNewDateEnd(e.target.value)}
              size="small" InputLabelProps={{ shrink: true }} fullWidth
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreateOpen(false)} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            onClick={() => createMutation.mutate()}
            disabled={!newDateStart || createMutation.isPending}
          >
            {createMutation.isPending ? <CircularProgress size={16} /> : "Créer"}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Tutorial dialog */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle fontWeight={700}>Comment fonctionnent les templates ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "PAIR vs IMPAIR", desc: "Créez un template par type de semaine (numéro ISO). La semaine 1 de 2026 est IMPAIRE. SurgicalHub alterne automatiquement." },
              { n: 2, title: "Soft versioning", desc: "Créer un nouveau template du même type ferme automatiquement l'ancien à la veille. Vous pouvez ainsi planifier un changement de planning à l'avance." },
              { n: 3, title: "Ajout de créneaux", desc: "Ouvrez un template et ajoutez des créneaux : pour chaque jour de la semaine (lundi→dimanche), définissez la période (matin/après-midi), le chirurgien, le type et l'instrumentiste par défaut." },
              { n: 4, title: "Génération", desc: "Une fois les templates configurés, allez dans \"Générer\" pour projeter les missions sur une période donnée." },
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
