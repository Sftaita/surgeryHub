import * as React from "react";
import {
  Alert,
  Autocomplete,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  IconButton,
  MenuItem,
  Paper,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import EditIcon from "@mui/icons-material/Edit";
import ArrowUpwardIcon from "@mui/icons-material/ArrowUpward";
import ArrowDownwardIcon from "@mui/icons-material/ArrowDownward";
import ExpandMoreIcon from "@mui/icons-material/ExpandMore";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getFirmPricingRules,
  createPricingRule,
  updatePricingRule,
  deletePricingRule,
  updateFirmBillingContact,
  getFirmServiceOfferings,
  createFirmServiceOffering,
  updateFirmServiceOffering,
  addSuggestedMaterial,
  reorderSuggestedMaterials,
  deleteSuggestedMaterial,
  type PricingRule,
  type FirmServiceOffering,
} from "../../../features/billing-firm/api/firmBilling.api";
import {
  getInterventionTypes,
  createInterventionType,
  type InterventionType,
} from "../../../features/intervention-types/api/interventionTypes.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

type MaterialItemRow = { id: number; label: string; referenceCode: string | null };

// ── Dialog : ajouter une prestation ─────────────────────────────────────────
function AddOfferingDialog({
  open, onClose, firmId, existingTypeIds, onCreated,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  existingTypeIds: number[];
  onCreated: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [interventionTypeId, setInterventionTypeId] = React.useState<number | "">("");
  const [creatingNewType, setCreatingNewType] = React.useState(false);
  const [newCode, setNewCode] = React.useState("");
  const [newLabel, setNewLabel] = React.useState("");

  React.useEffect(() => {
    if (open) {
      setInterventionTypeId("");
      setCreatingNewType(false);
      setNewCode("");
      setNewLabel("");
    }
  }, [open]);

  const typesQuery = useQuery({ queryKey: ["intervention-types", "active"], queryFn: () => getInterventionTypes(true), enabled: open });
  const availableTypes = (typesQuery.data ?? []).filter((t) => !existingTypeIds.includes(t.id));

  const createTypeMutation = useMutation({
    mutationFn: createInterventionType,
    onSuccess: (created) => {
      qc.invalidateQueries({ queryKey: ["intervention-types"] });
      setInterventionTypeId(created.id);
      setCreatingNewType(false);
    },
    onError: (e) => toast.error(extractError(e)),
  });

  const createOfferingMutation = useMutation({
    mutationFn: () => createFirmServiceOffering(firmId, { interventionTypeId: interventionTypeId as number }),
    onSuccess: () => { toast.success("Prestation créée"); onCreated(); onClose(); },
    onError: (e) => toast.error(extractError(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Ajouter une prestation</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {!creatingNewType ? (
            <>
              <Select
                fullWidth size="small" displayEmpty
                value={interventionTypeId}
                onChange={(e) => setInterventionTypeId(Number(e.target.value))}
              >
                <MenuItem value="" disabled>
                  {typesQuery.isLoading ? "Chargement…" : "Sélectionner un type d'intervention"}
                </MenuItem>
                {availableTypes.map((t) => (
                  <MenuItem key={t.id} value={t.id}>{t.label} <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1 }}>({t.code})</Typography></MenuItem>
                ))}
              </Select>
              <Button size="small" onClick={() => setCreatingNewType(true)} sx={{ alignSelf: "flex-start" }}>
                + Nouveau type d'intervention
              </Button>
            </>
          ) : (
            <>
              <TextField
                label="Code *" size="small" value={newCode}
                onChange={(e) => setNewCode(e.target.value.toUpperCase())}
                inputProps={{ style: { fontFamily: "monospace", fontWeight: 700 } }}
                placeholder="Ex : LCA-PRIMAIRE"
              />
              <TextField label="Libellé *" size="small" value={newLabel} onChange={(e) => setNewLabel(e.target.value)} placeholder="Ex : LCA primaire" />
              <Stack direction="row" spacing={1}>
                <Button size="small" onClick={() => setCreatingNewType(false)}>Annuler</Button>
                <Button
                  size="small" variant="contained" disableElevation
                  disabled={!newCode.trim() || !newLabel.trim() || createTypeMutation.isPending}
                  onClick={() => createTypeMutation.mutate({ code: newCode.trim(), label: newLabel.trim() })}
                >
                  {createTypeMutation.isPending ? <CircularProgress size={14} /> : "Créer le type"}
                </Button>
              </Stack>
            </>
          )}
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} color="inherit">Annuler</Button>
        <Button
          variant="contained" disableElevation
          disabled={!interventionTypeId || createOfferingMutation.isPending}
          onClick={() => createOfferingMutation.mutate()}
        >
          {createOfferingMutation.isPending ? <CircularProgress size={16} /> : "Créer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Dialog : forfait d'intervention (créer/modifier) ────────────────────────
function ForfaitDialog({
  open, onClose, firmId, interventionTypeId, existingRule,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  interventionTypeId: number;
  existingRule: PricingRule | null;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [unitPrice, setUnitPrice] = React.useState("");
  const [validFrom, setValidFrom] = React.useState("");
  const [validTo, setValidTo] = React.useState("");

  React.useEffect(() => {
    if (open) {
      setUnitPrice(existingRule?.unitPrice ?? "");
      setValidFrom(existingRule?.validFrom ?? "");
      setValidTo(existingRule?.validTo ?? "");
    }
  }, [open, existingRule]);

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        unitPrice: Number(unitPrice),
        validFrom: validFrom || null,
        validTo: validTo || null,
      };
      return existingRule
        ? updatePricingRule(firmId, existingRule.id, payload)
        : createPricingRule(firmId, { ruleType: "INTERVENTION_FEE", interventionTypeId, ...payload });
    },
    onSuccess: () => {
      toast.success("Forfait enregistré");
      qc.invalidateQueries({ queryKey: ["pricing-rules", firmId] });
      onClose();
    },
    onError: (e) => toast.error(extractError(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>{existingRule ? "Modifier le forfait" : "Définir un forfait"}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <TextField
            label="Montant (€) *" type="number" size="small" value={unitPrice}
            onChange={(e) => setUnitPrice(e.target.value)}
            inputProps={{ min: 0, step: "0.01" }}
          />
          <Stack direction="row" spacing={1.5}>
            <TextField
              label="Valide à partir de" type="date" size="small" fullWidth
              value={validFrom} onChange={(e) => setValidFrom(e.target.value)}
              InputLabelProps={{ shrink: true }}
              helperText="Vide = depuis toujours"
            />
            <TextField
              label="Valide jusqu'à" type="date" size="small" fullWidth
              value={validTo} onChange={(e) => setValidTo(e.target.value)}
              InputLabelProps={{ shrink: true }}
              helperText="Vide = sans fin"
            />
          </Stack>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} color="inherit">Annuler</Button>
        <Button
          variant="contained" disableElevation
          disabled={!unitPrice || Number(unitPrice) < 0 || saveMutation.isPending}
          onClick={() => saveMutation.mutate()}
        >
          {saveMutation.isPending ? <CircularProgress size={16} /> : "Enregistrer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Dialog : matériels suggérés d'une prestation ────────────────────────────
function SuggestedMaterialsDialog({
  open, onClose, firmId, offering, firmMaterials,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  offering: FirmServiceOffering | null;
  firmMaterials: MaterialItemRow[];
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [search, setSearch] = React.useState("");

  const invalidate = () => qc.invalidateQueries({ queryKey: ["service-offerings", firmId] });

  const addMutation = useMutation({
    mutationFn: (materialItemId: number) => addSuggestedMaterial(firmId, offering!.id, materialItemId),
    onSuccess: invalidate,
    onError: (e) => toast.error(extractError(e)),
  });
  const reorderMutation = useMutation({
    mutationFn: (orderedIds: number[]) => reorderSuggestedMaterials(firmId, offering!.id, orderedIds),
    onSuccess: invalidate,
    onError: (e) => toast.error(extractError(e)),
  });
  const removeMutation = useMutation({
    mutationFn: (suggestionId: number) => deleteSuggestedMaterial(firmId, offering!.id, suggestionId),
    onSuccess: invalidate,
    onError: (e) => toast.error(extractError(e)),
  });

  if (!offering) return null;

  const suggestions = [...offering.suggestedMaterials].sort((a, b) => a.displayOrder - b.displayOrder);
  const suggestedIds = new Set(suggestions.map((s) => s.materialItem.id));
  const results = search.trim()
    ? firmMaterials.filter((m) => !suggestedIds.has(m.id) && m.label.toLowerCase().includes(search.trim().toLowerCase()))
    : [];

  function move(index: number, direction: -1 | 1) {
    const next = [...suggestions];
    const target = index + direction;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    reorderMutation.mutate(next.map((s) => s.id));
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Matériels suggérés — {offering.interventionType.label}</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          Accélère l'encodage — ne restreint jamais le matériel réellement utilisable.
        </Typography>

        <TextField
          fullWidth size="small" placeholder="Rechercher un matériel de cette firme…"
          value={search} onChange={(e) => setSearch(e.target.value)}
          sx={{ mb: 1.5 }}
        />
        {results.length > 0 && (
          <Paper variant="outlined" sx={{ mb: 2, maxHeight: 160, overflowY: "auto" }}>
            {results.map((m) => (
              <Box
                key={m.id}
                onClick={() => { addMutation.mutate(m.id); setSearch(""); }}
                sx={{ px: 1.5, py: 1, cursor: "pointer", "&:hover": { bgcolor: "grey.50" }, borderBottom: "1px solid", borderColor: "grey.100" }}
              >
                <Typography variant="body2">{m.label}</Typography>
                {m.referenceCode && <Typography variant="caption" color="text.secondary">{m.referenceCode}</Typography>}
              </Box>
            ))}
          </Paper>
        )}

        <Divider sx={{ my: 1.5 }} />

        {suggestions.length === 0 ? (
          <Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: "center" }}>
            Aucun matériel suggéré pour l'instant.
          </Typography>
        ) : (
          <Stack spacing={1}>
            {suggestions.map((s, index) => (
              <Stack key={s.id} direction="row" alignItems="center" spacing={1} sx={{ p: 1, border: "1px solid", borderColor: "grey.150", borderRadius: 1.5 }}>
                <Stack sx={{ flex: 1, minWidth: 0 }}>
                  <Typography variant="body2" noWrap>{s.materialItem.label}</Typography>
                  {!s.materialItem.active && <Chip label="Matériel désactivé" size="small" color="default" sx={{ fontSize: ".65rem", height: 18, alignSelf: "flex-start" }} />}
                </Stack>
                <IconButton size="small" disabled={index === 0} onClick={() => move(index, -1)}><ArrowUpwardIcon fontSize="small" /></IconButton>
                <IconButton size="small" disabled={index === suggestions.length - 1} onClick={() => move(index, 1)}><ArrowDownwardIcon fontSize="small" /></IconButton>
                <IconButton size="small" color="error" onClick={() => removeMutation.mutate(s.id)}><DeleteIcon fontSize="small" /></IconButton>
              </Stack>
            ))}
          </Stack>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} variant="contained" disableElevation>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Dialog : ajouter un tarif matériel ───────────────────────────────────────
function AddMaterialRuleDialog({
  open, onClose, firmId, firmMaterials, existingRules,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  firmMaterials: MaterialItemRow[];
  existingRules: PricingRule[];
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [materialItemId, setMaterialItemId] = React.useState<number | "">("");
  const [unitPrice, setUnitPrice] = React.useState("");

  React.useEffect(() => {
    if (open) { setMaterialItemId(""); setUnitPrice(""); }
  }, [open]);

  const isDuplicate = materialItemId !== "" && existingRules.some(
    (r) => r.ruleType === "MATERIAL_FEE" && r.materialItem?.id === materialItemId && r.active,
  );

  const createMutation = useMutation({
    mutationFn: () => createPricingRule(firmId, { ruleType: "MATERIAL_FEE", materialItemId: materialItemId as number, unitPrice: Number(unitPrice) }),
    onSuccess: () => {
      toast.success("Tarif ajouté");
      qc.invalidateQueries({ queryKey: ["pricing-rules", firmId] });
      onClose();
    },
    onError: (e) => toast.error(extractError(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Ajouter un tarif matériel</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <Autocomplete
            options={firmMaterials}
            getOptionLabel={(m) => m.label}
            onChange={(_, v) => setMaterialItemId(v ? v.id : "")}
            renderInput={(params) => <TextField {...params} label="Matériel *" size="small" />}
          />
          {isDuplicate && (
            <Alert severity="warning" sx={{ py: 0.5, fontSize: ".8rem" }}>
              Une règle active existe déjà pour ce matériel.
            </Alert>
          )}
          <TextField
            label="Montant (€) *" type="number" size="small" value={unitPrice}
            onChange={(e) => setUnitPrice(e.target.value)}
            inputProps={{ min: 0, step: "0.01" }}
          />
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} color="inherit">Annuler</Button>
        <Button
          variant="contained" disableElevation
          disabled={!materialItemId || !unitPrice || Number(unitPrice) < 0 || isDuplicate || createMutation.isPending}
          onClick={() => createMutation.mutate()}
        >
          {createMutation.isPending ? <CircularProgress size={16} /> : "Ajouter"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Page principale ───────────────────────────────────────────────────────────
export default function BillingConfigPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const [selectedFirmId, setSelectedFirmId] = React.useState<number | "">("");
  const [addOfferingOpen, setAddOfferingOpen] = React.useState(false);
  const [forfaitTarget, setForfaitTarget] = React.useState<{ interventionTypeId: number; rule: PricingRule | null } | null>(null);
  const [suggestionsTargetId, setSuggestionsTargetId] = React.useState<number | null>(null);
  const [addMaterialRuleOpen, setAddMaterialRuleOpen] = React.useState(false);

  const firmsQuery = useQuery({
    queryKey: ["firms"],
    queryFn: async () => (await apiClient.get("/api/firms")).data as { id: number; name: string }[],
  });

  const rulesQuery = useQuery({
    queryKey: ["pricing-rules", selectedFirmId],
    queryFn: () => getFirmPricingRules(selectedFirmId as number),
    enabled: !!selectedFirmId,
  });

  const offeringsQuery = useQuery({
    queryKey: ["service-offerings", selectedFirmId],
    queryFn: () => getFirmServiceOfferings(selectedFirmId as number),
    enabled: !!selectedFirmId,
  });

  const materialsQuery = useQuery({
    queryKey: ["material-items-firm", selectedFirmId],
    queryFn: async () => {
      const res = await apiClient.get("/api/material-items", { params: { firmId: selectedFirmId, limit: 200 } });
      return (res.data.items as { id: number; label: string; referenceCode: string | null }[]);
    },
    enabled: !!selectedFirmId,
  });

  const toggleOfferingMutation = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => updateFirmServiceOffering(selectedFirmId as number, id, { active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["service-offerings", selectedFirmId] }),
    onError: (e) => toast.error(extractError(e)),
  });
  const deleteRuleMutation = useMutation({
    mutationFn: (ruleId: number) => deletePricingRule(selectedFirmId as number, ruleId),
    onSuccess: () => { toast.success("Règle supprimée"); qc.invalidateQueries({ queryKey: ["pricing-rules", selectedFirmId] }); },
    onError: (e) => toast.error(extractError(e)),
  });

  const rules = rulesQuery.data ?? [];
  const offerings = offeringsQuery.data ?? [];
  const materialRules = rules.filter((r) => r.ruleType === "MATERIAL_FEE");
  const materials = materialsQuery.data ?? [];
  // Dérivé en direct de la liste rafraîchie plutôt qu'une copie figée au clic — sinon le
  // dialogue afficherait une liste de suggestions périmée après un ajout/suppression.
  const suggestionsTarget = offerings.find((o) => o.id === suggestionsTargetId) ?? null;

  function forfaitFor(interventionTypeId: number): PricingRule | null {
    return rules.find((r) => r.ruleType === "INTERVENTION_FEE" && r.interventionType?.id === interventionTypeId) ?? null;
  }

  return (
    <Stack spacing={3} sx={{ p: 3, maxWidth: 1100 }}>
      <Typography variant="h5" fontWeight={700}>Règles de facturation firmes</Typography>

      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" spacing={2} alignItems="center">
          <Typography variant="subtitle2" fontWeight={700} sx={{ minWidth: 48 }}>Firme</Typography>
          <Select
            value={selectedFirmId}
            onChange={(e) => setSelectedFirmId(Number(e.target.value))}
            displayEmpty size="small" sx={{ minWidth: 240 }}
          >
            <MenuItem value="" disabled>Sélectionner une firme…</MenuItem>
            {(firmsQuery.data ?? []).map((f) => <MenuItem key={f.id} value={f.id}>{f.name}</MenuItem>)}
          </Select>
        </Stack>
      </Paper>

      {selectedFirmId === "" ? (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2 }}>
          <Typography variant="h6" fontWeight={600} color="text.secondary">Sélectionnez une firme pour commencer</Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 420 }}>
            Prestations (accélérateurs de saisie) et tarifs contractuels restent deux
            choses indépendantes — le contact de facturation reste géré depuis la fiche Firme.
          </Typography>
        </Box>
      ) : (
        <>
          {/* ── Prestations ── */}
          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
            <Stack direction="row" alignItems="center" justifyContent="space-between" mb={2}>
              <Typography variant="subtitle1" fontWeight={700}>Prestations</Typography>
              <Button variant="outlined" size="small" startIcon={<AddIcon />} onClick={() => setAddOfferingOpen(true)} sx={{ borderRadius: 999, fontWeight: 600 }}>
                Ajouter une prestation
              </Button>
            </Stack>
            <Divider sx={{ mb: 2 }} />

            {offeringsQuery.isLoading ? (
              <CircularProgress size={20} />
            ) : offerings.length === 0 ? (
              <Typography color="text.secondary" variant="body2">Aucune prestation configurée pour cette firme.</Typography>
            ) : (
              <Stack spacing={1.5}>
                {offerings.map((o) => {
                  const forfait = forfaitFor(o.interventionType.id);
                  return (
                    <Paper key={o.id} variant="outlined" sx={{ p: 1.75, borderRadius: 2 }}>
                      <Stack direction="row" alignItems="center" spacing={1.5}>
                        <Stack sx={{ flex: 1, minWidth: 0 }}>
                          <Stack direction="row" alignItems="center" spacing={1}>
                            <Typography fontWeight={700} variant="body2">{o.interventionType.label}</Typography>
                            <Chip label={o.interventionType.code} size="small" variant="outlined" sx={{ fontFamily: "monospace", fontSize: ".68rem" }} />
                            <Chip
                              label={o.active ? "Active" : "Inactive"} size="small"
                              color={o.active ? "success" : "default"}
                              onClick={() => toggleOfferingMutation.mutate({ id: o.id, active: !o.active })}
                              sx={{ cursor: "pointer" }}
                            />
                          </Stack>
                          <Typography variant="caption" color="text.secondary">
                            {o.suggestedMaterials.length} matériel{o.suggestedMaterials.length !== 1 ? "s" : ""} suggéré{o.suggestedMaterials.length !== 1 ? "s" : ""}
                          </Typography>
                        </Stack>

                        <Button size="small" onClick={() => setSuggestionsTargetId(o.id)}>
                          Matériels suggérés
                        </Button>

                        {forfait ? (
                          <Chip
                            icon={<EditIcon sx={{ fontSize: 14 }} />}
                            label={`${Number(forfait.unitPrice).toFixed(2)} €`}
                            onClick={() => setForfaitTarget({ interventionTypeId: o.interventionType.id, rule: forfait })}
                            color="primary" variant="outlined" sx={{ cursor: "pointer", fontWeight: 700 }}
                          />
                        ) : (
                          <Button size="small" variant="outlined" onClick={() => setForfaitTarget({ interventionTypeId: o.interventionType.id, rule: null })}>
                            Définir un forfait
                          </Button>
                        )}
                      </Stack>
                    </Paper>
                  );
                })}
              </Stack>
            )}
          </Paper>

          {/* ── Matériel facturable ── */}
          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
            <Stack direction="row" alignItems="center" justifyContent="space-between" mb={2}>
              <Typography variant="subtitle1" fontWeight={700}>Matériel facturable</Typography>
              <Button variant="outlined" size="small" startIcon={<AddIcon />} onClick={() => setAddMaterialRuleOpen(true)} sx={{ borderRadius: 999, fontWeight: 600 }}>
                Ajouter un tarif matériel
              </Button>
            </Stack>
            <Divider sx={{ mb: 2 }} />

            {rulesQuery.isLoading ? (
              <CircularProgress size={20} />
            ) : materialRules.length === 0 ? (
              <Typography color="text.secondary" variant="body2">Aucun tarif matériel configuré pour cette firme.</Typography>
            ) : (
              <Table size="small">
                <TableHead>
                  <TableRow sx={{ bgcolor: "grey.50" }}>
                    <TableCell>Matériel</TableCell>
                    <TableCell align="right">Tarif</TableCell>
                    <TableCell>Validité</TableCell>
                    <TableCell>Statut</TableCell>
                    <TableCell align="right">Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {materialRules.map((rule) => (
                    <TableRow key={rule.id} hover>
                      <TableCell>
                        <Typography variant="body2">{rule.materialItem?.label}</Typography>
                        {rule.materialItem?.referenceCode && <Typography variant="caption" color="text.secondary">({rule.materialItem.referenceCode})</Typography>}
                      </TableCell>
                      <TableCell align="right"><Typography fontWeight={700}>{Number(rule.unitPrice).toFixed(2)} {rule.currency}</Typography></TableCell>
                      <TableCell>
                        <Typography variant="caption" color="text.secondary">
                          {rule.validFrom ?? "…"} → {rule.validTo ?? "…"}
                        </Typography>
                      </TableCell>
                      <TableCell>
                        <Chip label={rule.active ? "Actif" : "Inactif"} size="small" color={rule.active ? "success" : "default"} />
                      </TableCell>
                      <TableCell align="right">
                        <IconButton size="small" color="error" onClick={() => deleteRuleMutation.mutate(rule.id)} disabled={deleteRuleMutation.isPending}>
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Paper>
        </>
      )}

      {selectedFirmId !== "" && (
        <>
          <AddOfferingDialog
            open={addOfferingOpen}
            onClose={() => setAddOfferingOpen(false)}
            firmId={selectedFirmId as number}
            existingTypeIds={offerings.map((o) => o.interventionType.id)}
            onCreated={() => qc.invalidateQueries({ queryKey: ["service-offerings", selectedFirmId] })}
          />
          <ForfaitDialog
            open={forfaitTarget !== null}
            onClose={() => setForfaitTarget(null)}
            firmId={selectedFirmId as number}
            interventionTypeId={forfaitTarget?.interventionTypeId ?? 0}
            existingRule={forfaitTarget?.rule ?? null}
          />
          <SuggestedMaterialsDialog
            open={suggestionsTargetId !== null}
            onClose={() => setSuggestionsTargetId(null)}
            firmId={selectedFirmId as number}
            offering={suggestionsTarget}
            firmMaterials={materials}
          />
          <AddMaterialRuleDialog
            open={addMaterialRuleOpen}
            onClose={() => setAddMaterialRuleOpen(false)}
            firmId={selectedFirmId as number}
            firmMaterials={materials}
            existingRules={rules}
          />
        </>
      )}
    </Stack>
  );
}
