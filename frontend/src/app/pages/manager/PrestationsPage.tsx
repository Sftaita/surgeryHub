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
  Tab,
  Tabs,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import EditIcon from "@mui/icons-material/Edit";
import ArrowUpwardIcon from "@mui/icons-material/ArrowUpward";
import ArrowDownwardIcon from "@mui/icons-material/ArrowDownward";
import CategoryIcon from "@mui/icons-material/Category";
import MedicalServicesIcon from "@mui/icons-material/MedicalServices";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getFirmPricingRules,
  createPricingRule,
  updatePricingRule,
  deletePricingRule,
  replacePricingRule,
  getFirmServiceOfferings,
  createFirmServiceOffering,
  updateFirmServiceOffering,
  addSuggestedMaterial,
  reorderSuggestedMaterials,
  deleteSuggestedMaterial,
  type PricingRule,
  type FirmServiceOffering,
} from "../../features/billing-firm/api/firmBilling.api";
import RateVersionManager, { getActiveVersion } from "../../features/billing-shared/components/RateVersionManager";
import {
  getInterventionTypes,
  createInterventionType,
} from "../../features/intervention-types/api/interventionTypes.api";
import { InterventionTypesManager } from "../../features/intervention-types/components/InterventionTypesManager";
import { getFirms, getMaterialItems, createMaterialItem } from "../../features/manager-catalogue/api/catalogue.api";
import type { FirmDTO } from "../../features/manager-catalogue/api/catalogue.types";
import { MaterialItemFormDialog } from "../../features/manager-catalogue/components/MaterialItemFormDialog";
import { useToast } from "../../ui/toast/useToast";
import { PageHeader } from "../../ui/PageHeader";
import { SideList } from "../../ui/SideList";
import { EmptyState } from "../../ui/EmptyState";
import { useDebouncedValue } from "../../ui/hooks/useDebouncedValue";

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

// ── Dialog : forfait d'intervention (historique de tarifs versionné) ────────
function ForfaitDialog({
  open, onClose, firmId, interventionTypeId, rules,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  interventionTypeId: number;
  rules: PricingRule[];
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const invalidate = () => qc.invalidateQueries({ queryKey: ["pricing-rules", firmId] });

  const createMutation = useMutation({
    mutationFn: (body: { amount: number; currency: string; validFrom: string | null; validTo: string | null }) =>
      createPricingRule(firmId, {
        ruleType: "INTERVENTION_FEE", interventionTypeId,
        unitPrice: body.amount, currency: body.currency, validFrom: body.validFrom, validTo: body.validTo,
      }),
    onSuccess: () => { toast.success("Tarif créé"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const replaceMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount: number; currency: string; effectiveFrom: string } }) =>
      replacePricingRule(firmId, id, { unitPrice: body.amount, currency: body.currency, effectiveFrom: body.effectiveFrom }),
    onSuccess: () => { toast.success("Tarif remplacé à partir de la date choisie"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const editMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount?: number; currency?: string; validFrom?: string | null; validTo?: string | null } }) =>
      updatePricingRule(firmId, id, { unitPrice: body.amount, currency: body.currency, validFrom: body.validFrom, validTo: body.validTo }),
    onSuccess: () => { toast.success("Tarif programmé modifié"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const cancelMutation = useMutation({
    mutationFn: (id: number) => deletePricingRule(firmId, id),
    onSuccess: () => { toast.success("Tarif programmé annulé"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });

  const isSaving = createMutation.isPending || replaceMutation.isPending || editMutation.isPending || cancelMutation.isPending;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Forfait d'intervention</DialogTitle>
      <DialogContent>
        <Box sx={{ pt: 1 }}>
          <RateVersionManager
            versions={rules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo }))}
            onCreateFirst={(b) => createMutation.mutate(b)}
            onReplaceActive={(id, b) => replaceMutation.mutate({ id, body: b })}
            onEditFuture={(id, b) => editMutation.mutate({ id, body: b })}
            onCancelFuture={(id) => cancelMutation.mutate(id)}
            isSaving={isSaving}
          />
        </Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} variant="contained" disableElevation>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Dialog : tarif matériel (historique de tarifs versionné) ────────────────
function MaterialRateDialog({
  open, onClose, firmId, materialItemId, materialLabel, rules,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  materialItemId: number;
  materialLabel: string;
  rules: PricingRule[];
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const invalidate = () => qc.invalidateQueries({ queryKey: ["pricing-rules", firmId] });

  const createMutation = useMutation({
    mutationFn: (body: { amount: number; currency: string; validFrom: string | null; validTo: string | null }) =>
      createPricingRule(firmId, {
        ruleType: "MATERIAL_FEE", materialItemId,
        unitPrice: body.amount, currency: body.currency, validFrom: body.validFrom, validTo: body.validTo,
      }),
    onSuccess: () => { toast.success("Tarif créé"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const replaceMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount: number; currency: string; effectiveFrom: string } }) =>
      replacePricingRule(firmId, id, { unitPrice: body.amount, currency: body.currency, effectiveFrom: body.effectiveFrom }),
    onSuccess: () => { toast.success("Tarif remplacé à partir de la date choisie"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const editMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount?: number; currency?: string; validFrom?: string | null; validTo?: string | null } }) =>
      updatePricingRule(firmId, id, { unitPrice: body.amount, currency: body.currency, validFrom: body.validFrom, validTo: body.validTo }),
    onSuccess: () => { toast.success("Tarif programmé modifié"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const cancelMutation = useMutation({
    mutationFn: (id: number) => deletePricingRule(firmId, id),
    onSuccess: () => { toast.success("Tarif programmé annulé"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });

  const isSaving = createMutation.isPending || replaceMutation.isPending || editMutation.isPending || cancelMutation.isPending;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Tarifs — {materialLabel}</DialogTitle>
      <DialogContent>
        <Box sx={{ pt: 1 }}>
          <RateVersionManager
            versions={rules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo }))}
            onCreateFirst={(b) => createMutation.mutate(b)}
            onReplaceActive={(id, b) => replaceMutation.mutate({ id, body: b })}
            onEditFuture={(id, b) => editMutation.mutate({ id, body: b })}
            onCancelFuture={(id) => cancelMutation.mutate(id)}
            isSaving={isSaving}
          />
        </Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} variant="contained" disableElevation>Fermer</Button>
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

// ── Dialog : gérer les types d'intervention (embarque InterventionTypesManager) ──
function InterventionTypesDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Types d'intervention</DialogTitle>
      <DialogContent>
        <Box sx={{ pt: 1 }}>
          <InterventionTypesManager showHeader={false} />
        </Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} variant="contained" disableElevation>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Page principale ───────────────────────────────────────────────────────────
export default function PrestationsPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const [selectedFirmId, setSelectedFirmId] = React.useState<number | null>(null);
  const [firmSearch, setFirmSearch] = React.useState("");
  const debouncedFirmSearch = useDebouncedValue(firmSearch, 300);
  const [tab, setTab] = React.useState<"offerings" | "materials">("offerings");

  const [addOfferingOpen, setAddOfferingOpen] = React.useState(false);
  const [forfaitTarget, setForfaitTarget] = React.useState<number | null>(null);
  const [suggestionsTargetId, setSuggestionsTargetId] = React.useState<number | null>(null);
  const [addMaterialRuleOpen, setAddMaterialRuleOpen] = React.useState(false);
  const [materialRateTarget, setMaterialRateTarget] = React.useState<{ id: number; label: string } | null>(null);
  const [interventionTypesOpen, setInterventionTypesOpen] = React.useState(false);
  const [createMaterialOpen, setCreateMaterialOpen] = React.useState(false);
  const [createMaterialError, setCreateMaterialError] = React.useState<string | null>(null);

  const firmsQuery = useQuery({ queryKey: ["firms"], queryFn: getFirms });
  const firms: FirmDTO[] = firmsQuery.data ?? [];
  const filteredFirms = debouncedFirmSearch.trim()
    ? firms.filter((f) => f.name.toLowerCase().includes(debouncedFirmSearch.trim().toLowerCase()))
    : firms;

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
    queryFn: () => getMaterialItems({ firmId: selectedFirmId as number, limit: 200 }),
    enabled: !!selectedFirmId,
  });

  const toggleOfferingMutation = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => updateFirmServiceOffering(selectedFirmId as number, id, { active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["service-offerings", selectedFirmId] }),
    onError: (e) => toast.error(extractError(e)),
  });

  const createMaterialMutation = useMutation({
    mutationFn: createMaterialItem,
    onSuccess: () => {
      toast.success("Article créé");
      qc.invalidateQueries({ queryKey: ["material-items-firm", selectedFirmId] });
      qc.invalidateQueries({ queryKey: ["material-items"] });
      setCreateMaterialOpen(false);
      setCreateMaterialError(null);
    },
    onError: (e: any) => setCreateMaterialError(e?.response?.data?.message ?? extractError(e)),
  });

  const rules = rulesQuery.data ?? [];
  const offerings = offeringsQuery.data ?? [];
  const materials: MaterialItemRow[] = materialsQuery.data?.items ?? [];
  // Dérivé en direct de la liste rafraîchie plutôt qu'une copie figée au clic — sinon le
  // dialogue afficherait une liste de suggestions périmée après un ajout/suppression.
  const suggestionsTarget = offerings.find((o) => o.id === suggestionsTargetId) ?? null;

  function rulesForIntervention(interventionTypeId: number): PricingRule[] {
    return rules.filter((r) => r.ruleType === "INTERVENTION_FEE" && r.interventionType?.id === interventionTypeId);
  }
  function forfaitFor(interventionTypeId: number): PricingRule | null {
    const lineage = rulesForIntervention(interventionTypeId);
    const activeVersion = getActiveVersion(lineage.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo })));
    return activeVersion ? lineage.find((r) => r.id === activeVersion.id) ?? null : null;
  }

  // Un matériel peut avoir plusieurs PricingRule au fil du temps (append-only, D-072) —
  // regroupées par matériel pour n'afficher qu'une ligne par matériel dans le tableau.
  type MaterialGroup = { materialItemId: number; label: string; referenceCode: string | null; rules: PricingRule[] };
  const materialGroups: MaterialGroup[] = React.useMemo(() => {
    const map = new Map<number, MaterialGroup>();
    for (const r of rules) {
      if (r.ruleType !== "MATERIAL_FEE" || !r.materialItem) continue;
      const key = r.materialItem.id;
      if (!map.has(key)) {
        map.set(key, { materialItemId: key, label: r.materialItem.label, referenceCode: r.materialItem.referenceCode, rules: [] });
      }
      map.get(key)!.rules.push(r);
    }
    return Array.from(map.values());
  }, [rules]);

  return (
    <Box sx={{ p: 3 }}>
      <PageHeader
        icon={CategoryIcon}
        title="Prestations"
        subtitle="Prestations (accélérateurs de saisie) et tarifs contractuels par firme."
        helpTopicId="billing-config"
        actions={
          <Button variant="outlined" startIcon={<MedicalServicesIcon />} onClick={() => setInterventionTypesOpen(true)}>
            Gérer les types d'intervention
          </Button>
        }
      />

      <Paper variant="outlined" sx={{ borderRadius: 2, display: "flex", minHeight: 480, overflow: "hidden" }}>
        <SideList
          items={filteredFirms.map((f) => ({ id: f.id, label: f.name }))}
          selectedId={selectedFirmId}
          onSelect={(id) => setSelectedFirmId(Number(id))}
          searchValue={firmSearch}
          onSearchChange={setFirmSearch}
          countLabel="Firmes"
          searchPlaceholder="Rechercher une firme…"
          emptyMessage="Aucune firme."
        />

        <Box sx={{ flex: 1, minWidth: 0, p: 2.5 }}>
          {selectedFirmId === null ? (
            <EmptyState
              title="Sélectionnez une firme pour commencer"
              description="Prestations (accélérateurs de saisie) et tarifs contractuels restent deux choses indépendantes — le contact de facturation reste géré depuis la fiche Firme."
            />
          ) : (
            <>
              <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ mb: 2, minHeight: 36 }}>
                <Tab value="offerings" label="Prestations" sx={{ minHeight: 36 }} />
                <Tab value="materials" label="Matériel facturable" sx={{ minHeight: 36 }} />
              </Tabs>

              {tab === "offerings" && (
                <Box>
                  <Stack direction="row" alignItems="center" justifyContent="flex-end" mb={2}>
                    <Button variant="outlined" size="small" startIcon={<AddIcon />} onClick={() => setAddOfferingOpen(true)} sx={{ borderRadius: 999, fontWeight: 600 }}>
                      Ajouter une prestation
                    </Button>
                  </Stack>

                  {offeringsQuery.isLoading ? (
                    <CircularProgress size={20} />
                  ) : offerings.length === 0 ? (
                    <EmptyState variant="dashed" title="Aucune prestation configurée pour cette firme." />
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
                                  onClick={() => setForfaitTarget(o.interventionType.id)}
                                  color="primary" variant="outlined" sx={{ cursor: "pointer", fontWeight: 700 }}
                                />
                              ) : (
                                <Button size="small" variant="outlined" onClick={() => setForfaitTarget(o.interventionType.id)}>
                                  Définir un forfait
                                </Button>
                              )}
                            </Stack>
                          </Paper>
                        );
                      })}
                    </Stack>
                  )}
                </Box>
              )}

              {tab === "materials" && (
                <Box>
                  <Stack direction="row" alignItems="center" justifyContent="flex-end" spacing={1} mb={2}>
                    <Button variant="outlined" size="small" startIcon={<AddIcon />} onClick={() => setCreateMaterialOpen(true)} sx={{ borderRadius: 999, fontWeight: 600 }}>
                      Créer un article
                    </Button>
                    <Button variant="outlined" size="small" startIcon={<AddIcon />} onClick={() => setAddMaterialRuleOpen(true)} sx={{ borderRadius: 999, fontWeight: 600 }}>
                      Ajouter un tarif matériel
                    </Button>
                  </Stack>

                  {rulesQuery.isLoading ? (
                    <CircularProgress size={20} />
                  ) : materialGroups.length === 0 ? (
                    <EmptyState variant="dashed" title="Aucun tarif matériel configuré pour cette firme." />
                  ) : (
                    <Table size="small">
                      <TableHead>
                        <TableRow sx={{ bgcolor: "grey.50" }}>
                          <TableCell>Matériel</TableCell>
                          <TableCell align="right">Tarif en vigueur</TableCell>
                          <TableCell>Historique</TableCell>
                          <TableCell align="right">Actions</TableCell>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {materialGroups.map((g) => {
                          const activeVersion = getActiveVersion(g.rules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo })));
                          return (
                            <TableRow key={g.materialItemId} hover>
                              <TableCell>
                                <Typography variant="body2">{g.label}</Typography>
                                {g.referenceCode && <Typography variant="caption" color="text.secondary">({g.referenceCode})</Typography>}
                              </TableCell>
                              <TableCell align="right">
                                {activeVersion ? (
                                  <Typography fontWeight={700}>{Number(activeVersion.amount).toFixed(2)} {activeVersion.currency}</Typography>
                                ) : (
                                  <Chip label="Aucun tarif actif" size="small" color="default" />
                                )}
                              </TableCell>
                              <TableCell>
                                <Typography variant="caption" color="text.secondary">
                                  {g.rules.length} version{g.rules.length > 1 ? "s" : ""}
                                </Typography>
                              </TableCell>
                              <TableCell align="right">
                                <Button size="small" onClick={() => setMaterialRateTarget({ id: g.materialItemId, label: g.label })}>
                                  Gérer les tarifs
                                </Button>
                              </TableCell>
                            </TableRow>
                          );
                        })}
                      </TableBody>
                    </Table>
                  )}
                </Box>
              )}
            </>
          )}
        </Box>
      </Paper>

      <InterventionTypesDialog open={interventionTypesOpen} onClose={() => setInterventionTypesOpen(false)} />

      {selectedFirmId !== null && (
        <>
          <AddOfferingDialog
            open={addOfferingOpen}
            onClose={() => setAddOfferingOpen(false)}
            firmId={selectedFirmId}
            existingTypeIds={offerings.map((o) => o.interventionType.id)}
            onCreated={() => qc.invalidateQueries({ queryKey: ["service-offerings", selectedFirmId] })}
          />
          <ForfaitDialog
            open={forfaitTarget !== null}
            onClose={() => setForfaitTarget(null)}
            firmId={selectedFirmId}
            interventionTypeId={forfaitTarget ?? 0}
            rules={forfaitTarget !== null ? rulesForIntervention(forfaitTarget) : []}
          />
          <SuggestedMaterialsDialog
            open={suggestionsTargetId !== null}
            onClose={() => setSuggestionsTargetId(null)}
            firmId={selectedFirmId}
            offering={suggestionsTarget}
            firmMaterials={materials}
          />
          <AddMaterialRuleDialog
            open={addMaterialRuleOpen}
            onClose={() => setAddMaterialRuleOpen(false)}
            firmId={selectedFirmId}
            firmMaterials={materials}
            existingRules={rules}
          />
          <MaterialRateDialog
            open={materialRateTarget !== null}
            onClose={() => setMaterialRateTarget(null)}
            firmId={selectedFirmId}
            materialItemId={materialRateTarget?.id ?? 0}
            materialLabel={materialRateTarget?.label ?? ""}
            rules={materialRateTarget ? materialGroups.find((g) => g.materialItemId === materialRateTarget.id)?.rules ?? [] : []}
          />
          <MaterialItemFormDialog
            open={createMaterialOpen}
            title="Créer un article"
            initial={{ firmId: selectedFirmId }}
            submitLabel="Créer"
            loading={createMaterialMutation.isPending}
            error={createMaterialError}
            onClose={() => { setCreateMaterialOpen(false); setCreateMaterialError(null); }}
            onSubmit={(values) => {
              if (!values.firmId) return;
              createMaterialMutation.mutate({
                firmId: values.firmId,
                label: values.label,
                unit: values.unit,
                referenceCode: values.referenceCode || undefined,
                isImplant: values.isImplant,
              });
            }}
          />
        </>
      )}
    </Box>
  );
}
