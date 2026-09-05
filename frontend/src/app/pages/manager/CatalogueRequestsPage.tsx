import * as React from "react";
import { useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Paper,
  Select,
  Stack,
  Tab,
  TextField,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Tabs,
  Typography,
} from "@mui/material";

import {
  createMaterialItem,
  getFirms,
  getMaterialItems,
  getMaterialRequests,
  ignoreMaterialRequest,
  resolveMaterialRequest,
} from "../../features/manager-catalogue/api/catalogue.api";
import type {
  CatalogueRequestIgnoreReason,
  MaterialItemDTO,
  MaterialRequestDTO,
  MaterialRequestStatus,
} from "../../features/manager-catalogue/api/catalogue.types";
import {
  getInterventionTypeRequests,
  ignoreInterventionTypeRequest,
  resolveInterventionTypeRequest,
  type InterventionTypeRequestDTO,
} from "../../features/manager-catalogue/api/interventionTypeRequests.api";
import {
  createInterventionType,
  getInterventionTypes,
  type InterventionType,
} from "../../features/intervention-types/api/interventionTypes.api";
import {
  MaterialItemFormDialog,
  type MaterialItemFormValues,
} from "../../features/manager-catalogue/components/MaterialItemFormDialog";
import { useToast } from "../../ui/toast/useToast";
import { PageHeader } from "../../ui/PageHeader";
import { EmptyState } from "../../ui/EmptyState";

type TabValue = "PENDING" | "RESOLVED" | "IGNORED";

/** Ligne unifiée du tableau — matériel ou intervention, distinguées par `kind`. */
type UnifiedRequest =
  | { kind: "material"; request: MaterialRequestDTO }
  | { kind: "intervention"; request: InterventionTypeRequestDTO };

// ── Dialog résolution matériel (inchangé, extrait tel quel de l'ancienne page) ──
function ResolveMaterialDialog({
  open,
  request,
  onClose,
  onResolve,
  resolving,
}: {
  open: boolean;
  request: MaterialRequestDTO | null;
  onClose: () => void;
  onResolve: (materialItemId: number) => void;
  resolving: boolean;
}) {
  const [mode, setMode] = React.useState<"pick" | "create">("create");
  const [createError, setCreateError] = React.useState<string | null>(null);
  const queryClient = useQueryClient();

  const createMutation = useMutation({
    mutationFn: createMaterialItem,
    onSuccess: (item) => {
      queryClient.invalidateQueries({ queryKey: ["material-items"] });
      onResolve(item.id);
    },
    onError: (err: any) => {
      setCreateError(
        err?.response?.data?.message ?? "Erreur lors de la création.",
      );
    },
  });

  const itemsQuery = useQuery({
    queryKey: ["material-items", ""],
    queryFn: () => getMaterialItems({ limit: 200 }),
    enabled: mode === "pick",
  });

  const [selectedItemId, setSelectedItemId] = React.useState<number | null>(
    null,
  );

  React.useEffect(() => {
    if (open) {
      setMode("create");
      setCreateError(null);
      setSelectedItemId(null);
    }
  }, [open]);

  if (!request) return null;

  const preFilledInitial: Partial<MaterialItemFormValues> = {
    label: request.label,
    referenceCode: request.referenceCode ?? "",
  };

  const items: MaterialItemDTO[] = itemsQuery.data?.items ?? [];
  return (
    <>
      <MaterialItemFormDialog
        open={open && mode === "create"}
        title="Créer un produit depuis la demande"
        initial={preFilledInitial}
        submitLabel="Créer et résoudre"
        loading={createMutation.isPending}
        error={createError}
        headerExtra={
          <Button
            size="small"
            variant="text"
            onClick={() => setMode("pick")}
            sx={{ alignSelf: "flex-start" }}
          >
            Plutôt associer un produit existant →
          </Button>
        }
        onClose={onClose}
        onSubmit={(values) => {
          if (!values.firmId) return;
          createMutation.mutate({
            firmId: values.firmId,
            label: values.label,
            unit: values.unit,
            referenceCode: values.referenceCode || undefined,
            isImplant: values.isImplant,
          });
        }}
      />

      <Dialog open={open && mode === "pick"} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>Associer un produit existant</DialogTitle>
        <DialogContent>
          <Stack spacing={1.5} sx={{ pt: 0.5 }}>
            <Button
              size="small"
              variant="text"
              onClick={() => setMode("create")}
              sx={{ alignSelf: "flex-start" }}
            >
              ← Créer un nouveau produit
            </Button>

            {itemsQuery.isLoading ? (
              <Box sx={{ display: "flex", justifyContent: "center", py: 2 }}>
                <CircularProgress size={24} />
              </Box>
            ) : (
              <TableContainer component={Paper} variant="outlined" sx={{ maxHeight: 360 }}>
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell>Nom</TableCell>
                      <TableCell>Firme</TableCell>
                      <TableCell>Référence</TableCell>
                      <TableCell />
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {items.map((item) => (
                      <TableRow
                        key={item.id}
                        hover
                        selected={selectedItemId === item.id}
                        onClick={() => setSelectedItemId(item.id)}
                        sx={{ cursor: "pointer" }}
                      >
                        <TableCell>{item.label}</TableCell>
                        <TableCell>{item.firm?.name ?? "—"}</TableCell>
                        <TableCell>{item.referenceCode || "—"}</TableCell>
                        <TableCell>
                          {selectedItemId === item.id ? (
                            <Chip label="Sélectionné" size="small" color="primary" />
                          ) : null}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={resolving}>Annuler</Button>
          <Button
            variant="contained"
            disabled={selectedItemId === null || resolving}
            onClick={() => {
              if (selectedItemId !== null) onResolve(selectedItemId);
            }}
          >
            {resolving ? <CircularProgress size={18} /> : "Associer et résoudre"}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  );
}

// ── Dialog résolution intervention (nouveau, calqué sur le pattern matériel) ──
function ResolveInterventionDialog({
  open,
  request,
  onClose,
  onResolve,
  resolving,
}: {
  open: boolean;
  request: InterventionTypeRequestDTO | null;
  onClose: () => void;
  onResolve: (interventionTypeId: number, primaryFirmId?: number) => void;
  resolving: boolean;
}) {
  const [mode, setMode] = React.useState<"pick" | "create">("create");
  const [newCode, setNewCode] = React.useState("");
  const [newLabel, setNewLabel] = React.useState("");
  const [selectedTypeId, setSelectedTypeId] = React.useState<number | "">("");
  const [primaryFirmId, setPrimaryFirmId] = React.useState<number | "">("");
  const queryClient = useQueryClient();

  const typesQuery = useQuery({
    queryKey: ["intervention-types", "active"],
    queryFn: () => getInterventionTypes(true),
    enabled: open && mode === "pick",
  });
  const firmsQuery = useQuery({ queryKey: ["firms"], queryFn: getFirms, enabled: open });

  const createTypeMutation = useMutation({
    mutationFn: createInterventionType,
    onSuccess: (created: InterventionType) => {
      queryClient.invalidateQueries({ queryKey: ["intervention-types"] });
      onResolve(created.id, primaryFirmId === "" ? undefined : primaryFirmId);
    },
  });

  React.useEffect(() => {
    if (open) {
      setMode("create");
      setNewCode(request?.suggestedCode ?? "");
      setNewLabel(request?.label ?? "");
      setSelectedTypeId("");
      setPrimaryFirmId("");
    }
  }, [open, request]);

  if (!request) return null;
  const types = typesQuery.data ?? [];
  const firms = firmsQuery.data ?? [];

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Résoudre la demande de type d'intervention</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <Typography variant="body2" color="text.secondary">
            « {request.label} »{request.comment ? ` — ${request.comment}` : ""}
          </Typography>

          {mode === "create" ? (
            <>
              <TextField
                label="Code *"
                size="small"
                value={newCode}
                onChange={(e) => setNewCode(e.target.value.toUpperCase())}
                inputProps={{ style: { fontFamily: "monospace", fontWeight: 700 } }}
              />
              <TextField
                label="Libellé *"
                size="small"
                value={newLabel}
                onChange={(e) => setNewLabel(e.target.value)}
              />
              <Button size="small" onClick={() => setMode("pick")} sx={{ alignSelf: "flex-start" }}>
                Plutôt associer un type existant →
              </Button>
            </>
          ) : (
            <>
              <Select
                fullWidth size="small" displayEmpty
                value={selectedTypeId}
                onChange={(e) => setSelectedTypeId(Number(e.target.value))}
              >
                <MenuItem value="" disabled>
                  {typesQuery.isLoading ? "Chargement…" : "Sélectionner un type d'intervention"}
                </MenuItem>
                {types.map((t) => (
                  <MenuItem key={t.id} value={t.id}>{t.label} ({t.code})</MenuItem>
                ))}
              </Select>
              <Button size="small" onClick={() => setMode("create")} sx={{ alignSelf: "flex-start" }}>
                ← Créer un nouveau type
              </Button>
            </>
          )}

          <Select
            fullWidth size="small" displayEmpty
            value={primaryFirmId}
            onChange={(e) => setPrimaryFirmId(String(e.target.value) === "" ? "" : Number(e.target.value))}
          >
            <MenuItem value="">Aucune firme principale (optionnel)</MenuItem>
            {firms.map((f) => (
              <MenuItem key={f.id} value={f.id}>{f.name}</MenuItem>
            ))}
          </Select>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={resolving || createTypeMutation.isPending}>Annuler</Button>
        <Button
          variant="contained"
          disabled={
            resolving || createTypeMutation.isPending ||
            (mode === "create" ? (!newCode.trim() || !newLabel.trim()) : !selectedTypeId)
          }
          onClick={() => {
            if (mode === "create") {
              createTypeMutation.mutate({ code: newCode.trim(), label: newLabel.trim() });
            } else if (selectedTypeId !== "") {
              onResolve(selectedTypeId, primaryFirmId === "" ? undefined : primaryFirmId);
            }
          }}
        >
          {resolving || createTypeMutation.isPending ? <CircularProgress size={18} /> : "Résoudre"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

function statusLabel(status: MaterialRequestStatus): string {
  switch (status) {
    case "PENDING": return "En attente";
    case "RESOLVED": return "Résolu";
    case "IGNORED": return "Ignoré";
  }
}

function statusColor(status: MaterialRequestStatus): "warning" | "success" | "default" {
  switch (status) {
    case "PENDING": return "warning";
    case "RESOLVED": return "success";
    case "IGNORED": return "default";
  }
}

/**
 * Correctif workflow Demandes Catalogue (D-113) — wording FR local à l'affichage
 * (historique Résolues/Ignorées, Select de la modal), miroir de
 * CatalogueRequestIgnoreReason::label() côté backend. Le backend, lui, ne transporte
 * jamais ce libellé (CatalogueRequestProcessedMessage porte le code brut) — voir
 * docs/decisions.md D-113.
 */
const IGNORE_REASON_LABELS: Record<CatalogueRequestIgnoreReason, string> = {
  ALREADY_EXISTS: "Intervention déjà existante",
  MATERIAL_ALREADY_EXISTS: "Matériel déjà existant",
  DUPLICATE: "Demande en doublon",
  INVALID_REQUEST: "Demande incorrecte",
  OTHER: "Autre",
};

function ignoreReasonsFor(kind: "material" | "intervention"): CatalogueRequestIgnoreReason[] {
  const alreadyExists: CatalogueRequestIgnoreReason = kind === "material" ? "MATERIAL_ALREADY_EXISTS" : "ALREADY_EXISTS";
  return [alreadyExists, "DUPLICATE", "INVALID_REQUEST", "OTHER"];
}

/**
 * Modal de confirmation "Ignorer" (D-113) — motif structuré + explication toujours
 * obligatoires (jamais seulement pour OTHER), partagée matériel/intervention (même
 * forme des deux côtés backend). Bouton de validation désactivé tant que les deux champs
 * ne sont pas renseignés. Sur échec : la modal reste ouverte avec les valeurs saisies,
 * l'erreur s'affiche en ligne — jamais un simple toast qui laisserait croire à un succès.
 */
function IgnoreCatalogueRequestDialog({
  open,
  kind,
  label,
  onClose,
  onConfirm,
  submitting,
  error,
}: {
  open: boolean;
  kind: "material" | "intervention" | null;
  label: string;
  onClose: () => void;
  onConfirm: (reason: CatalogueRequestIgnoreReason, comment: string) => void;
  submitting: boolean;
  error: string | null;
}) {
  const [reason, setReason] = React.useState<CatalogueRequestIgnoreReason | "">("");
  const [comment, setComment] = React.useState("");

  React.useEffect(() => {
    if (open) {
      setReason("");
      setComment("");
    }
  }, [open]);

  if (!kind) return null;
  const reasons = ignoreReasonsFor(kind);
  const canSubmit = reason !== "" && comment.trim() !== "";

  return (
    <Dialog open={open} onClose={submitting ? undefined : onClose} fullWidth maxWidth="sm">
      <DialogTitle>Ignorer cette demande ?</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {error ? <Alert severity="error">{error}</Alert> : null}
          <Typography variant="body2" color="text.secondary">« {label} »</Typography>

          <Select
            fullWidth
            size="small"
            displayEmpty
            value={reason}
            onChange={(e) => setReason(e.target.value as CatalogueRequestIgnoreReason)}
          >
            <MenuItem value="" disabled>Motif</MenuItem>
            {reasons.map((r) => (
              <MenuItem key={r} value={r}>{IGNORE_REASON_LABELS[r]}</MenuItem>
            ))}
          </Select>

          <TextField
            label="Explication"
            placeholder="Ex. Intervention déjà existante sous le nom « Prothèse totale de hanche »."
            multiline
            minRows={3}
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={submitting}>Annuler</Button>
        <Button
          variant="contained"
          color="error"
          disabled={!canSubmit || submitting}
          onClick={() => {
            if (reason !== "") onConfirm(reason, comment.trim());
          }}
        >
          {submitting ? <CircularProgress size={18} /> : "Ignorer la demande"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

/** Deep-link backend ("MATERIAL_ITEM"/"INTERVENTION_TYPE") → kind frontend (UnifiedRequest). */
const DEEP_LINK_KIND: Record<string, "material" | "intervention"> = {
  MATERIAL_ITEM: "material",
  INTERVENTION_TYPE: "intervention",
};

/**
 * Demandes matériel + demandes de type d'intervention réunies (D-079) — un
 * seul tableau, chaque ligne taguée par nature, mêmes onglets de statut
 * qu'avant. Le backend des demandes d'intervention
 * (`InterventionTypeRequestManagerController`) existait déjà depuis D-068
 * sans jamais avoir eu d'écran manager.
 */
export default function CatalogueRequestsPage() {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [searchParams] = useSearchParams();

  // Correctif workflow Demandes Catalogue (D-113) — deep-link identifié par le couple
  // (kind, requestId), jamais requestId seul : MaterialItemRequest et
  // InterventionTypeRequest ont des espaces d'ID indépendants et peuvent partager le
  // même id. Résolu une seule fois au montage (pas de useMemo qui suivrait
  // searchParams — une navigation ultérieure vers la même page ne doit pas rouvrir un
  // vieux surlignage).
  const [deepLinkTarget] = React.useState<{ kind: "material" | "intervention"; id: number } | null>(() => {
    const kindParam = searchParams.get("kind");
    const requestIdParam = searchParams.get("requestId");
    const kind = kindParam ? DEEP_LINK_KIND[kindParam] : undefined;
    const id = requestIdParam ? Number(requestIdParam) : NaN;
    return kind && Number.isFinite(id) ? { kind, id } : null;
  });
  const [highlightKey, setHighlightKey] = React.useState<string | null>(
    deepLinkTarget ? `${deepLinkTarget.kind}-${deepLinkTarget.id}` : null,
  );
  const rowRefs = React.useRef(new Map<string, HTMLTableRowElement>());

  // Un deep-link force l'onglet PENDING (comportement déjà par défaut) — explicite ici
  // pour documenter l'intention plutôt que de dépendre d'une coïncidence.
  const [tab, setTab] = React.useState<TabValue>("PENDING");
  const [resolveMaterialTarget, setResolveMaterialTarget] = React.useState<MaterialRequestDTO | null>(null);
  const [resolveInterventionTarget, setResolveInterventionTarget] = React.useState<InterventionTypeRequestDTO | null>(null);
  const [ignoreTarget, setIgnoreTarget] = React.useState<{ kind: "material" | "intervention"; id: number; label: string } | null>(null);
  const [ignoreError, setIgnoreError] = React.useState<string | null>(null);

  const materialQuery = useQuery({
    queryKey: ["material-requests", tab],
    queryFn: () => getMaterialRequests({ status: tab }),
  });
  const interventionQuery = useQuery({
    queryKey: ["intervention-type-requests", tab],
    queryFn: () => getInterventionTypeRequests({ status: tab }),
  });

  // Données fraîches à l'ouverture de la page, sans jamais recourir à
  // window.location.reload() — voir docs/decisions.md D-113 (cause racine #1).
  React.useEffect(() => {
    queryClient.invalidateQueries({ queryKey: ["material-requests"] });
    queryClient.invalidateQueries({ queryKey: ["intervention-type-requests"] });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const rows: UnifiedRequest[] = React.useMemo(() => {
    const material: UnifiedRequest[] = (materialQuery.data?.items ?? []).map((request) => ({ kind: "material", request }));
    const intervention: UnifiedRequest[] = (interventionQuery.data?.items ?? []).map((request) => ({ kind: "intervention", request }));
    return [...material, ...intervention].sort(
      (a, b) => new Date(b.request.createdAt).getTime() - new Date(a.request.createdAt).getTime(),
    );
  }, [materialQuery.data, interventionQuery.data]);

  // Met la ligne ciblée par la notification en évidence + la fait défiler dans le champ
  // de vision, une fois les données chargées. Si elle n'est plus PENDING (déjà traitée
  // par un autre manager), elle n'apparaît simplement pas dans cet onglet — dégradation
  // gracieuse, pas d'échec.
  React.useEffect(() => {
    if (!highlightKey || tab !== "PENDING" || materialQuery.isLoading || interventionQuery.isLoading) return;
    const el = rowRefs.current.get(highlightKey);
    if (!el) return;
    el.scrollIntoView({ behavior: "smooth", block: "center" });
    const timer = setTimeout(() => setHighlightKey(null), 4000);
    return () => clearTimeout(timer);
  }, [highlightKey, tab, materialQuery.isLoading, interventionQuery.isLoading, rows]);

  const resolveMaterialMutation = useMutation({
    mutationFn: ({ id, materialItemId }: { id: number; materialItemId: number }) =>
      resolveMaterialRequest(id, materialItemId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["material-requests"] });
      setResolveMaterialTarget(null);
      toast.success("Demande résolue. Ligne matériel créée.");
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message ?? "Erreur lors de la résolution.");
    },
  });

  const ignoreMaterialMutation = useMutation({
    mutationFn: ignoreMaterialRequest,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["material-requests"] });
      setIgnoreTarget(null);
      setIgnoreError(null);
      toast.success("Demande ignorée et demandeur averti.");
    },
    onError: (err: any) => {
      // Décision concurrente (409) — un autre manager a déjà traité la demande : on
      // rafraîchit la liste en fond pour que la ligne reflète l'état réel dès la
      // fermeture de la modal, sans jamais faire croire que l'action a réussi.
      if (err?.response?.status === 409) {
        queryClient.invalidateQueries({ queryKey: ["material-requests"] });
      }
      setIgnoreError(err?.response?.data?.error?.message ?? err?.response?.data?.message ?? "Erreur lors de l'action.");
    },
  });

  const resolveInterventionMutation = useMutation({
    mutationFn: ({ id, interventionTypeId, primaryFirmId }: { id: number; interventionTypeId: number; primaryFirmId?: number }) =>
      resolveInterventionTypeRequest(id, interventionTypeId, primaryFirmId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["intervention-type-requests"] });
      setResolveInterventionTarget(null);
      toast.success("Demande résolue. Intervention créée sur la mission.");
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message ?? "Erreur lors de la résolution.");
    },
  });

  const ignoreInterventionMutation = useMutation({
    mutationFn: ignoreInterventionTypeRequest,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["intervention-type-requests"] });
      setIgnoreTarget(null);
      setIgnoreError(null);
      toast.success("Demande ignorée et demandeur averti.");
    },
    onError: (err: any) => {
      if (err?.response?.status === 409) {
        queryClient.invalidateQueries({ queryKey: ["intervention-type-requests"] });
      }
      setIgnoreError(err?.response?.data?.error?.message ?? err?.response?.data?.message ?? "Erreur lors de l'action.");
    },
  });

  const isLoading = materialQuery.isLoading || interventionQuery.isLoading;
  const isError = materialQuery.isError || interventionQuery.isError;

  return (
    <Stack spacing={2}>
      <PageHeader
        title="Demandes"
        subtitle="Matériel et types d'intervention demandés par les instrumentistes pendant l'encodage."
      />

      <Tabs
        value={tab}
        onChange={(_, v: TabValue) => setTab(v)}
        sx={{ borderBottom: 1, borderColor: "divider" }}
      >
        <Tab label="En attente" value="PENDING" />
        <Tab label="Résolues" value="RESOLVED" />
        <Tab label="Ignorées" value="IGNORED" />
      </Tabs>

      {isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}>
          <CircularProgress size={28} />
        </Box>
      ) : isError ? (
        <Alert severity="error">Impossible de charger les demandes.</Alert>
      ) : rows.length === 0 ? (
        <EmptyState title="Aucune demande." />
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Type</TableCell>
                <TableCell>Demande</TableCell>
                <TableCell>Référence</TableCell>
                <TableCell>Mission</TableCell>
                <TableCell>Demandé par</TableCell>
                <TableCell>Statut</TableCell>
                {tab === "PENDING" ? <TableCell align="right">Actions</TableCell> : null}
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((row) => {
                const { request } = row;
                const isMaterial = row.kind === "material";
                const rowKey = `${row.kind}-${request.id}`;
                const isHighlighted = highlightKey === rowKey;
                return (
                  <TableRow
                    key={rowKey}
                    hover
                    ref={(el) => {
                      if (el) rowRefs.current.set(rowKey, el);
                      else rowRefs.current.delete(rowKey);
                    }}
                    sx={isHighlighted ? {
                      bgcolor: "warning.light",
                      transition: "background-color 2s ease",
                    } : undefined}
                  >
                    <TableCell>
                      <Chip
                        size="small"
                        label={isMaterial ? "Matériel" : "Intervention"}
                        variant="outlined"
                        color={isMaterial ? "default" : "primary"}
                        sx={{ fontSize: ".68rem" }}
                      />
                    </TableCell>
                    <TableCell>
                      <Stack spacing={0.25}>
                        <Typography variant="body2">{request.label}</Typography>
                        {request.comment ? (
                          <Typography variant="caption" color="text.secondary">
                            {request.comment}
                          </Typography>
                        ) : null}
                      </Stack>
                    </TableCell>
                    <TableCell>
                      {isMaterial
                        ? (request as MaterialRequestDTO).referenceCode || "—"
                        : (request as InterventionTypeRequestDTO).suggestedCode || "—"}
                    </TableCell>
                    <TableCell>
                      {request.mission ? (
                        <Stack spacing={0}>
                          <Typography variant="body2">#{request.mission.id}</Typography>
                          {request.mission.site ? (
                            <Typography variant="caption" color="text.secondary">
                              {request.mission.site}
                            </Typography>
                          ) : null}
                        </Stack>
                      ) : "—"}
                    </TableCell>
                    <TableCell>{request.requestedBy?.displayName ?? "—"}</TableCell>
                    <TableCell>
                      <Chip
                        label={statusLabel(request.status)}
                        color={statusColor(request.status)}
                        size="small"
                        variant="outlined"
                      />
                      {isMaterial && request.status === "RESOLVED" && (request as MaterialRequestDTO).materialItem ? (
                        <Typography variant="caption" display="block" color="text.secondary">
                          → {(request as MaterialRequestDTO).materialItem!.label}
                        </Typography>
                      ) : null}
                      {!isMaterial && request.status === "RESOLVED" && (request as InterventionTypeRequestDTO).resolvedInterventionType ? (
                        <Typography variant="caption" display="block" color="text.secondary">
                          → {(request as InterventionTypeRequestDTO).resolvedInterventionType!.label}
                        </Typography>
                      ) : null}
                      {request.status === "IGNORED" && request.ignoreReason ? (
                        <Stack spacing={0} sx={{ mt: 0.5 }}>
                          <Typography variant="caption" display="block" color="text.secondary">
                            Motif : {IGNORE_REASON_LABELS[request.ignoreReason]}
                          </Typography>
                          {request.ignoreComment ? (
                            <Typography variant="caption" display="block" color="text.secondary">
                              « {request.ignoreComment} »
                            </Typography>
                          ) : null}
                          {request.decidedBy ? (
                            <Typography variant="caption" display="block" color="text.secondary">
                              Par {request.decidedBy.displayName}
                              {request.decidedAt ? ` le ${new Date(request.decidedAt).toLocaleDateString("fr-BE")}` : ""}
                            </Typography>
                          ) : null}
                        </Stack>
                      ) : null}
                    </TableCell>
                    {tab === "PENDING" ? (
                      <TableCell align="right">
                        <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                          <Button
                            size="small"
                            variant="contained"
                            onClick={() =>
                              isMaterial
                                ? setResolveMaterialTarget(request as MaterialRequestDTO)
                                : setResolveInterventionTarget(request as InterventionTypeRequestDTO)
                            }
                            disabled={ignoreMaterialMutation.isPending || ignoreInterventionMutation.isPending}
                          >
                            {isMaterial ? "Créer produit" : "Résoudre"}
                          </Button>
                          <Button
                            size="small"
                            color="inherit"
                            variant="outlined"
                            onClick={() => {
                              setIgnoreError(null);
                              setIgnoreTarget({ kind: row.kind, id: request.id, label: request.label });
                            }}
                            disabled={ignoreMaterialMutation.isPending || ignoreInterventionMutation.isPending}
                          >
                            Ignorer
                          </Button>
                        </Stack>
                      </TableCell>
                    ) : null}
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <ResolveMaterialDialog
        open={resolveMaterialTarget !== null}
        request={resolveMaterialTarget}
        onClose={() => setResolveMaterialTarget(null)}
        resolving={resolveMaterialMutation.isPending}
        onResolve={(materialItemId) => {
          if (!resolveMaterialTarget) return;
          resolveMaterialMutation.mutate({ id: resolveMaterialTarget.id, materialItemId });
        }}
      />

      <ResolveInterventionDialog
        open={resolveInterventionTarget !== null}
        request={resolveInterventionTarget}
        onClose={() => setResolveInterventionTarget(null)}
        resolving={resolveInterventionMutation.isPending}
        onResolve={(interventionTypeId, primaryFirmId) => {
          if (!resolveInterventionTarget) return;
          resolveInterventionMutation.mutate({ id: resolveInterventionTarget.id, interventionTypeId, primaryFirmId });
        }}
      />

      <IgnoreCatalogueRequestDialog
        open={ignoreTarget !== null}
        kind={ignoreTarget?.kind ?? null}
        label={ignoreTarget?.label ?? ""}
        onClose={() => {
          setIgnoreTarget(null);
          setIgnoreError(null);
        }}
        submitting={ignoreMaterialMutation.isPending || ignoreInterventionMutation.isPending}
        error={ignoreError}
        onConfirm={(reason, comment) => {
          if (!ignoreTarget) return;
          if (ignoreTarget.kind === "material") {
            ignoreMaterialMutation.mutate({ id: ignoreTarget.id, reason, comment });
          } else {
            ignoreInterventionMutation.mutate({ id: ignoreTarget.id, reason, comment });
          }
        }}
      />
    </Stack>
  );
}
