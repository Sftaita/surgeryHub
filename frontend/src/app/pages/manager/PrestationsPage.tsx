import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControlLabel,
  IconButton,
  Paper,
  Radio,
  RadioGroup,
  Stack,
  Tab,
  Tabs,
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
import EditIcon from "@mui/icons-material/Edit";
import DeleteIcon from "@mui/icons-material/Delete";
import ArrowUpwardIcon from "@mui/icons-material/ArrowUpward";
import ArrowDownwardIcon from "@mui/icons-material/ArrowDownward";
import CategoryIcon from "@mui/icons-material/Category";
import MedicalServicesIcon from "@mui/icons-material/MedicalServices";
import Inventory2OutlinedIcon from "@mui/icons-material/Inventory2Outlined";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import BadgeOutlinedIcon from "@mui/icons-material/BadgeOutlined";
import BusinessIcon from "@mui/icons-material/Business";
import MenuBookIcon from "@mui/icons-material/MenuBook";
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
  findSimilarInterventionTypes,
  type InterventionType,
  type SimilarInterventionTypeCandidate,
} from "../../features/intervention-types/api/interventionTypes.api";
import { InterventionTypesManager } from "../../features/intervention-types/components/InterventionTypesManager";
import { InterventionTypeDetailDialog } from "../../features/intervention-types/components/InterventionTypeDetailDialog";
import { getFirms, getMaterialItems, createMaterialItem, updateMaterialItem } from "../../features/manager-catalogue/api/catalogue.api";
import type { FirmDTO, MaterialItemDTO } from "../../features/manager-catalogue/api/catalogue.types";
import { MaterialItemFormDialog } from "../../features/manager-catalogue/components/MaterialItemFormDialog";
import { FirmAvatar } from "../../features/manager-catalogue/components/FirmAvatar";
import { FirmLogoDialog } from "../../features/manager-catalogue/components/FirmLogoDialog";
import { resolveApiAssetUrl } from "../../api/apiAssetUrl";
import { useToast } from "../../ui/toast/useToast";
import { PageHeader } from "../../ui/PageHeader";
import { SideList } from "../../ui/SideList";
import { EmptyState } from "../../ui/EmptyState";
import { useDebouncedValue } from "../../ui/hooks/useDebouncedValue";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.response?.data?.message ?? e?.message ?? String(err);
}

// ── Dialog : ajouter une prestation — recherche + création dans le même flux ───
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
  const [search, setSearch] = React.useState("");
  const [selectedTypeId, setSelectedTypeId] = React.useState<number | null>(null);
  const [creatingNewType, setCreatingNewType] = React.useState(false);
  const [newCode, setNewCode] = React.useState("");
  const [newLabel, setNewLabel] = React.useState("");
  const [similarCandidates, setSimilarCandidates] = React.useState<SimilarInterventionTypeCandidate[] | null>(null);
  const [checkingSimilar, setCheckingSimilar] = React.useState(false);

  React.useEffect(() => {
    if (open) {
      setSearch("");
      setSelectedTypeId(null);
      setCreatingNewType(false);
      setNewCode("");
      setNewLabel("");
      setSimilarCandidates(null);
    }
  }, [open]);

  const typesQuery = useQuery({ queryKey: ["intervention-types", "active"], queryFn: () => getInterventionTypes(true), enabled: open });
  const availableTypes = (typesQuery.data ?? []).filter((t) => !existingTypeIds.includes(t.id));
  const query = search.trim().toLowerCase();
  const results = query
    ? availableTypes.filter((t) => t.label.toLowerCase().includes(query) || t.code.toLowerCase().includes(query)).slice(0, 8)
    : [];
  const selectedType = selectedTypeId !== null ? availableTypes.find((t) => t.id === selectedTypeId) ?? null : null;

  const createOfferingMutation = useMutation({
    mutationFn: (interventionTypeId: number) => createFirmServiceOffering(firmId, { interventionTypeId }),
    onSuccess: () => { toast.success("Prestation créée"); onCreated(); onClose(); },
    onError: (e) => toast.error(extractError(e)),
  });

  // "Créer et ajouter" fait les deux étapes en un seul geste (§5) — jamais un second
  // clic requis pour rattacher le type fraîchement créé à cette firme.
  const createTypeMutation = useMutation({
    mutationFn: createInterventionType,
    onSuccess: (created) => {
      qc.invalidateQueries({ queryKey: ["intervention-types"] });
      setSelectedTypeId(created.id);
      setCreatingNewType(false);
      createOfferingMutation.mutate(created.id);
    },
    onError: (e) => toast.error(extractError(e)),
  });

  function startCreatingFromSearch() {
    setNewLabel(search.trim());
    setNewCode("");
    setCreatingNewType(true);
  }

  // Task 11, section 6 — "Un type similaire existe déjà : X. Voulez-vous l'utiliser ?"
  // Suggestion avant création, jamais un blocage : le manager choisit "Créer quand même"
  // si l'intervention est réellement distincte.
  async function handleCreateType() {
    setCheckingSimilar(true);
    try {
      const candidates = (await findSimilarInterventionTypes(newLabel.trim()))
        .filter((c) => !existingTypeIds.includes(c.type.id));
      if (candidates.length > 0) {
        setSimilarCandidates(candidates);
        return;
      }
    } finally {
      setCheckingSimilar(false);
    }
    createTypeMutation.mutate({ code: newCode.trim(), label: newLabel.trim() });
  }

  function useExistingCandidate(candidateId: number) {
    setSimilarCandidates(null);
    setCreatingNewType(false);
    setSelectedTypeId(candidateId);
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Ajouter une prestation</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {similarCandidates ? (
            <>
              <Typography variant="body2" color="text.secondary">
                {similarCandidates.length > 1 ? "Des types proches existent déjà :" : "Un type proche existe déjà :"}
              </Typography>
              {similarCandidates.map((c) => (
                <Paper key={c.type.id} variant="outlined" sx={{ p: 1.5, borderRadius: 2, display: "flex", alignItems: "center", gap: 1 }}>
                  <Box sx={{ flex: 1 }}>
                    <Typography fontWeight={700} variant="body2">{c.type.label}</Typography>
                    <Chip label={c.type.code} size="small" variant="outlined" sx={{ fontFamily: "monospace", fontSize: ".68rem", mt: 0.5 }} />
                  </Box>
                  <Button size="small" variant="contained" disableElevation onClick={() => useExistingCandidate(c.type.id)}>
                    Utiliser ce type
                  </Button>
                </Paper>
              ))}
              <Stack direction="row" spacing={1}>
                <Button size="small" onClick={() => setSimilarCandidates(null)}>Retour</Button>
                <Button
                  size="small" variant="outlined" disableElevation disabled={createTypeMutation.isPending}
                  onClick={() => { setSimilarCandidates(null); createTypeMutation.mutate({ code: newCode.trim(), label: newLabel.trim() }); }}
                >
                  {createTypeMutation.isPending ? <CircularProgress size={14} /> : "Créer quand même"}
                </Button>
              </Stack>
            </>
          ) : !creatingNewType ? (
            <>
              {selectedType ? (
                <Paper variant="outlined" sx={{ p: 1.5, borderRadius: 2, display: "flex", alignItems: "center", gap: 1 }}>
                  <Typography sx={{ flex: 1, fontWeight: 700 }}>{selectedType.label}</Typography>
                  <Chip label={selectedType.code} size="small" variant="outlined" sx={{ fontFamily: "monospace" }} />
                  <Button size="small" onClick={() => setSelectedTypeId(null)}>Changer</Button>
                </Paper>
              ) : (
                <>
                  <TextField
                    fullWidth size="small" autoFocus
                    label="Type d'intervention"
                    placeholder="Rechercher une intervention…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                  />
                  {typesQuery.isLoading && <CircularProgress size={18} sx={{ alignSelf: "center" }} />}
                  {query && !typesQuery.isLoading && (
                    <Paper variant="outlined" sx={{ maxHeight: 220, overflowY: "auto" }}>
                      {results.map((t) => (
                        <Box
                          key={t.id}
                          onClick={() => { setSelectedTypeId(t.id); setSearch(""); }}
                          sx={{ px: 1.5, py: 1, cursor: "pointer", display: "flex", alignItems: "center", gap: 1, "&:hover": { bgcolor: "grey.50" }, borderBottom: "1px solid", borderColor: "grey.100" }}
                        >
                          <Typography variant="body2" sx={{ flex: 1 }}>{t.label}</Typography>
                          <Chip label={t.code} size="small" variant="outlined" sx={{ fontFamily: "monospace", fontSize: ".68rem" }} />
                        </Box>
                      ))}
                      <Box
                        onClick={startCreatingFromSearch}
                        sx={{ px: 1.5, py: 1, cursor: "pointer", color: "primary.main", fontWeight: 600, fontSize: ".875rem", "&:hover": { bgcolor: "grey.50" } }}
                      >
                        + Créer «{search.trim()}»
                      </Box>
                    </Paper>
                  )}
                </>
              )}
            </>
          ) : (
            <>
              <TextField
                label="Code *" size="small" value={newCode} autoFocus
                onChange={(e) => setNewCode(e.target.value.toUpperCase())}
                inputProps={{ style: { fontFamily: "monospace", fontWeight: 700 } }}
                placeholder="Ex : LCA-PRIMAIRE"
              />
              <TextField label="Libellé *" size="small" value={newLabel} onChange={(e) => setNewLabel(e.target.value)} placeholder="Ex : LCA primaire" />
              <Stack direction="row" spacing={1}>
                <Button size="small" onClick={() => setCreatingNewType(false)}>Retour</Button>
                <Button
                  size="small" variant="contained" disableElevation
                  disabled={!newCode.trim() || !newLabel.trim() || createTypeMutation.isPending || checkingSimilar}
                  onClick={handleCreateType}
                >
                  {createTypeMutation.isPending || checkingSimilar ? <CircularProgress size={14} /> : "Créer et ajouter"}
                </Button>
              </Stack>
            </>
          )}
        </Stack>
      </DialogContent>
      {!creatingNewType && (
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={onClose} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            disabled={!selectedTypeId || createOfferingMutation.isPending}
            onClick={() => createOfferingMutation.mutate(selectedTypeId as number)}
          >
            {createOfferingMutation.isPending ? <CircularProgress size={16} /> : "Créer"}
          </Button>
        </DialogActions>
      )}
    </Dialog>
  );
}

// ── Dialog : forfait d'intervention (historique de tarifs versionné + existence) ───
function ForfaitDialog({
  open, onClose, firmId, offering, rules,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  offering: FirmServiceOffering | null;
  rules: PricingRule[];
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const invalidateRules = () => qc.invalidateQueries({ queryKey: ["pricing-rules", firmId] });
  const invalidateOfferings = () => qc.invalidateQueries({ queryKey: ["service-offerings", firmId] });

  const createMutation = useMutation({
    mutationFn: (body: { amount: number; currency: string; validFrom: string | null; validTo: string | null }) =>
      createPricingRule(firmId, {
        ruleType: "INTERVENTION_FEE", interventionTypeId: offering?.interventionType.id as number,
        unitPrice: body.amount, currency: body.currency, validFrom: body.validFrom, validTo: body.validTo,
      }),
    onSuccess: () => { toast.success("Tarif créé"); invalidateRules(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const replaceMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount: number; currency: string; effectiveFrom: string } }) =>
      replacePricingRule(firmId, id, { unitPrice: body.amount, currency: body.currency, effectiveFrom: body.effectiveFrom }),
    onSuccess: () => { toast.success("Tarif remplacé à partir de la date choisie"); invalidateRules(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const editMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount?: number; currency?: string; validFrom?: string | null; validTo?: string | null } }) =>
      updatePricingRule(firmId, id, { unitPrice: body.amount, currency: body.currency, validFrom: body.validFrom, validTo: body.validTo }),
    onSuccess: () => { toast.success("Tarif programmé modifié"); invalidateRules(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const cancelMutation = useMutation({
    mutationFn: (id: number) => deletePricingRule(firmId, id),
    onSuccess: () => { toast.success("Tarif programmé annulé"); invalidateRules(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const feeApplicableMutation = useMutation({
    mutationFn: (feeApplicable: boolean) => updateFirmServiceOffering(firmId, offering!.id, { feeApplicable }),
    onSuccess: () => invalidateOfferings(),
    onError: (e) => toast.error(extractError(e)),
  });

  const isSaving = createMutation.isPending || replaceMutation.isPending || editMutation.isPending || cancelMutation.isPending;

  if (!offering) return null;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Forfait — {offering.interventionType.label}</DialogTitle>
      <DialogContent>
        <Box sx={{ pt: 1 }}>
          <RadioGroup
            value={offering.feeApplicable ? "applicable" : "none"}
            onChange={(e) => feeApplicableMutation.mutate(e.target.value === "applicable")}
          >
            <FormControlLabel value="none" control={<Radio size="small" />} disabled={feeApplicableMutation.isPending} label="Pas de forfait prévu pour cette prestation" />
            <FormControlLabel value="applicable" control={<Radio size="small" />} disabled={feeApplicableMutation.isPending} label="Forfait facturable" />
          </RadioGroup>

          {offering.feeApplicable ? (
            <Box sx={{ mt: 2 }}>
              <RateVersionManager
                versions={rules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo }))}
                onCreateFirst={(b) => createMutation.mutate(b)}
                onReplaceActive={(id, b) => replaceMutation.mutate({ id, body: b })}
                onEditFuture={(id, b) => editMutation.mutate({ id, body: b })}
                onCancelFuture={(id) => cancelMutation.mutate(id)}
                isSaving={isSaving}
              />
            </Box>
          ) : (
            <Alert severity="info" variant="outlined" sx={{ mt: 2 }}>
              Aucun forfait ne sera facturé pour cette prestation — décision volontaire, distincte d'un tarif manquant.
            </Alert>
          )}
        </Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} variant="contained" disableElevation>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Dialog : édition complète d'un matériel (identification + tarification) ─
// Point 10 (audit tarification) — remplace les 3 actions séparées de la ligne
// (Définir un tarif / Ajouter un tarif / Non facturable) par un seul point d'entrée
// « Modifier » couvrant à la fois l'identification (nom, référence — corriger une
// référence erronée après coup) et la tarification (RateVersionManager, réutilise
// PricingRule/D-072 tel quel, aucun nouveau modèle financier).
function MaterialEditDialog({
  open, onClose, firmId, material, rules,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  material: MaterialItemDTO | null;
  rules: PricingRule[];
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [label, setLabel] = React.useState("");
  const [referenceCode, setReferenceCode] = React.useState("");
  const [unit, setUnit] = React.useState("");
  const [isImplant, setIsImplant] = React.useState(false);
  const [identificationError, setIdentificationError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (open && material) {
      setLabel(material.label);
      setReferenceCode(material.referenceCode);
      setUnit(material.unit);
      setIsImplant(material.isImplant);
      setIdentificationError(null);
    }
  }, [open, material]);

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["pricing-rules", firmId] });
    qc.invalidateQueries({ queryKey: ["material-items-firm", firmId] });
    qc.invalidateQueries({ queryKey: ["material-items"] });
  };

  const identificationMutation = useMutation({
    mutationFn: (body: { label: string; unit: string; referenceCode?: string; isImplant: boolean }) =>
      updateMaterialItem(material!.id, body),
    onSuccess: () => { toast.success("Matériel mis à jour"); invalidate(); },
    onError: (e) => setIdentificationError(extractError(e)),
  });

  const createMutation = useMutation({
    mutationFn: (body: { amount: number; currency: string; validFrom: string | null; validTo: string | null }) =>
      createPricingRule(firmId, {
        ruleType: "MATERIAL_FEE", materialItemId: material!.id,
        unitPrice: body.amount, currency: body.currency, validFrom: body.validFrom, validTo: body.validTo,
      }),
    onSuccess: () => { toast.success("Tarif créé — matériel désormais facturable"); invalidate(); },
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
  const notBillableMutation = useMutation({
    mutationFn: () => updateMaterialItem(material!.id, { billingStatus: "NOT_BILLABLE" }),
    onSuccess: () => { toast.success("Matériel marqué non facturable"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });

  if (!material) return null;

  const isSaving = createMutation.isPending || replaceMutation.isPending || editMutation.isPending || cancelMutation.isPending;
  const hasActiveRate = !!getActiveVersion(rules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo })));

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Modifier le matériel</DialogTitle>
      <DialogContent>
        <Stack spacing={2.5} sx={{ pt: 1 }}>
          <Box>
            <Typography variant="overline" color="text.secondary">Identification</Typography>
            {identificationError && <Alert severity="error" sx={{ mb: 1.5 }}>{identificationError}</Alert>}
            <Stack spacing={1.5}>
              <TextField label="Nom *" size="small" fullWidth value={label} onChange={(e) => setLabel(e.target.value)} />
              <TextField label="Référence" size="small" fullWidth value={referenceCode} onChange={(e) => setReferenceCode(e.target.value)} helperText="Corrige une référence erronée après création" />
              <TextField label="Unité *" size="small" fullWidth value={unit} onChange={(e) => setUnit(e.target.value)} />
              <FormControlLabel
                control={<Checkbox checked={isImplant} onChange={(e) => setIsImplant(e.target.checked)} />}
                label="Implant"
              />
              <Button
                size="small" variant="outlined" sx={{ alignSelf: "flex-start" }}
                disabled={identificationMutation.isPending || !label.trim() || !unit.trim()}
                onClick={() => identificationMutation.mutate({ label: label.trim(), unit: unit.trim(), referenceCode: referenceCode.trim() || undefined, isImplant })}
              >
                Enregistrer l'identification
              </Button>
            </Stack>
          </Box>

          <Divider />

          <Box>
            <Typography variant="overline" color="text.secondary">Tarification</Typography>
            <Box sx={{ mt: 1 }}>
              <RateVersionManager
                versions={rules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo }))}
                onCreateFirst={(b) => createMutation.mutate(b)}
                onReplaceActive={(id, b) => replaceMutation.mutate({ id, body: b })}
                onEditFuture={(id, b) => editMutation.mutate({ id, body: b })}
                onCancelFuture={(id) => cancelMutation.mutate(id)}
                isSaving={isSaving}
              />
              {!hasActiveRate && material.billingStatus !== "NOT_BILLABLE" && (
                <Button
                  size="small" color="inherit" sx={{ mt: 1 }}
                  onClick={() => notBillableMutation.mutate()}
                  disabled={notBillableMutation.isPending}
                >
                  Marquer non facturable
                </Button>
              )}
            </Box>
          </Box>
        </Stack>
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
  firmMaterials: MaterialItemDTO[];
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

// Refonte UX (§5) — trois états jamais confondus : montant défini, "Tarif à définir"
// (feeApplicable=true sans PricingRule active), "Pas de forfait" (feeApplicable=false,
// décision volontaire). Tous les montants sont HTVA — clarification d'unité uniquement,
// aucun calcul de taxe introduit ici (hors périmètre de ce lot).
function forfaitSummary(offering: FirmServiceOffering, forfait: PricingRule | null): string {
  if (!offering.feeApplicable) return "Pas de forfait";
  if (forfait) return `${Number(forfait.unitPrice).toFixed(2)} € HTVA`;
  return "Tarif à définir";
}

function representativeSummary(offering: FirmServiceOffering): string {
  if (!offering.representativePresenceRelevant) return "Délégué : aucun impact";
  const parts: string[] = [];
  if (offering.representativeSuppressesInterventionFee) parts.push("neutralise le forfait");
  if (offering.representativeSuppressesOwnMaterialFees) parts.push("neutralise le matériel");
  return parts.length > 0 ? `Délégué : ${parts.join(" et ")}` : "Délégué : présence enregistrée, aucun effet";
}

// ── Dialog : détail d'une prestation (intervention, forfait, matériels, délégué) ──
function OfferingDetailDialog({
  open, onClose, firmId, offering, forfait, onOpenForfait, onOpenSuggestions, onViewInReferentiel,
}: {
  open: boolean;
  onClose: () => void;
  firmId: number;
  offering: FirmServiceOffering | null;
  forfait: PricingRule | null;
  onOpenForfait: () => void;
  onOpenSuggestions: () => void;
  /** Refonte UX — bascule vers le Référentiel global (contexte GLOBAL), jamais un
   *  champ d'édition de l'identité clinique depuis cette prestation firme. */
  onViewInReferentiel: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();

  const policyMutation = useMutation({
    mutationFn: (body: Partial<Pick<FirmServiceOffering, "representativePresenceRelevant" | "representativeSuppressesInterventionFee" | "representativeSuppressesOwnMaterialFees">>) =>
      updateFirmServiceOffering(firmId, offering!.id, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["service-offerings", firmId] }),
    onError: (e) => toast.error(extractError(e)),
  });

  if (!offering) return null;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>
        {offering.interventionType.label}
        <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1, fontFamily: "monospace" }}>
          {offering.interventionType.code}
        </Typography>
        <Button size="small" onClick={onViewInReferentiel} sx={{ float: "right", mt: -0.5 }}>
          Voir dans le référentiel →
        </Button>
      </DialogTitle>
      <DialogContent>
        <Stack spacing={3} sx={{ pt: 1 }}>
          <Box>
            <Typography variant="overline" color="text.secondary">Facturation</Typography>
            <Stack direction="row" alignItems="center" spacing={1.5} sx={{ mt: 0.5 }}>
              <Typography variant="h6" fontWeight={700}>{forfaitSummary(offering, forfait)}</Typography>
              <Button size="small" onClick={onOpenForfait}>Modifier le forfait</Button>
            </Stack>
          </Box>

          <Box>
            <Stack direction="row" alignItems="center" justifyContent="space-between">
              <Typography variant="overline" color="text.secondary">Matériels suggérés</Typography>
              <Button size="small" startIcon={<AddIcon fontSize="small" />} onClick={onOpenSuggestions}>Ajouter</Button>
            </Stack>
            {offering.suggestedMaterials.length === 0 ? (
              <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>Aucun matériel suggéré.</Typography>
            ) : (
              <Stack direction="row" flexWrap="wrap" useFlexGap spacing={0.75} sx={{ mt: 0.75 }}>
                {[...offering.suggestedMaterials].sort((a, b) => a.displayOrder - b.displayOrder).map((s) => (
                  <Chip key={s.id} size="small" label={s.materialItem.label} variant="outlined" />
                ))}
              </Stack>
            )}
          </Box>

          <Box>
            <Typography variant="overline" color="text.secondary">Présence d'un délégué</Typography>
            <Typography variant="body2" sx={{ mt: 0.5, mb: 1 }}>
              La présence d'un délégué influence-t-elle la facturation de cette prestation ?
            </Typography>
            <RadioGroup
              row
              value={offering.representativePresenceRelevant ? "yes" : "no"}
              onChange={(e) => policyMutation.mutate({ representativePresenceRelevant: e.target.value === "yes" })}
            >
              <FormControlLabel value="no" control={<Radio size="small" />} label="Non" disabled={policyMutation.isPending} />
              <FormControlLabel value="yes" control={<Radio size="small" />} label="Oui" disabled={policyMutation.isPending} />
            </RadioGroup>

            {offering.representativePresenceRelevant && (
              <Stack spacing={0.5} sx={{ mt: 1, pl: 1 }}>
                <FormControlLabel
                  control={
                    <Checkbox
                      size="small"
                      checked={offering.representativeSuppressesInterventionFee}
                      onChange={(e) => policyMutation.mutate({ representativeSuppressesInterventionFee: e.target.checked })}
                      disabled={policyMutation.isPending}
                    />
                  }
                  label="Neutralise le forfait de cette prestation"
                />
                <FormControlLabel
                  control={
                    <Checkbox
                      size="small"
                      checked={offering.representativeSuppressesOwnMaterialFees}
                      onChange={(e) => policyMutation.mutate({ representativeSuppressesOwnMaterialFees: e.target.checked })}
                      disabled={policyMutation.isPending}
                    />
                  }
                  label="Neutralise le matériel facturable de cette firme"
                />
              </Stack>
            )}
          </Box>
        </Stack>
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

  // Refonte UX (docs/design/screens/catalogue-prestations/README.md) — deux
  // destinations de poids différent, jamais 3 onglets à plat : "firms" (configuration
  // par firme) vs "referentiel" (bibliothèque clinique globale).
  const [view, setView] = React.useState<"firms" | "referentiel">("firms");
  const [referentielDetailId, setReferentielDetailId] = React.useState<number | null>(null);
  const [logoDialogOpen, setLogoDialogOpen] = React.useState(false);

  const [selectedFirmId, setSelectedFirmId] = React.useState<number | null>(null);
  const [firmSearch, setFirmSearch] = React.useState("");
  const debouncedFirmSearch = useDebouncedValue(firmSearch, 300);
  const [tab, setTab] = React.useState<"offerings" | "materials">("offerings");

  const [addOfferingOpen, setAddOfferingOpen] = React.useState(false);
  const [detailTargetId, setDetailTargetId] = React.useState<number | null>(null);
  const [forfaitTargetId, setForfaitTargetId] = React.useState<number | null>(null);
  const [suggestionsTargetId, setSuggestionsTargetId] = React.useState<number | null>(null);
  const [createMaterialOpen, setCreateMaterialOpen] = React.useState(false);
  const [createMaterialError, setCreateMaterialError] = React.useState<string | null>(null);
  // Point 10 (audit tarification) — un seul point d'entrée « Modifier » par matériel
  // (identification + tarification combinées), remplace les anciens materialRateTarget/
  // editMaterialTarget distincts. Stocke l'id, pas l'objet : le dialogue doit refléter
  // les données fraîches après une mutation interne (ex: passage à NOT_BILLABLE), pas
  // un instantané figé au moment du clic sur la ligne.
  const [materialEditTargetId, setMaterialEditTargetId] = React.useState<number | null>(null);

  const firmsQuery = useQuery({ queryKey: ["firms"], queryFn: getFirms });
  const firms: FirmDTO[] = firmsQuery.data ?? [];
  const filteredFirms = debouncedFirmSearch.trim()
    ? firms.filter((f) => f.name.toLowerCase().includes(debouncedFirmSearch.trim().toLowerCase()))
    : firms;
  const selectedFirm = firms.find((f) => f.id === selectedFirmId) ?? null;

  // Même clé de requête qu'InterventionTypesManager ("intervention-types") — un seul
  // fetch réseau réellement effectué, le cache React Query est partagé.
  const interventionTypesQuery = useQuery({ queryKey: ["intervention-types"], queryFn: () => getInterventionTypes() });
  const referentielDetailType: InterventionType | null =
    (interventionTypesQuery.data ?? []).find((t) => t.id === referentielDetailId) ?? null;

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

  const createMaterialMutation = useMutation({
    mutationFn: createMaterialItem,
    onSuccess: () => {
      toast.success("Article créé");
      qc.invalidateQueries({ queryKey: ["material-items-firm", selectedFirmId] });
      qc.invalidateQueries({ queryKey: ["material-items"] });
      setCreateMaterialOpen(false);
      setCreateMaterialError(null);
    },
    onError: (e: any) => setCreateMaterialError(extractError(e)),
  });

  const rules = rulesQuery.data ?? [];
  const offerings = offeringsQuery.data ?? [];
  const materials: MaterialItemDTO[] = materialsQuery.data?.items ?? [];

  const detailTarget = offerings.find((o) => o.id === detailTargetId) ?? null;
  const forfaitTarget = offerings.find((o) => o.id === forfaitTargetId) ?? null;
  const suggestionsTarget = offerings.find((o) => o.id === suggestionsTargetId) ?? null;
  const materialEditTarget = materials.find((m) => m.id === materialEditTargetId) ?? null;

  function rulesForIntervention(interventionTypeId: number): PricingRule[] {
    return rules.filter((r) => r.ruleType === "INTERVENTION_FEE" && r.interventionType?.id === interventionTypeId);
  }
  function forfaitFor(interventionTypeId: number): PricingRule | null {
    const lineage = rulesForIntervention(interventionTypeId);
    const activeVersion = getActiveVersion(lineage.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo })));
    return activeVersion ? lineage.find((r) => r.id === activeVersion.id) ?? null : null;
  }
  function materialRulesFor(materialItemId: number): PricingRule[] {
    return rules.filter((r) => r.ruleType === "MATERIAL_FEE" && r.materialItem?.id === materialItemId);
  }

  return (
    <Box sx={{ p: 3 }}>
      <PageHeader
        icon={CategoryIcon}
        title="Prestations"
        subtitle="Pour chaque firme : quelles prestations, quel matériel, combien ça coûte."
        helpTopicId="billing-config"
      />

      {/* Refonte UX — deux destinations de poids différent (jamais 3 onglets à plat) :
          Firmes = configuration commerciale par firme, Référentiel = bibliothèque
          clinique globale. Voir docs/design/screens/catalogue-prestations/README.md. */}
      <Stack direction="row" spacing={1.5} sx={{ mb: 1.5 }}>
        <Button
          variant={view === "firms" ? "contained" : "outlined"}
          disableElevation
          startIcon={<BusinessIcon />}
          onClick={() => setView("firms")}
          sx={{ flexDirection: "column", alignItems: "flex-start", textAlign: "left", py: 1, px: 2 }}
        >
          <Typography variant="body2" fontWeight={700} sx={{ display: "flex", alignItems: "center", gap: .5 }}>Firmes</Typography>
          <Typography variant="caption" sx={{ opacity: .8, textTransform: "none" }}>Configuration commerciale par firme</Typography>
        </Button>
        <Button
          variant={view === "referentiel" ? "contained" : "outlined"}
          disableElevation
          startIcon={<MenuBookIcon />}
          onClick={() => setView("referentiel")}
          sx={{ flexDirection: "column", alignItems: "flex-start", textAlign: "left", py: 1, px: 2 }}
        >
          <Typography variant="body2" fontWeight={700}>Référentiel</Typography>
          <Typography variant="caption" sx={{ opacity: .8, textTransform: "none" }}>Bibliothèque clinique globale, commune à toutes les firmes</Typography>
        </Button>
      </Stack>

      {/* Bandeau de contexte permanent — le logo/nom de firme disparaît entièrement en
          vue Référentiel : le signal le plus fort de sortie de contexte. */}
      {view === "firms" && selectedFirm ? (
        <Alert icon={false} severity="info" variant="outlined" sx={{ mb: 2, py: .5 }}>
          <Stack direction="row" alignItems="center" spacing={1}>
            <Typography variant="overline" fontWeight={700}>CONFIGURATION FIRME</Typography>
            <Typography color="text.secondary">·</Typography>
            <FirmAvatar name={selectedFirm.name} logoPath={selectedFirm.logoPath} size="xs" />
            <Typography variant="body2" fontWeight={700}>{selectedFirm.name}</Typography>
          </Stack>
        </Alert>
      ) : view === "referentiel" ? (
        <Alert icon={false} severity="info" variant="outlined" sx={{ mb: 2, py: .5 }}>
          <Typography variant="overline" fontWeight={700}>RÉFÉRENTIEL GLOBAL</Typography>
          <Typography variant="body2" color="text.secondary" component="span"> · Commun à toutes les firmes — aucune configuration commerciale ici.</Typography>
        </Alert>
      ) : null}

      {view === "referentiel" ? (
        <Paper variant="outlined" sx={{ borderRadius: 2, p: 2.5, minHeight: 480 }}>
          <InterventionTypesManager showHeader={false} onOpenDetail={(t) => setReferentielDetailId(t.id)} />
        </Paper>
      ) : (
      <Paper variant="outlined" sx={{ borderRadius: 2, display: "flex", minHeight: 480, overflow: "hidden" }}>
        <SideList
          items={filteredFirms.map((f) => ({ id: f.id, label: f.name, avatarLabel: f.name, avatarUrl: resolveApiAssetUrl(f.logoPath) }))}
          selectedId={selectedFirmId}
          onSelect={(id) => setSelectedFirmId(Number(id))}
          searchValue={firmSearch}
          onSearchChange={setFirmSearch}
          countLabel="Firmes"
          searchPlaceholder="Rechercher une firme…"
          emptyMessage="Aucune firme."
        />

        <Box sx={{ flex: 1, minWidth: 0, p: 2.5 }}>
          {selectedFirmId === null || !selectedFirm ? (
            <EmptyState
              title="Sélectionnez une firme pour commencer"
              description="Prestations et tarifs contractuels restent deux choses indépendantes — le contact de facturation reste géré depuis la fiche Firme."
            />
          ) : (
            <>
              {/* Contexte firme (écran 3) — identité toujours visible avant les onglets. */}
              <Stack direction="row" alignItems="center" spacing={1.5} sx={{ mb: 2.5 }}>
                <FirmAvatar name={selectedFirm.name} logoPath={selectedFirm.logoPath} size="lg" />
                <Box>
                  <Typography variant="h6" fontWeight={700}>{selectedFirm.name}</Typography>
                  <Button size="small" onClick={() => setLogoDialogOpen(true)} sx={{ p: 0, minWidth: 0 }}>
                    Gérer le logo
                  </Button>
                </Box>
              </Stack>

              <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ mb: 2, minHeight: 36 }}>
                <Tab value="offerings" label="Prestations" sx={{ minHeight: 36 }} />
                <Tab value="materials" label="Matériel" sx={{ minHeight: 36 }} />
              </Tabs>

              {tab === "offerings" && (
                <Box>
                  <Stack direction="row" alignItems="center" justifyContent="flex-end" mb={2}>
                    <Tooltip title="Ajouter une prestation">
                      <IconButton
                        aria-label="Ajouter une prestation"
                        onClick={() => setAddOfferingOpen(true)}
                        sx={{ border: "1px solid", borderColor: "divider", borderRadius: 999 }}
                        size="small"
                      >
                        <AddIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </Stack>

                  {offeringsQuery.isLoading ? (
                    <CircularProgress size={20} />
                  ) : offerings.length === 0 ? (
                    <EmptyState
                      icon={MedicalServicesIcon}
                      title="Aucune prestation renseignée pour cette firme"
                      description="Ajoutez les interventions proposées par cette firme pour faciliter l'encodage et configurer leur facturation."
                      action={{ label: "+ Ajouter une prestation", onClick: () => setAddOfferingOpen(true) }}
                    />
                  ) : (
                    <Stack spacing={1.5}>
                      {offerings.map((o) => {
                        const forfait = forfaitFor(o.interventionType.id);
                        return (
                          <Paper
                            key={o.id}
                            variant="outlined"
                            onClick={() => setDetailTargetId(o.id)}
                            sx={{ p: 1.75, borderRadius: 2, cursor: "pointer", "&:hover": { borderColor: "primary.main", bgcolor: "grey.50" } }}
                          >
                            <Stack direction="row" alignItems="center" spacing={1.5}>
                              <Stack sx={{ flex: 1, minWidth: 0 }}>
                                <Stack direction="row" alignItems="center" spacing={1}>
                                  <Typography fontWeight={700} variant="body2">{o.interventionType.label}</Typography>
                                  <Chip label={o.interventionType.code} size="small" variant="outlined" sx={{ fontFamily: "monospace", fontSize: ".68rem" }} />
                                  {!o.active && <Chip label="Inactive" size="small" color="default" />}
                                  {o.representativePresenceRelevant && <BadgeOutlinedIcon fontSize="small" sx={{ color: "text.disabled" }} />}
                                </Stack>
                                <Typography variant="caption" color="text.secondary">
                                  {forfaitSummary(o, forfait)} · {o.suggestedMaterials.length} matériel{o.suggestedMaterials.length !== 1 ? "s" : ""} suggéré{o.suggestedMaterials.length !== 1 ? "s" : ""} · {representativeSummary(o)}
                                </Typography>
                              </Stack>
                              <ChevronRightIcon fontSize="small" sx={{ color: "text.disabled" }} />
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
                  <Stack direction="row" alignItems="center" justifyContent="flex-end" mb={2}>
                    <Tooltip title="Ajouter un matériel">
                      <IconButton
                        aria-label="Ajouter un matériel"
                        onClick={() => setCreateMaterialOpen(true)}
                        sx={{ border: "1px solid", borderColor: "divider", borderRadius: 999 }}
                        size="small"
                      >
                        <AddIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </Stack>

                  {materialsQuery.isLoading || rulesQuery.isLoading ? (
                    <CircularProgress size={20} />
                  ) : materials.length === 0 ? (
                    <EmptyState
                      icon={Inventory2OutlinedIcon}
                      title="Aucun matériel renseigné pour cette firme"
                      description="Ajoutez le matériel de cette firme pour pouvoir le sélectionner pendant l'encodage."
                      action={{ label: "+ Ajouter un matériel", onClick: () => setCreateMaterialOpen(true) }}
                    />
                  ) : (
                    <Table size="small">
                      <TableHead>
                        <TableRow sx={{ bgcolor: "grey.50" }}>
                          <TableCell>Matériel</TableCell>
                          <TableCell>Référence</TableCell>
                          <TableCell align="right">Tarif actuel</TableCell>
                          <TableCell>Historique</TableCell>
                          <TableCell align="right">Actions</TableCell>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {materials.map((m) => {
                          const itemRules = materialRulesFor(m.id);
                          const activeVersion = getActiveVersion(itemRules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo })));
                          return (
                            <TableRow key={m.id} hover>
                              <TableCell>
                                <Typography variant="body2">{m.label}</Typography>
                              </TableCell>
                              <TableCell>
                                <Typography variant="caption" color="text.secondary">{m.referenceCode || "—"}</Typography>
                              </TableCell>
                              <TableCell align="right">
                                {activeVersion ? (
                                  <Typography variant="body2" fontWeight={700} color="success.main">
                                    {Number(activeVersion.amount).toFixed(2)} {activeVersion.currency} HTVA
                                  </Typography>
                                ) : (
                                  <Typography variant="caption" color="text.secondary">
                                    Tarif à définir
                                  </Typography>
                                )}
                              </TableCell>
                              <TableCell>
                                <Typography variant="caption" color="text.secondary">
                                  {itemRules.length > 0 ? `${itemRules.length} version${itemRules.length > 1 ? "s" : ""}` : "—"}
                                </Typography>
                              </TableCell>
                              <TableCell align="right">
                                <Tooltip title="Modifier le matériel">
                                  <IconButton
                                    aria-label={`Modifier ${m.label}`}
                                    size="small"
                                    onClick={() => setMaterialEditTargetId(m.id)}
                                  >
                                    <EditIcon fontSize="small" />
                                  </IconButton>
                                </Tooltip>
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
      )}

      <InterventionTypeDetailDialog
        open={referentielDetailId !== null}
        onClose={() => setReferentielDetailId(null)}
        intervention={referentielDetailType}
        onOpenFirm={(firmId, offeringId) => {
          setView("firms");
          setSelectedFirmId(firmId);
          setReferentielDetailId(null);
          setTab("offerings");
          setDetailTargetId(offeringId);
        }}
      />

      {selectedFirm && (
        <FirmLogoDialog open={logoDialogOpen} onClose={() => setLogoDialogOpen(false)} firm={selectedFirm} />
      )}

      {selectedFirmId !== null && (
        <>
          <AddOfferingDialog
            open={addOfferingOpen}
            onClose={() => setAddOfferingOpen(false)}
            firmId={selectedFirmId}
            existingTypeIds={offerings.map((o) => o.interventionType.id)}
            onCreated={() => qc.invalidateQueries({ queryKey: ["service-offerings", selectedFirmId] })}
          />
          <OfferingDetailDialog
            open={detailTargetId !== null}
            onClose={() => setDetailTargetId(null)}
            firmId={selectedFirmId}
            offering={detailTarget}
            forfait={detailTarget ? forfaitFor(detailTarget.interventionType.id) : null}
            onOpenForfait={() => { setForfaitTargetId(detailTargetId); }}
            onOpenSuggestions={() => { setSuggestionsTargetId(detailTargetId); }}
            onViewInReferentiel={() => {
              const typeId = detailTarget?.interventionType.id ?? null;
              setDetailTargetId(null);
              setReferentielDetailId(typeId);
              setView("referentiel");
            }}
          />
          <ForfaitDialog
            open={forfaitTargetId !== null}
            onClose={() => setForfaitTargetId(null)}
            firmId={selectedFirmId}
            offering={forfaitTarget}
            rules={forfaitTarget ? rulesForIntervention(forfaitTarget.interventionType.id) : []}
          />
          <SuggestedMaterialsDialog
            open={suggestionsTargetId !== null}
            onClose={() => setSuggestionsTargetId(null)}
            firmId={selectedFirmId}
            offering={suggestionsTarget}
            firmMaterials={materials}
          />
          <MaterialItemFormDialog
            open={createMaterialOpen}
            title="Ajouter un matériel"
            initial={{ firmId: selectedFirmId }}
            firmLocked
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
          <MaterialEditDialog
            open={materialEditTarget !== null}
            onClose={() => setMaterialEditTargetId(null)}
            firmId={selectedFirmId}
            material={materialEditTarget}
            rules={materialEditTarget ? materialRulesFor(materialEditTarget.id) : []}
          />
        </>
      )}
    </Box>
  );
}
