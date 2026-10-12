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
  FormControlLabel,
  Link,
  MenuItem,
  Paper,
  Radio,
  RadioGroup,
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
  getMaterialRequests,
  ignoreMaterialRequest,
  resolveMaterialRequest,
} from "../../features/manager-catalogue/api/catalogue.api";
import type {
  CatalogueRequestIgnoreReason,
  MaterialRequestDTO,
  ResolveMaterialRequestBody,
} from "../../features/manager-catalogue/api/catalogue.types";
import {
  getInterventionTypeRequests,
  ignoreInterventionTypeRequest,
  resolveInterventionTypeRequest,
  type IgnoreStrategy,
  type InterventionTypeRequestDTO,
  type ResolveInterventionTypeRequestBody,
} from "../../features/manager-catalogue/api/interventionTypeRequests.api";
import {
  CatalogueRequestContext,
  RequesterCommentPreview,
} from "../../features/manager-catalogue/components/CatalogueRequestComment";
import {
  ResolveInterventionDialog,
  ResolveMaterialDialog,
  toCatalogueApiError,
  type CatalogueApiError,
} from "../../features/manager-catalogue/components/CatalogueRequestResolveDialogs";
import { CatalogueRequestDetailDialog } from "../../features/manager-catalogue/components/CatalogueRequestDetailDialog";
import {
  IGNORE_REASON_LABELS,
  statusColor,
  statusLabel,
  type CatalogueRequestRow,
} from "../../features/manager-catalogue/catalogueRequestDisplay";
import { useToast } from "../../ui/toast/useToast";
import { PageHeader } from "../../ui/PageHeader";
import { EmptyState } from "../../ui/EmptyState";

type TabValue = "PENDING" | "RESOLVED" | "IGNORED";

/** Ligne unifiée du tableau — matériel ou intervention, distinguées par `kind`. */
type UnifiedRequest = CatalogueRequestRow;

/** Récapitulatif affiché en tête de chaque modal de traitement (résolution / ignore). */
function RequestContext({ row }: { row: UnifiedRequest }) {
  const { request } = row;
  return (
    <CatalogueRequestContext
      kind={row.kind}
      label={request.label}
      reference={row.kind === "material" ? row.request.referenceCode : row.request.suggestedCode}
      requestedBy={request.requestedBy}
      mission={request.mission}
      comment={request.comment}
      firm={requestFirmLabel(row)}
      quantity={row.kind === "material" ? (row.request.quantity ? String(Number(row.request.quantity)).replace(".", ",") : null) : undefined}
    />
  );
}

/** D-146 bis — firme indiquée par l'instrumentiste (référentiel ou nom proposé). */
function requestFirmLabel(row: UnifiedRequest): string | null {
  if (row.kind === "material") {
    return row.request.proposedFirm?.name ?? (row.request.proposedFirmName ? `${row.request.proposedFirmName} (proposée)` : null);
  }
  return row.request.requestedFirm?.name
    ?? (row.request.proposedFirmName ? `${row.request.proposedFirmName} (proposée)` : row.request.requestedFirmNameSnapshot ?? null);
}

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
  target,
  onClose,
  onConfirm,
  submitting,
  error,
}: {
  open: boolean;
  target: UnifiedRequest | null;
  onClose: () => void;
  onConfirm: (reason: CatalogueRequestIgnoreReason, comment: string, strategy?: IgnoreStrategy, missionInterventionId?: number) => void;
  submitting: boolean;
  error: string | null;
}) {
  const [reason, setReason] = React.useState<CatalogueRequestIgnoreReason | "">("");
  const [comment, setComment] = React.useState("");
  const [strategy, setStrategy] = React.useState<IgnoreStrategy | "">("");
  const [reassignTargetId, setReassignTargetId] = React.useState<number | "">("");

  React.useEffect(() => {
    if (open) {
      setReason("");
      setComment("");
      setStrategy("");
      setReassignTargetId("");
    }
  }, [open]);

  if (!target) return null;
  const reasons = ignoreReasonsFor(target.kind);
  // D-146 bis — matériel déjà déclaré sur la demande d'intervention : le manager choisit
  // explicitement ce qu'il devient (jamais perdu, jamais déplacé implicitement).
  const draft = target.kind === "intervention" ? target.request.draft : null;
  const materialCount = draft ? draft.materialLineCount + draft.materialItemRequestCount : 0;
  const reassignTargets = target.kind === "intervention" ? target.request.mission?.interventions ?? [] : [];
  const strategyComplete = materialCount === 0 || strategy === "KEEP_AS_HISTORY" || (strategy === "REASSIGN" && reassignTargetId !== "");
  const canSubmit = reason !== "" && comment.trim() !== "" && strategyComplete;

  return (
    <Dialog open={open} onClose={submitting ? undefined : onClose} fullWidth maxWidth="sm">
      <DialogTitle>Ignorer cette demande ?</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {error ? <Alert severity="error">{error}</Alert> : null}
          <RequestContext row={target} />

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

          {materialCount > 0 ? (
            <Stack spacing={1}>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                Du matériel est déjà déclaré sur cette demande ({materialCount} élément(s)). Que devient-il ?
              </Typography>
              <RadioGroup value={strategy} onChange={(e) => setStrategy(e.target.value as IgnoreStrategy)}>
                <FormControlLabel value="KEEP_AS_HISTORY" control={<Radio size="small" />} label="Le conserver en historique (non rattaché à une intervention)" />
                <FormControlLabel
                  value="REASSIGN"
                  disabled={reassignTargets.length === 0}
                  control={<Radio size="small" />}
                  label={reassignTargets.length === 0 ? "Le rattacher à une intervention existante (aucune sur cette mission)" : "Le rattacher à une intervention existante de la mission"}
                />
              </RadioGroup>
              {strategy === "REASSIGN" ? (
                <Select
                  fullWidth
                  size="small"
                  displayEmpty
                  value={reassignTargetId}
                  onChange={(e) => setReassignTargetId(Number(e.target.value))}
                >
                  <MenuItem value="" disabled>Intervention de destination</MenuItem>
                  {reassignTargets.map((i) => (
                    <MenuItem key={i.id} value={i.id}>{i.label}{i.firm ? ` · ${i.firm}` : ""}</MenuItem>
                  ))}
                </Select>
              ) : null}
            </Stack>
          ) : null}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={submitting}>Annuler</Button>
        <Button
          variant="contained"
          color="error"
          disabled={!canSubmit || submitting}
          onClick={() => {
            if (reason === "") return;
            if (materialCount > 0 && strategy !== "") {
              onConfirm(reason, comment.trim(), strategy, strategy === "REASSIGN" && reassignTargetId !== "" ? reassignTargetId : undefined);
            } else {
              onConfirm(reason, comment.trim());
            }
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
  const [ignoreTarget, setIgnoreTarget] = React.useState<UnifiedRequest | null>(null);
  const [detailTarget, setDetailTarget] = React.useState<UnifiedRequest | null>(null);

  // Ouvrir le détail est une pure consultation (aucun appel réseau) ; les deux handlers
  // ci-dessous ne font qu'ouvrir les modals de traitement existantes.
  const openResolve = (row: UnifiedRequest) => {
    setResolveError(null);
    if (row.kind === "material") setResolveMaterialTarget(row.request);
    else setResolveInterventionTarget(row.request);
  };
  const openIgnore = (row: UnifiedRequest) => {
    setIgnoreError(null);
    setIgnoreTarget(row);
  };
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

  // D-146 bis — une seule requête atomique ; en cas de refus la modal reste ouverte avec
  // l'erreur du serveur (doublons, firme existante, mission verrouillée…), jamais un toast seul.
  const [resolveError, setResolveError] = React.useState<CatalogueApiError | null>(null);
  const resolveMaterialMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: ResolveMaterialRequestBody }) => resolveMaterialRequest(id, body),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ["material-requests"] });
      queryClient.invalidateQueries({ queryKey: ["material-items"] });
      queryClient.invalidateQueries({ queryKey: ["firms"] });
      setResolveMaterialTarget(null);
      setResolveError(null);
      toast.success(result.createdItem
        ? "Demande résolue : matériel ajouté au catalogue (facturation à décider) et à la mission."
        : "Demande résolue : matériel ajouté à la mission.");
    },
    onError: (err: unknown) => {
      if ((err as { response?: { status?: number } })?.response?.status === 409) {
        queryClient.invalidateQueries({ queryKey: ["material-requests"] });
      }
      setResolveError(toCatalogueApiError(err));
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
    mutationFn: ({ id, body }: { id: number; body: ResolveInterventionTypeRequestBody }) => resolveInterventionTypeRequest(id, body),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ["intervention-type-requests"] });
      queryClient.invalidateQueries({ queryKey: ["intervention-types"] });
      queryClient.invalidateQueries({ queryKey: ["firms"] });
      queryClient.invalidateQueries({ queryKey: ["firmServiceOfferings"] });
      setResolveInterventionTarget(null);
      setResolveError(null);
      toast.success(result.offeringDecision === "FEE_APPLICABLE"
        ? "Demande résolue. Intervention créée ; configurez le montant du forfait dans Catalogue › Prestations."
        : "Demande résolue. Intervention créée sur la mission.");
    },
    onError: (err: unknown) => {
      if ((err as { response?: { status?: number } })?.response?.status === 409) {
        queryClient.invalidateQueries({ queryKey: ["intervention-type-requests"] });
      }
      setResolveError(toCatalogueApiError(err));
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
        // D-146 bis — 6 colonnes (référence intégrée à la demande, demandeur à la mission,
        // actions empilées) : plus de défilement horizontal à 1280 px ; le défilement reste
        // possible sur petit écran (TableContainer), jamais d'information masquée.
        <TableContainer component={Paper} variant="outlined">
          <Table size="small" sx={{ "& td, & th": { px: 1.25 } }}>
            <TableHead>
              <TableRow>
                <TableCell>Type</TableCell>
                <TableCell>Demande</TableCell>
                <TableCell>Firme · Qté</TableCell>
                <TableCell>Mission · demandeur</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right">Actions</TableCell>
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
                    onClick={() => setDetailTarget(row)}
                    sx={{
                      cursor: "pointer",
                      ...(isHighlighted ? { bgcolor: "warning.light", transition: "background-color 2s ease" } : null),
                    }}
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
                    <TableCell sx={{ maxWidth: 420 }}>
                      <Link
                        component="button"
                        type="button"
                        variant="body2"
                        underline="hover"
                        onClick={(e: React.MouseEvent) => {
                          e.stopPropagation();
                          setDetailTarget(row);
                        }}
                        sx={{ textAlign: "left", color: "text.primary", fontWeight: 500 }}
                      >
                        {request.label}
                      </Link>
                      {(isMaterial ? (request as MaterialRequestDTO).referenceCode : (request as InterventionTypeRequestDTO).suggestedCode) ? (
                        <Typography variant="caption" display="block" color="text.secondary">
                          {isMaterial ? "Réf. " : "Code suggéré "}
                          {isMaterial ? (request as MaterialRequestDTO).referenceCode : (request as InterventionTypeRequestDTO).suggestedCode}
                        </Typography>
                      ) : null}
                      {request.comment ? (
                        // Déplier l'aperçu ne doit pas ouvrir le détail.
                        <Box onClick={(e) => e.stopPropagation()}>
                          <RequesterCommentPreview comment={request.comment} />
                        </Box>
                      ) : null}
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{requestFirmLabel(row) ?? "—"}</Typography>
                      {isMaterial && (request as MaterialRequestDTO).quantity ? (
                        <Typography variant="caption" color="text.secondary">
                          Qté {String(Number((request as MaterialRequestDTO).quantity)).replace(".", ",")}
                        </Typography>
                      ) : null}
                    </TableCell>
                    <TableCell>
                      <Stack spacing={0}>
                        <Typography variant="body2">{request.mission ? `#${request.mission.id}` : "—"}</Typography>
                        {request.mission?.site ? (
                          <Typography variant="caption" color="text.secondary">
                            {request.mission.site}
                          </Typography>
                        ) : null}
                        <Typography variant="caption" color="text.secondary">
                          {request.requestedBy?.displayName ?? "—"}
                        </Typography>
                      </Stack>
                    </TableCell>
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
                    <TableCell align="right" onClick={(e) => e.stopPropagation()}>
                      <Stack spacing={0.5} alignItems="flex-end" sx={{ "& .MuiButton-root": { whiteSpace: "nowrap", minWidth: 96 } }}>
                        <Button size="small" variant="text" onClick={() => setDetailTarget(row)}>
                          Voir le détail
                        </Button>
                        {request.status === "PENDING" ? (
                          <>
                            <Button
                              size="small"
                              variant="contained"
                              onClick={() => openResolve(row)}
                              disabled={ignoreMaterialMutation.isPending || ignoreInterventionMutation.isPending}
                            >
                              Résoudre
                            </Button>
                            <Button
                              size="small"
                              color="inherit"
                              variant="outlined"
                              onClick={() => openIgnore(row)}
                              disabled={ignoreMaterialMutation.isPending || ignoreInterventionMutation.isPending}
                            >
                              Ignorer
                            </Button>
                          </>
                        ) : null}
                      </Stack>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <CatalogueRequestDetailDialog
        row={detailTarget}
        onClose={() => setDetailTarget(null)}
        onResolve={(row) => {
          setDetailTarget(null);
          openResolve(row);
        }}
        onIgnore={(row) => {
          setDetailTarget(null);
          openIgnore(row);
        }}
      />

      <ResolveMaterialDialog
        open={resolveMaterialTarget !== null}
        request={resolveMaterialTarget}
        onClose={() => setResolveMaterialTarget(null)}
        resolving={resolveMaterialMutation.isPending}
        error={resolveError}
        onResolve={(body) => {
          if (!resolveMaterialTarget) return;
          resolveMaterialMutation.mutate({ id: resolveMaterialTarget.id, body });
        }}
      />

      <ResolveInterventionDialog
        open={resolveInterventionTarget !== null}
        request={resolveInterventionTarget}
        onClose={() => setResolveInterventionTarget(null)}
        resolving={resolveInterventionMutation.isPending}
        error={resolveError}
        onResolve={(body) => {
          if (!resolveInterventionTarget) return;
          resolveInterventionMutation.mutate({ id: resolveInterventionTarget.id, body });
        }}
      />

      <IgnoreCatalogueRequestDialog
        open={ignoreTarget !== null}
        target={ignoreTarget}
        onClose={() => {
          setIgnoreTarget(null);
          setIgnoreError(null);
        }}
        submitting={ignoreMaterialMutation.isPending || ignoreInterventionMutation.isPending}
        error={ignoreError}
        onConfirm={(reason, comment, strategy, missionInterventionId) => {
          if (!ignoreTarget) return;
          if (ignoreTarget.kind === "material") {
            ignoreMaterialMutation.mutate({ id: ignoreTarget.request.id, reason, comment });
          } else {
            ignoreInterventionMutation.mutate({ id: ignoreTarget.request.id, reason, comment, strategy, missionInterventionId });
          }
        }}
      />
    </Stack>
  );
}
