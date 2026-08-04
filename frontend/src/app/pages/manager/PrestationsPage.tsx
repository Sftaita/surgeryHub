import * as React from "react";
import {
  Alert,
  Box,
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
  TextField,
  Tooltip,
  Typography,
  Button as MuiButton,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import ArrowUpwardIcon from "@mui/icons-material/ArrowUpward";
import ArrowDownwardIcon from "@mui/icons-material/ArrowDownward";
import CategoryIcon from "@mui/icons-material/Category";
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
  updateInterventionType,
  findSimilarInterventionTypes,
  getInterventionTypeOfferings,
  type InterventionType,
  type SimilarInterventionTypeCandidate,
} from "../../features/intervention-types/api/interventionTypes.api";
import { getFirms, getMaterialItems, createMaterialItem, updateMaterialItem } from "../../features/manager-catalogue/api/catalogue.api";
import type { FirmDTO, MaterialItemDTO } from "../../features/manager-catalogue/api/catalogue.types";
import { FirmAvatar } from "../../features/manager-catalogue/components/FirmAvatar";
import { FirmLogoDialog } from "../../features/manager-catalogue/components/FirmLogoDialog";
import { useToast } from "../../ui/toast/useToast";
import { PageHeader } from "../../ui/PageHeader";
import { useDebouncedValue } from "../../ui/hooks/useDebouncedValue";
import styles from "./prestationsDesign.module.css";
import { Modal, Btn, Switch, StatusPill, Tag } from "./prestationsUi";
import { PlusIcon, SearchIcon, EditIcon as PrEditIcon, WarningIcon, BuildingIcon, BookIcon, ArrowLeftIcon, ChevronRightIcon } from "./prestationsIcons";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.response?.data?.message ?? e?.message ?? String(err);
}

// ── Modale : créer une intervention dans le référentiel global ─────────────
// Formulaire code/libellé/spécialité/statut, détection de doublon approximative
// AVANT création (jamais un blocage) — utilisé à la fois depuis « Nouvelle
// intervention » (écran Référentiel) et depuis le flux « Ajouter une
// prestation » (création inline quand l'intervention n'existe pas encore).
function NewInterventionModal({
  initialLabel = "",
  onClose,
  onCreated,
}: {
  initialLabel?: string;
  onClose: () => void;
  onCreated: (type: InterventionType) => void;
}) {
  const toast = useToast();
  const [code, setCode] = React.useState("");
  const [label, setLabel] = React.useState(initialLabel);
  const [specialty, setSpecialty] = React.useState("");
  const [candidates, setCandidates] = React.useState<SimilarInterventionTypeCandidate[] | null>(null);
  const [checkingSimilar, setCheckingSimilar] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const createMutation = useMutation({
    mutationFn: createInterventionType,
    onSuccess: (created) => { toast.success("Intervention ajoutée au référentiel global"); onCreated(created); },
    onError: (e) => setError(extractError(e)),
  });

  async function handleSave() {
    setError(null);
    if (!code.trim() || !label.trim()) { setError("Indiquez un code et un libellé."); return; }
    setCheckingSimilar(true);
    try {
      const found = await findSimilarInterventionTypes(label.trim());
      if (found.length > 0) { setCandidates(found); return; }
    } finally {
      setCheckingSimilar(false);
    }
    createMutation.mutate({ code: code.trim().toUpperCase(), label: label.trim(), specialty: specialty.trim() || undefined });
  }

  return (
    <Modal title="Nouvelle intervention" onClose={onClose} width={440}>
      <div className={styles.contextBanner} style={{ background: "var(--pr-gray-75)", border: "none", margin: "8px 0 0" }}>
        <span className={styles.contextBannerDesc}>
          Cette intervention sera ajoutée au <strong>référentiel global</strong> et pourra ensuite être utilisée par toutes les firmes.
        </span>
      </div>
      <div className={styles.formStack}>
        <div className={styles.fieldRow}>
          <div className={styles.field} style={{ width: 130, flex: "none" }}>
            <label className={styles.fieldLabel} htmlFor="ni-code">Code *</label>
            <input
              id="ni-code"
              className={styles.fieldInput}
              style={{ textTransform: "uppercase", fontFamily: "monospace", fontWeight: 700 }}
              value={code}
              onChange={(e) => setCode(e.target.value.toUpperCase())}
              placeholder="Ex. PTG"
            />
          </div>
          <div className={styles.field}>
            <label className={styles.fieldLabel} htmlFor="ni-label">Libellé *</label>
            <input id="ni-label" className={styles.fieldInput} value={label} onChange={(e) => { setLabel(e.target.value); setCandidates(null); }} placeholder="Ex. Prothèse totale de genou" />
          </div>
        </div>
        <div className={styles.field}>
          <label className={styles.fieldLabel} htmlFor="ni-specialty">Spécialité <span className={styles.fieldHint}>(optionnel)</span></label>
          <input id="ni-specialty" className={styles.fieldInput} value={specialty} onChange={(e) => setSpecialty(e.target.value)} placeholder="Indicatif uniquement" />
        </div>

        {candidates && candidates.length > 0 && (
          <div style={{ background: "var(--pr-amber-50)", border: "1px solid var(--pr-amber-200)", borderRadius: 12, padding: "13px 14px" }}>
            <div style={{ display: "flex", gap: 8, fontSize: 12.5, color: "var(--pr-amber-800)", lineHeight: 1.5, marginBottom: 8 }}>
              <WarningIcon size={16} /> Cette intervention ressemble à une déjà présente :
            </div>
            <Stack spacing={1}>
              {candidates.map((c) => (
                <div key={c.type.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, background: "#fff", borderRadius: 10, padding: "8px 10px" }}>
                  <div>
                    <div style={{ fontSize: 13.5, fontWeight: 700 }}>{c.type.label}</div>
                    <div className={styles.refRowCode}>{c.type.code}</div>
                  </div>
                  <Btn small onClick={() => onCreated(c.type)}>Utiliser ce type</Btn>
                </div>
              ))}
            </Stack>
            <div style={{ display: "flex", gap: 8, marginTop: 10 }}>
              <Btn small variant="secondary" disabled={createMutation.isPending} onClick={() => createMutation.mutate({ code: code.trim().toUpperCase(), label: label.trim(), specialty: specialty.trim() || undefined })}>
                {createMutation.isPending ? <CircularProgress size={14} /> : "Créer quand même"}
              </Btn>
            </div>
          </div>
        )}
        {error && <div className={styles.warningLine}><WarningIcon size={14} /> {error}</div>}
      </div>
      {!candidates && (
        <div style={{ marginTop: 20 }}>
          <Btn fullWidth disabled={createMutation.isPending || checkingSimilar} onClick={handleSave}>
            {createMutation.isPending || checkingSimilar ? <CircularProgress size={16} /> : "Ajouter au référentiel"}
          </Btn>
        </div>
      )}
    </Modal>
  );
}

// ── Modale : modifier une intervention du référentiel (champs globaux uniquement) ──
function EditInterventionModal({
  intervention, onClose,
}: {
  intervention: InterventionType;
  onClose: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [label, setLabel] = React.useState(intervention.label);
  const [specialty, setSpecialty] = React.useState(intervention.specialty ?? "");
  const [active, setActive] = React.useState(intervention.active);

  const mutation = useMutation({
    mutationFn: () => updateInterventionType(intervention.id, { label: label.trim(), specialty: specialty.trim() || null, active }),
    onSuccess: () => { toast.success("Intervention mise à jour"); qc.invalidateQueries({ queryKey: ["intervention-types"] }); onClose(); },
    onError: (e) => toast.error(extractError(e)),
  });

  return (
    <Modal title="Modifier l'intervention" subtitle="Champs globaux uniquement — aucun tarif, aucune firme." onClose={onClose} width={420}>
      <div className={styles.formStack}>
        <div className={styles.fieldRow}>
          <div className={styles.field} style={{ width: 130, flex: "none" }}>
            <label className={styles.fieldLabel} htmlFor="ei-code">Code</label>
            <input id="ei-code" className={styles.fieldInput} style={{ fontFamily: "monospace", fontWeight: 700 }} value={intervention.code} disabled />
          </div>
          <div className={styles.field}>
            <label className={styles.fieldLabel} htmlFor="ei-label">Libellé *</label>
            <input id="ei-label" className={styles.fieldInput} value={label} onChange={(e) => setLabel(e.target.value)} />
          </div>
        </div>
        <div className={styles.field}>
          <label className={styles.fieldLabel} htmlFor="ei-specialty">Spécialité</label>
          <input id="ei-specialty" className={styles.fieldInput} value={specialty} onChange={(e) => setSpecialty(e.target.value)} placeholder="Indicatif uniquement" />
        </div>
        <Switch label="Intervention active" checked={active} onChange={setActive} />
      </div>
      <div style={{ marginTop: 20 }}>
        <Btn fullWidth disabled={mutation.isPending || !label.trim()} onClick={() => mutation.mutate()}>
          {mutation.isPending ? <CircularProgress size={16} /> : "Enregistrer"}
        </Btn>
      </div>
    </Modal>
  );
}

// ── Modale : ajouter une prestation — recherche + création dans le même flux ───
// Taille fixe, liste complète du référentiel triée alphabétiquement, recherche
// dynamique (jamais limitée aux premiers résultats) — cf. AddPrestationSheet.tsx
// du prototype de référence.
function AddPrestationModal({
  onClose, firmId, firmName, existingTypeIds, onCreated,
}: {
  onClose: () => void;
  firmId: number;
  firmName: string;
  existingTypeIds: number[];
  onCreated: () => void;
}) {
  const toast = useToast();
  const qc = useQueryClient();
  const [search, setSearch] = React.useState("");
  const [creatingNew, setCreatingNew] = React.useState(false);

  const typesQuery = useQuery({ queryKey: ["intervention-types", "active"], queryFn: () => getInterventionTypes(true) });
  const allTypes = typesQuery.data ?? [];
  const q = search.trim().toLowerCase();
  const results = [...allTypes]
    .filter((t) => !q || t.label.toLowerCase().includes(q) || t.code.toLowerCase().includes(q))
    .sort((a, b) => a.label.localeCompare(b.label, "fr"));

  const createOfferingMutation = useMutation({
    mutationFn: (interventionTypeId: number) => createFirmServiceOffering(firmId, { interventionTypeId }),
    onSuccess: () => { toast.success("Prestation créée"); onCreated(); onClose(); },
    onError: (e) => toast.error(extractError(e)),
  });

  function handleCreated(type: InterventionType) {
    setCreatingNew(false);
    qc.invalidateQueries({ queryKey: ["intervention-types"] });
    if (existingTypeIds.includes(type.id)) { toast.error("Cette firme a déjà une prestation sur cette intervention."); return; }
    createOfferingMutation.mutate(type.id);
  }

  if (creatingNew) {
    return <NewInterventionModal initialLabel={search} onClose={() => setCreatingNew(false)} onCreated={handleCreated} />;
  }

  return (
    <Modal title="Choisir dans le référentiel" subtitle={`pour ${firmName}`} onClose={onClose} width={520}>
      <div className={styles.searchInput} style={{ marginTop: 4 }}>
        <SearchIcon size={16} className={styles.searchInputIcon} />
        <input autoFocus placeholder="Rechercher une intervention…" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <div className={styles.addPrestaList}>
        {typesQuery.isLoading ? (
          <div style={{ display: "flex", justifyContent: "center", padding: 20 }}><CircularProgress size={20} /></div>
        ) : (
          <>
            {results.map((t) => {
              const already = existingTypeIds.includes(t.id);
              return (
                <div key={t.id} className={styles.addPrestaRow}>
                  <div className={styles.addPrestaRowInfo}>
                    <div className={styles.addPrestaRowName}>{t.label}</div>
                    <div className={styles.addPrestaRowCode}>{t.code}</div>
                  </div>
                  {already ? (
                    <span className={styles.addPrestaAlready}>Déjà configurée</span>
                  ) : (
                    <Btn small disabled={createOfferingMutation.isPending} onClick={() => createOfferingMutation.mutate(t.id)}>Ajouter</Btn>
                  )}
                </div>
              );
            })}
            {results.length === 0 && <div className={styles.emptyState} style={{ padding: "18px 6px 6px" }}>Aucune intervention correspondante.</div>}
            {q.length > 0 && (
              <button type="button" className={styles.addPrestaCreate} onClick={() => setCreatingNew(true)}>
                <span className={styles.addPrestaCreateTitle}><PlusIcon size={14} /> Ajouter «&nbsp;{search.trim()}&nbsp;» au référentiel</span>
                <span className={styles.addPrestaCreateSub}>Cette intervention sera ajoutée au référentiel global et pourra ensuite être utilisée par toutes les firmes.</span>
              </button>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}

// ── Modale : nouveau matériel (création) — champs identification uniquement,
// la tarification se fait ensuite via « Modifier » (RateVersionManager, D-072). ──
function NewMaterialModal({
  onClose, firmId, firmName, onCreated,
}: {
  onClose: () => void;
  firmId: number;
  firmName: string;
  onCreated: () => void;
}) {
  const [label, setLabel] = React.useState("");
  const [unit, setUnit] = React.useState("");
  const [referenceCode, setReferenceCode] = React.useState("");
  const [isImplant, setIsImplant] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: createMaterialItem,
    onSuccess: () => onCreated(),
    onError: (e) => setError(extractError(e)),
  });

  function handleSave() {
    setError(null);
    if (!label.trim() || !unit.trim()) { setError("Indiquez au moins le nom et l'unité du matériel."); return; }
    mutation.mutate({ firmId, label: label.trim(), unit: unit.trim(), referenceCode: referenceCode.trim() || undefined, isImplant });
  }

  return (
    <Modal title="Nouveau matériel" subtitle={firmName} onClose={onClose} width={440}>
      <div className={styles.formStack}>
        <div className={styles.field}>
          <label className={styles.fieldLabel} htmlFor="nm-label">Nom du matériel *</label>
          <input id="nm-label" className={styles.fieldInput} value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Ex. Fast Fix" />
        </div>
        <div className={styles.fieldRow}>
          <div className={styles.field}>
            <label className={styles.fieldLabel} htmlFor="nm-unit">Unité *</label>
            <input id="nm-unit" className={styles.fieldInput} value={unit} onChange={(e) => setUnit(e.target.value)} placeholder="Ex. pièce, boîte, ml" />
          </div>
          <div className={styles.field}>
            <label className={styles.fieldLabel} htmlFor="nm-reference">Référence</label>
            <input id="nm-reference" className={styles.fieldInput} value={referenceCode} onChange={(e) => setReferenceCode(e.target.value)} placeholder="Ex. FastFix" />
          </div>
        </div>
        <Switch label="Implant" checked={isImplant} onChange={setIsImplant} />
        <p className={styles.fieldHint} style={{ margin: 0, fontSize: 12.5 }}>
          Le tarif se configure ensuite, depuis « Modifier » sur la ligne du matériel — « Tarif à définir » tant qu'aucun tarif n'est encore posé.
        </p>
        {error && <div className={styles.warningLine}><WarningIcon size={14} /> {error}</div>}
      </div>
      <div style={{ marginTop: 20 }}>
        <Btn fullWidth disabled={mutation.isPending} onClick={handleSave}>
          {mutation.isPending ? <CircularProgress size={16} /> : "Enregistrer le matériel"}
        </Btn>
      </div>
    </Modal>
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
        <MuiButton onClick={onClose} variant="contained" disableElevation>Fermer</MuiButton>
      </DialogActions>
    </Dialog>
  );
}

// ── Dialog : édition complète d'un matériel (identification + tarification) ─
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
              <MuiButton
                size="small" variant="outlined" sx={{ alignSelf: "flex-start" }}
                disabled={identificationMutation.isPending || !label.trim() || !unit.trim()}
                onClick={() => identificationMutation.mutate({ label: label.trim(), unit: unit.trim(), referenceCode: referenceCode.trim() || undefined, isImplant })}
              >
                Enregistrer l'identification
              </MuiButton>
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
                <MuiButton
                  size="small" color="inherit" sx={{ mt: 1 }}
                  onClick={() => notBillableMutation.mutate()}
                  disabled={notBillableMutation.isPending}
                >
                  Marquer non facturable
                </MuiButton>
              )}
            </Box>
          </Box>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <MuiButton onClick={onClose} variant="contained" disableElevation>Fermer</MuiButton>
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
        <MuiButton onClick={onClose} variant="contained" disableElevation>Fermer</MuiButton>
      </DialogActions>
    </Dialog>
  );
}

// Refonte UX (§5) — trois états jamais confondus : montant défini, "Tarif à définir"
// (feeApplicable=true sans PricingRule active), "Pas de forfait" (feeApplicable=false,
// décision volontaire). Tous les montants sont HTVA.
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
        <MuiButton size="small" onClick={onViewInReferentiel} sx={{ float: "right", mt: -0.5 }}>
          Voir dans le référentiel →
        </MuiButton>
      </DialogTitle>
      <DialogContent>
        <Stack spacing={3} sx={{ pt: 1 }}>
          <Box>
            <Typography variant="overline" color="text.secondary">Facturation</Typography>
            <Stack direction="row" alignItems="center" spacing={1.5} sx={{ mt: 0.5 }}>
              <Typography variant="h6" fontWeight={700}>{forfaitSummary(offering, forfait)}</Typography>
              <MuiButton size="small" onClick={onOpenForfait}>Modifier le forfait</MuiButton>
            </Stack>
          </Box>

          <Box>
            <Stack direction="row" alignItems="center" justifyContent="space-between">
              <Typography variant="overline" color="text.secondary">Matériels suggérés</Typography>
              <MuiButton size="small" startIcon={<AddIcon fontSize="small" />} onClick={onOpenSuggestions}>Ajouter</MuiButton>
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
        <MuiButton onClick={onClose} variant="contained" disableElevation>Fermer</MuiButton>
      </DialogActions>
    </Dialog>
  );
}

// ── Référentiel — table (liste triée alphabétiquement + recherche dynamique) ──
function ReferentielTableView({
  types, isLoading, query, onQueryChange, onOpenDetail, onCreate,
}: {
  types: InterventionType[];
  isLoading: boolean;
  query: string;
  onQueryChange: (q: string) => void;
  onOpenDetail: (t: InterventionType) => void;
  onCreate: () => void;
}) {
  const q = query.trim().toLowerCase();
  const rows = [...types]
    .filter((t) => !q || t.label.toLowerCase().includes(q) || t.code.toLowerCase().includes(q))
    .sort((a, b) => a.label.localeCompare(b.label, "fr"));

  return (
    <div>
      <div className={styles.refToolbar}>
        <div className={styles.searchInput} style={{ margin: 0, flex: 1 }}>
          <SearchIcon size={17} className={styles.searchInputIcon} />
          <input placeholder="Rechercher un code ou une intervention…" value={query} onChange={(e) => onQueryChange(e.target.value)} />
        </div>
        <Btn onClick={onCreate}><PlusIcon size={16} /> Nouvelle intervention</Btn>
      </div>

      <div className={styles.refTable}>
        <div className={[styles.refRow, styles.refRowHead].join(" ")}>
          <span>CODE</span><span>INTERVENTION</span><span>FIRMES</span><span>STATUT</span><span />
        </div>
        {isLoading ? (
          <div style={{ display: "flex", justifyContent: "center", padding: 24 }}><CircularProgress size={20} /></div>
        ) : (
          <>
            {rows.map((t) => (
              <div key={t.id} className={styles.refRow} onClick={() => onOpenDetail(t)}>
                <span className={styles.refRowCode}>{t.code}</span>
                <span className={styles.refRowName}>{t.label}</span>
                <span className={styles.refRowFirms}>{t.firmsCount ?? 0}</span>
                <StatusPill active={t.active} activeLabel="Actif" inactiveLabel="Inactif" />
                <ChevronRightIcon size={16} className={styles.prestaCardChevron} />
              </div>
            ))}
            {types.length === 0 && (
              <div className={styles.emptyState}>
                Aucune intervention dans le référentiel.
                <div style={{ marginTop: 10 }}><Btn onClick={onCreate}>Créer la première intervention</Btn></div>
              </div>
            )}
            {types.length > 0 && rows.length === 0 && (
              <div className={styles.emptyState}>Aucune intervention correspondante.</div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

// ── Référentiel — détail d'une intervention (firmes utilisatrices) ─────────
function ReferentielDetailView({
  intervention, onBack, onEdit, onOpenFirm,
}: {
  intervention: InterventionType;
  onBack: () => void;
  onEdit: () => void;
  onOpenFirm: (firmId: number, offeringId: number) => void;
}) {
  const offeringsQuery = useQuery({
    queryKey: ["intervention-type-offerings", intervention.id],
    queryFn: () => getInterventionTypeOfferings(intervention.id),
  });
  const offerings = offeringsQuery.data ?? [];

  return (
    <div>
      <button type="button" className={styles.refDetailBack} onClick={onBack}><ArrowLeftIcon size={15} /> Retour au référentiel</button>
      <div className={styles.refDetailHead}>
        <div>
          <div className={styles.refDetailMeta}>
            <span className={styles.refRowCode}>{intervention.code}</span>
            <StatusPill active={intervention.active} activeLabel="Actif" inactiveLabel="Inactif" />
          </div>
          <h2 className={styles.refDetailTitle}>{intervention.label}</h2>
        </div>
        <div className={styles.refDetailActions}>
          <Btn variant="secondary" onClick={onEdit}>Modifier</Btn>
        </div>
      </div>

      <h3 className={styles.refUsageTitle}>Utilisée par {offerings.length} firme{offerings.length !== 1 ? "s" : ""}</h3>
      {offeringsQuery.isLoading ? (
        <div style={{ display: "flex", justifyContent: "center", padding: 24 }}><CircularProgress size={20} /></div>
      ) : (
        <div className={styles.refUsageList}>
          {offerings.map((row) => (
            <div key={row.offeringId} className={styles.refUsageRow}>
              <FirmAvatar name={row.firm.name} logoPath={row.firm.logoPath} size="sm" />
              <div className={styles.refUsageBody}>
                <div className={styles.refUsageName}>{row.firm.name}</div>
                <div className={styles.refUsageMeta}>
                  {row.feeApplicable ? (row.forfait ? `${Number(row.forfait.amount).toFixed(2)} ${row.forfait.currency} HTVA` : "Tarif à définir") : "Pas de forfait"}
                  {!row.active && " · inactive"}
                </div>
              </div>
              <button type="button" className={styles.refUsageLink} onClick={() => onOpenFirm(row.firm.id, row.offeringId)}>Ouvrir chez cette firme →</button>
            </div>
          ))}
          {offerings.length === 0 && <div className={[styles.emptyState, styles.emptyStateBoxed].join(" ")}>Aucune firme ne configure encore cette intervention.</div>}
        </div>
      )}
    </div>
  );
}

// ── Page principale ───────────────────────────────────────────────────────────
export default function PrestationsPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const [view, setView] = React.useState<"firms" | "referentiel">("firms");
  const [referentielDetailId, setReferentielDetailId] = React.useState<number | null>(null);
  const [refQuery, setRefQuery] = React.useState("");
  const [newInterventionOpen, setNewInterventionOpen] = React.useState(false);
  const [editInterventionTarget, setEditInterventionTarget] = React.useState<InterventionType | null>(null);
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
  const [materialEditTargetId, setMaterialEditTargetId] = React.useState<number | null>(null);

  const firmsQuery = useQuery({ queryKey: ["firms"], queryFn: getFirms });
  const firms: FirmDTO[] = firmsQuery.data ?? [];
  const filteredFirms = (debouncedFirmSearch.trim()
    ? firms.filter((f) => f.name.toLowerCase().includes(debouncedFirmSearch.trim().toLowerCase()))
    : firms
  ).slice().sort((a, b) => a.name.localeCompare(b.name, "fr"));
  const selectedFirm = firms.find((f) => f.id === selectedFirmId) ?? null;

  const interventionTypesQuery = useQuery({ queryKey: ["intervention-types"], queryFn: () => getInterventionTypes() });
  const allInterventionTypes = interventionTypesQuery.data ?? [];
  const referentielDetailType: InterventionType | null =
    allInterventionTypes.find((t) => t.id === referentielDetailId) ?? null;

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

  const rules = rulesQuery.data ?? [];
  const offerings = [...(offeringsQuery.data ?? [])].sort((a, b) => a.interventionType.label.localeCompare(b.interventionType.label, "fr"));
  const materials: MaterialItemDTO[] = [...(materialsQuery.data?.items ?? [])].sort((a, b) => a.label.localeCompare(b.label, "fr"));

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

  function goToFirm(firmId: number) {
    setSelectedFirmId(firmId);
    setTab("offerings");
    setView("firms");
    setReferentielDetailId(null);
  }

  return (
    <Box sx={{ p: 3 }} className={styles.root}>
      <PageHeader
        icon={CategoryIcon}
        title="Prestations"
        subtitle="Pour chaque firme : quelles prestations, quel matériel, combien ça coûte."
        helpTopicId="billing-config"
      />

      {/* Deux destinations de poids différent (jamais 3 onglets à plat) : Firmes =
          configuration commerciale par firme, Référentiel = bibliothèque clinique
          globale. Voir docs/design/screens/catalogue-prestations/README.md et le
          prototype de référence react-catalogue-prestations. */}
      <div className={styles.topNav}>
        <button
          type="button"
          className={[styles.topNavDest, view === "firms" ? styles.topNavDestActive : ""].filter(Boolean).join(" ")}
          onClick={() => setView("firms")}
        >
          <span className={styles.topNavDestTitle}><BuildingIcon size={17} /> Firmes</span>
          <span className={styles.topNavDestSub}>Configuration commerciale par firme</span>
        </button>
        <button
          type="button"
          className={[styles.topNavDest, view === "referentiel" ? styles.topNavDestActive : ""].filter(Boolean).join(" ")}
          onClick={() => { setView("referentiel"); setReferentielDetailId(null); }}
        >
          <span className={styles.topNavDestTitle}><BookIcon size={17} /> Référentiel</span>
          <span className={styles.topNavDestSub}>Bibliothèque clinique globale, commune à toutes les firmes</span>
        </button>
      </div>

      {/* Bandeau de contexte permanent — le logo/nom de firme disparaît entièrement
          en vue Référentiel : le signal le plus fort de sortie de contexte. */}
      {view === "firms" && selectedFirm ? (
        <div className={[styles.contextBanner, styles.contextBannerFirm].join(" ")}>
          <span className={styles.contextBannerEyebrow}>CONFIGURATION FIRME</span>
          <span className={styles.contextBannerSep}>·</span>
          <FirmAvatar name={selectedFirm.name} logoPath={selectedFirm.logoPath} size="xs" />
          <span className={styles.contextBannerName}>{selectedFirm.name}</span>
        </div>
      ) : view === "referentiel" ? (
        <div className={[styles.contextBanner, styles.contextBannerGlobal].join(" ")}>
          <span className={[styles.contextBannerEyebrow, styles.contextBannerEyebrowGlobal].join(" ")}>RÉFÉRENTIEL GLOBAL</span>
          <span className={styles.contextBannerSep}>·</span>
          <span className={styles.contextBannerDesc}>Commun à toutes les firmes — aucune configuration commerciale ici.</span>
        </div>
      ) : null}

      {view === "referentiel" ? (
        <div className={styles.referentielContent}>
          {referentielDetailType ? (
            <ReferentielDetailView
              intervention={referentielDetailType}
              onBack={() => setReferentielDetailId(null)}
              onEdit={() => setEditInterventionTarget(referentielDetailType)}
              onOpenFirm={(firmId, offeringId) => {
                goToFirm(firmId);
                setDetailTargetId(offeringId);
              }}
            />
          ) : (
            <ReferentielTableView
              types={allInterventionTypes}
              isLoading={interventionTypesQuery.isLoading}
              query={refQuery}
              onQueryChange={setRefQuery}
              onOpenDetail={(t) => setReferentielDetailId(t.id)}
              onCreate={() => setNewInterventionOpen(true)}
            />
          )}
        </div>
      ) : (
        <div className={styles.body}>
          <aside className={styles.sidebar}>
            <div className={styles.sidebarHead}>FIRMES · {filteredFirms.length}</div>
            <div className={styles.sidebarSearch}>
              <SearchIcon size={16} className={styles.sidebarSearchIcon} />
              <input className={styles.sidebarSearchInput} placeholder="Rechercher une firme…" value={firmSearch} onChange={(e) => setFirmSearch(e.target.value)} />
            </div>
            <div className={styles.sidebarList}>
              {filteredFirms.map((f) => (
                <button
                  key={f.id}
                  type="button"
                  className={[styles.sidebarRow, f.id === selectedFirmId ? styles.sidebarRowActive : ""].filter(Boolean).join(" ")}
                  onClick={() => goToFirm(f.id)}
                >
                  <FirmAvatar name={f.name} logoPath={f.logoPath} size="sm" />
                  <span className={styles.sidebarName}>{f.name}</span>
                </button>
              ))}
              {filteredFirms.length === 0 && <div className={styles.emptyState}>Aucune firme trouvée.</div>}
            </div>
          </aside>

          <div className={styles.content}>
            {selectedFirmId === null || !selectedFirm ? (
              <div className={styles.emptyState} style={{ paddingTop: 80 }}>
                <Typography variant="h6" fontWeight={700} color="text.primary" gutterBottom>
                  Sélectionnez une firme pour commencer
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  Prestations et tarifs contractuels restent deux choses indépendantes — le contact de facturation reste géré depuis la fiche Firme.
                </Typography>
              </div>
            ) : (
              <>
                <div className={styles.firmHeader}>
                  <FirmAvatar name={selectedFirm.name} logoPath={selectedFirm.logoPath} size="lg" />
                  <div>
                    <h2 className={styles.firmHeaderName}>{selectedFirm.name}</h2>
                    <button type="button" className={styles.firmHeaderManageLink} onClick={() => setLogoDialogOpen(true)}>Gérer le logo</button>
                  </div>
                </div>

                <div className={styles.tabs} role="tablist">
                  <button type="button" role="tab" aria-selected={tab === "offerings"} className={[styles.tab, tab === "offerings" ? styles.tabActive : ""].filter(Boolean).join(" ")} onClick={() => setTab("offerings")}>
                    Prestations <span className={styles.tabCount}>{offerings.length}</span>
                  </button>
                  <button type="button" role="tab" aria-selected={tab === "materials"} className={[styles.tab, tab === "materials" ? styles.tabActive : ""].filter(Boolean).join(" ")} onClick={() => setTab("materials")}>
                    Matériel <span className={styles.tabCount}>{materials.length}</span>
                  </button>
                </div>

                {tab === "offerings" && (
                  <div className={styles.panelIntro} style={{ marginTop: 18 }}>
                    <p className={styles.panelIntroText}>Une prestation associe cette firme à une intervention du référentiel : forfait, matériel suggéré, statut.</p>
                    <Btn onClick={() => setAddOfferingOpen(true)}><PlusIcon size={16} /> Ajouter une prestation</Btn>
                  </div>
                )}
                {tab === "offerings" && (
                  offeringsQuery.isLoading ? (
                    <div style={{ display: "flex", justifyContent: "center", padding: 24 }}><CircularProgress size={20} /></div>
                  ) : offerings.length === 0 ? (
                    <div className={styles.emptyState}>Aucune prestation renseignée pour cette firme.</div>
                  ) : (
                    <div className={styles.prestaList}>
                      {offerings.map((o) => {
                        const forfait = forfaitFor(o.interventionType.id);
                        return (
                          <div key={o.id} className={styles.prestaCard} onClick={() => setDetailTargetId(o.id)}>
                            <div className={styles.prestaCardTop}>
                              <div className={styles.prestaCardInfo}>
                                <div className={styles.prestaCardNameRow}>
                                  <span className={styles.prestaCardType}>{o.interventionType.label}</span>
                                  <span className={styles.prestaCardCode}>{o.interventionType.code}</span>
                                  {!o.active && <StatusPill active={false} />}
                                  {o.representativePresenceRelevant && <Tag>Délégué</Tag>}
                                </div>
                                <div className={styles.prestaCardMeta}>
                                  {offerings.length ? `${o.suggestedMaterials.length} matériel${o.suggestedMaterials.length !== 1 ? "s" : ""} suggéré${o.suggestedMaterials.length !== 1 ? "s" : ""} · ${representativeSummary(o)}` : ""}
                                </div>
                              </div>
                              <div className={styles.prestaCardForfait}>
                                <div className={styles.prestaCardForfaitValue}>{forfaitSummary(o, forfait)}</div>
                                <div className={styles.prestaCardForfaitLabel}>forfait</div>
                              </div>
                              <ChevronRightIcon size={16} className={styles.prestaCardChevron} />
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )
                )}

                {tab === "materials" && (
                  <>
                    <div className={styles.panelIntro} style={{ marginTop: 18 }}>
                      <p className={styles.panelIntroText}>Catalogue et tarifs du matériel de {selectedFirm.name}.</p>
                      <Btn onClick={() => setCreateMaterialOpen(true)}><PlusIcon size={16} /> Nouveau matériel</Btn>
                    </div>
                    {materialsQuery.isLoading || rulesQuery.isLoading ? (
                      <div style={{ display: "flex", justifyContent: "center", padding: 24 }}><CircularProgress size={20} /></div>
                    ) : materials.length === 0 ? (
                      <div className={styles.emptyState}>Aucun matériel renseigné pour cette firme.</div>
                    ) : (
                      <div className={styles.materialTable}>
                        <div className={[styles.materialRow, styles.materialRowHead].join(" ")}>
                          <span>MATÉRIEL</span><span>RÉFÉRENCE</span><span>TARIF</span><span />
                        </div>
                        {materials.map((m) => {
                          const itemRules = materialRulesFor(m.id);
                          const activeVersion = getActiveVersion(itemRules.map((r) => ({ id: r.id, amount: r.unitPrice, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo })));
                          return (
                            <div key={m.id} className={styles.materialRow}>
                              <span className={styles.materialRowName}>{m.label}</span>
                              <span className={styles.materialRowRef}>{m.referenceCode || "—"}</span>
                              <span className={[styles.materialRowPrice, activeVersion ? "" : styles.materialRowPriceMuted].filter(Boolean).join(" ")}>
                                {activeVersion ? `${Number(activeVersion.amount).toFixed(2)} ${activeVersion.currency} HTVA` : "Tarif à définir"}
                              </span>
                              <Tooltip title="Modifier le matériel">
                                <button type="button" aria-label={`Modifier ${m.label}`} className={[styles.btn, styles.btnOutline, styles.btnSm].join(" ")} onClick={() => setMaterialEditTargetId(m.id)}>
                                  <PrEditIcon size={14} /> Modifier
                                </button>
                              </Tooltip>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </>
                )}
              </>
            )}
          </div>
        </div>
      )}

      {selectedFirm && (
        <FirmLogoDialog open={logoDialogOpen} onClose={() => setLogoDialogOpen(false)} firm={selectedFirm} />
      )}

      {newInterventionOpen && (
        <NewInterventionModal
          onClose={() => setNewInterventionOpen(false)}
          onCreated={() => { setNewInterventionOpen(false); qc.invalidateQueries({ queryKey: ["intervention-types"] }); }}
        />
      )}
      {editInterventionTarget && (
        <EditInterventionModal intervention={editInterventionTarget} onClose={() => setEditInterventionTarget(null)} />
      )}

      {selectedFirmId !== null && (
        <>
          {addOfferingOpen && (
            <AddPrestationModal
              onClose={() => setAddOfferingOpen(false)}
              firmId={selectedFirmId}
              firmName={selectedFirm?.name ?? ""}
              existingTypeIds={offerings.map((o) => o.interventionType.id)}
              onCreated={() => qc.invalidateQueries({ queryKey: ["service-offerings", selectedFirmId] })}
            />
          )}
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
          {createMaterialOpen && (
            <NewMaterialModal
              onClose={() => setCreateMaterialOpen(false)}
              firmId={selectedFirmId}
              firmName={selectedFirm?.name ?? ""}
              onCreated={() => {
                toast.success("Matériel créé");
                qc.invalidateQueries({ queryKey: ["material-items-firm", selectedFirmId] });
                qc.invalidateQueries({ queryKey: ["material-items"] });
                setCreateMaterialOpen(false);
              }}
            />
          )}
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
