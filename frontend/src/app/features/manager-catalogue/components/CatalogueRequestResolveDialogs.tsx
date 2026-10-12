import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Autocomplete,
  Box,
  Button,
  Checkbox,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormControlLabel,
  FormLabel,
  Link,
  Radio,
  RadioGroup,
  Stack,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
} from "@mui/material";

import {
  getFirms,
  getMaterialItems,
  getMaterialRequestDuplicateCandidates,
} from "../api/catalogue.api";
import type {
  CatalogueRequestMissionDTO,
  FirmDTO,
  MaterialDuplicateCandidateDTO,
  MaterialRequestDTO,
  ResolveMaterialRequestBody,
} from "../api/catalogue.types";
import type {
  InterventionTypeRequestDTO,
  OfferingDecision,
  ResolveInterventionTypeRequestBody,
} from "../api/interventionTypeRequests.api";
import { getInterventionTypes } from "../../intervention-types/api/interventionTypes.api";
import { fetchFirmServiceOfferings } from "../../encoding/api/encoding.api";
import { CatalogueRequestContext } from "./CatalogueRequestComment";

/** Erreur API normalisée ({error:{code,message,...extra}}) — affichée telle quelle, jamais rejouée. */
export type CatalogueApiError = { code: string | null; message: string; extra: Record<string, unknown> };

export function toCatalogueApiError(err: unknown): CatalogueApiError {
  const data = (err as { response?: { data?: Record<string, unknown> } })?.response?.data ?? {};
  const error = (data.error ?? {}) as Record<string, unknown>;
  const { code, message, status: _status, violations: _violations, ...extra } = error;
  return {
    code: typeof code === "string" ? code : null,
    message: (typeof message === "string" && message) || (typeof data.message === "string" ? data.message : "") || "Erreur lors de la résolution.",
    extra,
  };
}

// ── Firme : existante (recherche) OU nouvelle (nom), jamais « aucune » ───────────

export type FirmDecision = { kind: "existing"; firmId: number | null } | { kind: "new"; name: string };

/** Préremplissage depuis la demande : firme du référentiel, nom libre reconnu, ou nouvelle firme à créer. */
export function initialFirmDecision(firm: { id: number } | null | undefined, proposedName: string | null | undefined, firms: FirmDTO[]): FirmDecision {
  if (firm) return { kind: "existing", firmId: firm.id };
  const name = (proposedName ?? "").trim();
  if (name === "") return { kind: "existing", firmId: null };
  const match = firms.find((f) => f.name.trim().toLowerCase() === name.toLowerCase());
  return match ? { kind: "existing", firmId: match.id } : { kind: "new", name };
}

export function firmDecisionComplete(d: FirmDecision): boolean {
  return d.kind === "existing" ? d.firmId != null : d.name.trim() !== "";
}

function FirmDecisionField({
  label,
  firms,
  value,
  onChange,
  proposal,
  disabled,
}: {
  label: string;
  firms: FirmDTO[];
  value: FirmDecision;
  onChange: (d: FirmDecision) => void;
  proposal: string | null;
  disabled?: boolean;
}) {
  const selected = value.kind === "existing" ? firms.find((f) => f.id === value.firmId) ?? null : null;
  return (
    <Stack spacing={0.75}>
      {value.kind === "existing" ? (
        <Autocomplete
          options={firms}
          value={selected}
          disabled={disabled}
          getOptionLabel={(f) => f.name}
          isOptionEqualToValue={(a, b) => a.id === b.id}
          onChange={(_, f) => onChange({ kind: "existing", firmId: f?.id ?? null })}
          renderInput={(params) => <TextField {...params} label={label} size="small" required />}
        />
      ) : (
        <TextField
          label={`${label} — nouvelle firme`}
          size="small"
          required
          disabled={disabled}
          value={value.name}
          onChange={(e) => onChange({ kind: "new", name: e.target.value })}
          helperText="La firme sera créée au moment de la résolution (vérification des doublons côté serveur)."
        />
      )}
      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
        {proposal ? (
          <Typography variant="caption" color="text.secondary">Indiquée par l'instrumentiste : {proposal}</Typography>
        ) : null}
        <Link
          component="button"
          type="button"
          variant="caption"
          disabled={disabled}
          onClick={() => onChange(value.kind === "existing" ? { kind: "new", name: proposal ?? "" } : { kind: "existing", firmId: null })}
        >
          {value.kind === "existing" ? "Créer une nouvelle firme" : "Choisir une firme existante"}
        </Link>
      </Stack>
    </Stack>
  );
}

/** Mission verrouillée : refus expliqué, réouverture proposée (jamais automatique). */
function LockedMissionAlert({ mission }: { mission: CatalogueRequestMissionDTO | null }) {
  if (!mission?.locked) return null;
  return (
    <Alert severity="warning">
      La mission #{mission.id} est {mission.status === "VALIDATED" ? "validée" : "verrouillée"} : rien ne peut y être ajouté directement.
      {mission.reopenAvailable
        ? " Rouvrez d'abord son encodage (Suivi des encodages › Rouvrir), puis résolvez la demande."
        : " Elle est facturée ou son calcul financier est verrouillé : la demande ne peut plus y être résolue (ignorez-la en expliquant pourquoi)."}
    </Alert>
  );
}

function formatQuantity(q: string | null | undefined): string {
  if (q == null || q === "") return "";
  return String(Number(q)).replace(".", ",");
}

function parseQuantity(raw: string): string | null {
  const normalized = raw.trim().replace(",", ".");
  if (!/^\d+(\.\d{1,2})?$/.test(normalized) || Number(normalized) <= 0) return null;
  return normalized;
}

function MaterialRequestContext({ request }: { request: MaterialRequestDTO }) {
  return (
    <Stack spacing={1}>
      <CatalogueRequestContext
        kind="material"
        label={request.label}
        reference={request.referenceCode}
        requestedBy={request.requestedBy}
        mission={request.mission}
        comment={request.comment}
        firm={request.proposedFirm?.name ?? (request.proposedFirmName ? `${request.proposedFirmName} (absente du catalogue)` : null)}
        quantity={request.quantity ? formatQuantity(request.quantity) : null}
        target={request.target ? `${request.target.label}${request.target.kind === "DRAFT" ? " (demande d'intervention en cours)" : ""}${request.target.firm ? ` · ${request.target.firm.name}` : ""}` : null}
      />
      <LockedMissionAlert mission={request.mission} />
    </Stack>
  );
}

function CandidateList({
  title,
  candidates,
  onPick,
  disabled,
}: {
  title: string;
  candidates: MaterialDuplicateCandidateDTO[];
  onPick: (c: MaterialDuplicateCandidateDTO) => void;
  disabled?: boolean;
}) {
  if (candidates.length === 0) return null;
  return (
    <Alert severity="info" icon={false}>
      <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>{title}</Typography>
      <Stack spacing={0.5}>
        {candidates.map((c) => (
          <Stack key={c.id} direction="row" spacing={1} alignItems="center" justifyContent="space-between">
            <Typography variant="body2">
              {c.label}
              <Box component="span" sx={{ color: "text.secondary" }}>
                {c.referenceCode ? ` · ${c.referenceCode}` : ""} · {c.firm?.name ?? "—"}
              </Box>
            </Typography>
            <Button size="small" onClick={() => onPick(c)} disabled={disabled}>Associer celui-ci</Button>
          </Stack>
        ))}
      </Stack>
    </Alert>
  );
}

// ── Résolution d'une demande de matériel (une seule requête atomique) ──────────────

export function ResolveMaterialDialog({
  open,
  request,
  onClose,
  onResolve,
  resolving,
  error,
}: {
  open: boolean;
  request: MaterialRequestDTO | null;
  onClose: () => void;
  onResolve: (body: ResolveMaterialRequestBody) => void;
  resolving: boolean;
  error: CatalogueApiError | null;
}) {
  const [mode, setMode] = React.useState<"existing" | "create">("create");
  const [quantity, setQuantity] = React.useState("");
  const [search, setSearch] = React.useState("");
  const [selectedItem, setSelectedItem] = React.useState<{ id: number; label: string; firm: { name: string } | null } | null>(null);
  const [label, setLabel] = React.useState("");
  const [referenceCode, setReferenceCode] = React.useState("");
  const [unit, setUnit] = React.useState("pièce");
  const [isImplant, setIsImplant] = React.useState(false);
  const [firm, setFirm] = React.useState<FirmDecision>({ kind: "existing", firmId: null });

  const firmsQuery = useQuery({ queryKey: ["firms"], queryFn: getFirms, enabled: open });
  const candidatesQuery = useQuery({
    queryKey: ["material-request-duplicates", request?.id],
    queryFn: () => getMaterialRequestDuplicateCandidates(request!.id),
    enabled: open && request != null,
  });
  const itemsQuery = useQuery({
    queryKey: ["material-items", "resolve-search", search],
    queryFn: () => getMaterialItems({ search: search || undefined, limit: 50 }),
    enabled: open && mode === "existing",
  });
  const firms = firmsQuery.data ?? [];

  const initialized = React.useRef<number | null>(null);
  React.useEffect(() => {
    if (!open) {
      initialized.current = null;
      return;
    }
    if (!request || initialized.current === request.id || !firmsQuery.data) return;
    initialized.current = request.id;
    setMode("create");
    setQuantity(formatQuantity(request.quantity));
    setSearch("");
    setSelectedItem(null);
    setLabel(request.label);
    setReferenceCode(request.referenceCode ?? "");
    setUnit("pièce");
    setIsImplant(false);
    setFirm(initialFirmDecision(request.proposedFirm, request.proposedFirmName, firmsQuery.data));
  }, [open, request, firmsQuery.data]);

  // FIRM_ALREADY_EXISTS : la firme saisie existe déjà — on bascule sur elle (jamais un doublon).
  React.useEffect(() => {
    const existing = error?.code === "FIRM_ALREADY_EXISTS" ? (error.extra.existingFirm as { id?: number } | undefined) : undefined;
    if (existing?.id) setFirm({ kind: "existing", firmId: existing.id });
  }, [error]);

  if (!request) return null;

  const parsedQuantity = parseQuantity(quantity);
  const locked = !!request.mission?.locked;
  const canSubmit = !resolving && !locked && parsedQuantity != null && (
    mode === "existing" ? selectedItem != null : label.trim() !== "" && unit.trim() !== "" && firmDecisionComplete(firm)
  );

  const pickCandidate = (c: { id: number; label: string; firm: { name: string } | null }) => {
    setMode("existing");
    setSelectedItem({ id: c.id, label: c.label, firm: c.firm });
  };

  const submit = (confirmPossibleDuplicate = false) => {
    if (!canSubmit || parsedQuantity == null) return;
    if (mode === "existing" && selectedItem) {
      onResolve({ quantity: parsedQuantity, materialItemId: selectedItem.id });
      return;
    }
    onResolve({
      quantity: parsedQuantity,
      newItem: {
        label: label.trim(),
        unit: unit.trim(),
        referenceCode: referenceCode.trim() || undefined,
        isImplant,
        ...(firm.kind === "existing" ? { firmId: firm.firmId! } : { newFirmName: firm.name.trim() }),
      },
      ...(confirmPossibleDuplicate ? { confirmPossibleDuplicate: true } : {}),
    });
  };

  const possibleDuplicates = error?.code === "MATERIAL_ITEM_POSSIBLE_DUPLICATE" ? ((error.extra.candidates as MaterialDuplicateCandidateDTO[]) ?? []) : [];
  const certainDuplicate = error?.code === "MATERIAL_ITEM_DUPLICATE" ? (error.extra.existingMaterialItem as MaterialDuplicateCandidateDTO | undefined) : undefined;

  return (
    <Dialog open={open} onClose={resolving ? undefined : onClose} fullWidth maxWidth="sm">
      <DialogTitle>Résoudre la demande de matériel</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <MaterialRequestContext request={request} />

          {error ? <Alert severity="error">{error.message}</Alert> : null}
          {certainDuplicate ? (
            <CandidateList title="Matériel existant correspondant" candidates={[certainDuplicate]} onPick={pickCandidate} disabled={resolving} />
          ) : null}
          {possibleDuplicates.length > 0 ? (
            <Alert
              severity="warning"
              action={<Button color="inherit" size="small" onClick={() => submit(true)} disabled={resolving}>Créer quand même</Button>}
            >
              Un matériel semblable existe déjà. Associez-le, ou confirmez la création d'une nouvelle référence.
            </Alert>
          ) : null}
          <CandidateList
            title={possibleDuplicates.length > 0 ? "Matériels semblables" : "Matériels existants qui ressemblent à la demande"}
            candidates={possibleDuplicates.length > 0 ? possibleDuplicates : (candidatesQuery.data ?? [])}
            onPick={pickCandidate}
            disabled={resolving || locked}
          />

          <TextField
            label="Quantité utilisée"
            size="small"
            required
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            error={quantity.trim() !== "" && parsedQuantity == null}
            helperText={request.quantity ? `Déclarée : ${formatQuantity(request.quantity)}` : "Demande antérieure sans quantité : indiquez la quantité réellement utilisée."}
            inputProps={{ inputMode: "decimal" }}
          />

          <ToggleButtonGroup exclusive size="small" value={mode} onChange={(_, v) => v && setMode(v)}>
            <ToggleButton value="create">Créer une nouvelle référence</ToggleButton>
            <ToggleButton value="existing">Associer un matériel existant</ToggleButton>
          </ToggleButtonGroup>

          {mode === "existing" ? (
            <Stack spacing={1}>
              <Autocomplete
                options={(itemsQuery.data?.items ?? []).map((i) => ({ id: i.id, label: i.label, ref: i.referenceCode, firm: i.firm }))}
                value={selectedItem ? { id: selectedItem.id, label: selectedItem.label, ref: "", firm: selectedItem.firm as FirmDTO | null } : null}
                filterOptions={(x) => x}
                loading={itemsQuery.isLoading}
                onInputChange={(_, v, reason) => { if (reason === "input") setSearch(v); }}
                getOptionLabel={(o) => `${o.label}${o.ref ? ` · ${o.ref}` : ""} · ${o.firm?.name ?? "—"}`}
                isOptionEqualToValue={(a, b) => a.id === b.id}
                onChange={(_, o) => setSelectedItem(o ? { id: o.id, label: o.label, firm: o.firm } : null)}
                renderInput={(params) => <TextField {...params} label="Rechercher un matériel (nom ou référence)" size="small" />}
              />
              {selectedItem ? (
                <Typography variant="caption" color="text.secondary">
                  Le matériel garde sa firme ({selectedItem.firm?.name ?? "—"}) et ses règles de facturation existantes.
                </Typography>
              ) : null}
            </Stack>
          ) : (
            <Stack spacing={1.5}>
              <TextField label="Nom du matériel" size="small" required value={label} onChange={(e) => setLabel(e.target.value)} />
              <TextField label="Référence" size="small" value={referenceCode} onChange={(e) => setReferenceCode(e.target.value)} />
              <FirmDecisionField
                label="Firme du matériel"
                firms={firms}
                value={firm}
                onChange={setFirm}
                proposal={request.proposedFirm?.name ?? request.proposedFirmName ?? null}
                disabled={resolving}
              />
              <Stack direction="row" spacing={2} alignItems="center">
                <TextField label="Unité" size="small" required value={unit} onChange={(e) => setUnit(e.target.value)} sx={{ maxWidth: 160 }} />
                <FormControlLabel control={<Checkbox checked={isImplant} onChange={(e) => setIsImplant(e.target.checked)} />} label="Implant" />
              </Stack>
              <Typography variant="caption" color="text.secondary">
                Le nouveau matériel est créé « à décider » (aucun tarif) : sa facturation se décide ensuite dans Catalogue › Matériel à régulariser.
              </Typography>
            </Stack>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={resolving}>Annuler</Button>
        <Button variant="contained" disabled={!canSubmit} onClick={() => submit(false)}>
          {resolving ? <CircularProgress size={18} /> : mode === "existing" ? "Associer et résoudre" : "Créer et résoudre"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Résolution d'une demande d'intervention (une seule requête atomique) ─────────

const OFFERING_DECISION_LABELS: Record<OfferingDecision, string> = {
  FEE_APPLICABLE: "Un forfait firme est applicable (montant à configurer ensuite dans Catalogue › Prestations)",
  NO_FEE: "Aucun forfait n'est prévu pour cette firme et cette intervention",
  LEAVE_UNCONFIGURED: "Ne rien décider maintenant (prestation à configurer)",
};

export function ResolveInterventionDialog({
  open,
  request,
  onClose,
  onResolve,
  resolving,
  error,
}: {
  open: boolean;
  request: InterventionTypeRequestDTO | null;
  onClose: () => void;
  onResolve: (body: ResolveInterventionTypeRequestBody) => void;
  resolving: boolean;
  error: CatalogueApiError | null;
}) {
  const [typeMode, setTypeMode] = React.useState<"create" | "existing">("existing");
  const [typeId, setTypeId] = React.useState<number | null>(null);
  const [newCode, setNewCode] = React.useState("");
  const [newLabel, setNewLabel] = React.useState("");
  const [firm, setFirm] = React.useState<FirmDecision>({ kind: "existing", firmId: null });
  const [offeringDecision, setOfferingDecision] = React.useState<OfferingDecision | null>(null);
  const [representativePresent, setRepresentativePresent] = React.useState<boolean | null>(null);
  const [choiceId, setChoiceId] = React.useState<number | null>(null);

  const firmsQuery = useQuery({ queryKey: ["firms"], queryFn: getFirms, enabled: open });
  const typesQuery = useQuery({ queryKey: ["intervention-types", "active"], queryFn: () => getInterventionTypes(true), enabled: open });
  const existingFirmId = firm.kind === "existing" ? firm.firmId : null;
  const offeringsQuery = useQuery({
    queryKey: ["firmServiceOfferings", existingFirmId],
    queryFn: () => fetchFirmServiceOfferings(existingFirmId as number),
    enabled: open && existingFirmId != null,
  });

  const initialized = React.useRef<number | null>(null);
  React.useEffect(() => {
    if (!open) {
      initialized.current = null;
      return;
    }
    if (!request || initialized.current === request.id || !firmsQuery.data) return;
    initialized.current = request.id;
    setTypeMode("existing");
    setTypeId(null);
    setNewCode(request.suggestedCode ?? "");
    setNewLabel(request.label);
    // Firme connue de la demande : préremplie, jamais remise à zéro.
    setFirm(initialFirmDecision(request.requestedFirm, request.proposedFirmName ?? request.requestedFirmNameSnapshot, firmsQuery.data));
    setOfferingDecision(null);
    setRepresentativePresent(null);
    setChoiceId(null);
  }, [open, request, firmsQuery.data]);

  React.useEffect(() => {
    setRepresentativePresent(null);
    setChoiceId(null);
    setOfferingDecision(null);
  }, [existingFirmId, typeId, typeMode, firm.kind]);

  React.useEffect(() => {
    const existing = error?.code === "FIRM_ALREADY_EXISTS" ? (error.extra.existingFirm as { id?: number } | undefined) : undefined;
    if (existing?.id) setFirm({ kind: "existing", firmId: existing.id });
    const existingType = error?.code === "INTERVENTION_TYPE_CODE_EXISTS" ? (error.extra.existingInterventionType as { id?: number } | undefined) : undefined;
    if (existingType?.id) {
      setTypeMode("existing");
      setTypeId(existingType.id);
    }
  }, [error]);

  if (!request) return null;

  const firms = firmsQuery.data ?? [];
  const types = typesQuery.data ?? [];
  const locked = !!request.mission?.locked;

  // La prestation firme × intervention existe-t-elle ? (lecture du catalogue, jamais déduite)
  const offering = existingFirmId != null && typeMode === "existing" && typeId != null
    ? (offeringsQuery.data ?? []).find((o) => o.interventionType.id === typeId) ?? null
    : null;
  const offeringKnown = existingFirmId == null || typeMode === "create" || typeId == null || offeringsQuery.isSuccess;
  const offeringMissing = firmDecisionComplete(firm) && (typeMode === "create" ? newCode.trim() !== "" : typeId != null) && offeringKnown && offering == null;
  const representativeRequired = !!offering?.representativePresenceRelevant;
  const choiceGroup = offering?.choiceGroup ?? null;
  const firmName = firm.kind === "existing" ? firms.find((f) => f.id === firm.firmId)?.name ?? "" : firm.name.trim();

  const typeComplete = typeMode === "existing" ? typeId != null : newCode.trim() !== "" && newLabel.trim() !== "";
  const canSubmit = !resolving && !locked && typeComplete && firmDecisionComplete(firm) && offeringKnown
    && (!offeringMissing || offeringDecision != null)
    && (!representativeRequired || representativePresent != null)
    && (choiceGroup == null || choiceId != null);

  const submit = () => {
    if (!canSubmit) return;
    onResolve({
      ...(typeMode === "existing" ? { interventionTypeId: typeId! } : { newInterventionType: { code: newCode.trim(), label: newLabel.trim() } }),
      ...(firm.kind === "existing" ? { firmId: firm.firmId! } : { newFirmName: firm.name.trim() }),
      ...(offeringMissing && offeringDecision ? { offeringDecision } : {}),
      ...(representativeRequired && representativePresent != null ? { representativePresent } : {}),
      ...(choiceGroup && choiceId != null ? { selectedChoiceOptionId: choiceId } : {}),
    });
  };

  return (
    <Dialog open={open} onClose={resolving ? undefined : onClose} fullWidth maxWidth="sm">
      <DialogTitle>Résoudre la demande d'intervention</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <CatalogueRequestContext
            kind="intervention"
            label={request.label}
            reference={request.suggestedCode}
            requestedBy={request.requestedBy}
            mission={request.mission}
            comment={request.comment}
            firm={request.requestedFirm?.name ?? (request.proposedFirmName ? `${request.proposedFirmName} (absente du catalogue)` : request.requestedFirmNameSnapshot ?? null)}
          />
          <LockedMissionAlert mission={request.mission} />
          {error ? <Alert severity="error">{error.message}</Alert> : null}

          <FirmDecisionField
            label="Avec quelle firme cette intervention a-t-elle été réalisée ?"
            firms={firms}
            value={firm}
            onChange={setFirm}
            proposal={request.requestedFirm?.name ?? request.proposedFirmName ?? request.requestedFirmNameSnapshot ?? null}
            disabled={resolving}
          />
          <Typography variant="caption" color="text.secondary">
            La firme validée s'applique uniquement à cette nouvelle intervention, jamais aux autres interventions de la mission.
          </Typography>

          <ToggleButtonGroup exclusive size="small" value={typeMode} onChange={(_, v) => v && setTypeMode(v)}>
            <ToggleButton value="existing">Intervention existante</ToggleButton>
            <ToggleButton value="create">Créer une nouvelle intervention</ToggleButton>
          </ToggleButtonGroup>

          {typeMode === "existing" ? (
            <Autocomplete
              options={types}
              value={types.find((t) => t.id === typeId) ?? null}
              loading={typesQuery.isLoading}
              getOptionLabel={(t) => `${t.label} (${t.code})`}
              isOptionEqualToValue={(a, b) => a.id === b.id}
              onChange={(_, t) => setTypeId(t?.id ?? null)}
              renderInput={(params) => <TextField {...params} label="Intervention correspondante" size="small" required />}
            />
          ) : (
            <Stack direction="row" spacing={1.5}>
              <TextField label="Code" size="small" required value={newCode} onChange={(e) => setNewCode(e.target.value.toUpperCase())} sx={{ maxWidth: 180 }} inputProps={{ style: { fontFamily: "monospace", fontWeight: 700 } }} />
              <TextField label="Libellé" size="small" required fullWidth value={newLabel} onChange={(e) => setNewLabel(e.target.value)} />
            </Stack>
          )}

          {offeringMissing ? (
            <FormControl>
              <FormLabel sx={{ fontSize: 14, fontWeight: 600 }}>
                La prestation « {typeMode === "existing" ? types.find((t) => t.id === typeId)?.label : newLabel.trim()} » n'est pas encore configurée chez {firmName || "cette firme"}. Que décidez-vous ?
              </FormLabel>
              <RadioGroup value={offeringDecision ?? ""} onChange={(e) => setOfferingDecision(e.target.value as OfferingDecision)}>
                {(Object.keys(OFFERING_DECISION_LABELS) as OfferingDecision[]).map((d) => (
                  <FormControlLabel key={d} value={d} control={<Radio size="small" />} label={<Typography variant="body2">{OFFERING_DECISION_LABELS[d]}</Typography>} />
                ))}
              </RadioGroup>
            </FormControl>
          ) : null}

          {representativeRequired ? (
            <FormControl>
              <FormLabel sx={{ fontSize: 14, fontWeight: 600 }}>Un délégué de {firmName} était-il présent ?</FormLabel>
              <RadioGroup row value={representativePresent == null ? "" : String(representativePresent)} onChange={(e) => setRepresentativePresent(e.target.value === "true")}>
                <FormControlLabel value="true" control={<Radio size="small" />} label="Oui" />
                <FormControlLabel value="false" control={<Radio size="small" />} label="Non" />
              </RadioGroup>
              <Typography variant="caption" color="text.secondary">Réponse obligatoire pour cette prestation — à vérifier auprès de l'instrumentiste si besoin.</Typography>
            </FormControl>
          ) : null}

          {choiceGroup ? (
            <FormControl>
              <FormLabel sx={{ fontSize: 14, fontWeight: 600 }}>{choiceGroup.question}</FormLabel>
              <RadioGroup value={choiceId == null ? "" : String(choiceId)} onChange={(e) => setChoiceId(Number(e.target.value))}>
                {choiceGroup.options.filter((o) => o.active).map((o) => (
                  <FormControlLabel key={o.id} value={String(o.id)} control={<Radio size="small" />} label={o.label} />
                ))}
              </RadioGroup>
            </FormControl>
          ) : null}

          {request.draft && request.draft.materialLineCount + request.draft.materialItemRequestCount > 0 ? (
            <Typography variant="caption" color="text.secondary">
              Le matériel déjà déclaré sur cette demande ({request.draft.materialLineCount} ligne(s)) sera rattaché à l'intervention créée, chacun avec sa propre firme.
            </Typography>
          ) : null}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={resolving}>Annuler</Button>
        <Button variant="contained" disabled={!canSubmit} onClick={submit}>
          {resolving ? <CircularProgress size={18} /> : "Résoudre"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
