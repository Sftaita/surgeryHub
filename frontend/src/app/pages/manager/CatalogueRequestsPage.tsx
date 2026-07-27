import * as React from "react";
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
 * Demandes matériel + demandes de type d'intervention réunies (D-079) — un
 * seul tableau, chaque ligne taguée par nature, mêmes onglets de statut
 * qu'avant. Le backend des demandes d'intervention
 * (`InterventionTypeRequestManagerController`) existait déjà depuis D-068
 * sans jamais avoir eu d'écran manager.
 */
export default function CatalogueRequestsPage() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [tab, setTab] = React.useState<TabValue>("PENDING");
  const [resolveMaterialTarget, setResolveMaterialTarget] = React.useState<MaterialRequestDTO | null>(null);
  const [resolveInterventionTarget, setResolveInterventionTarget] = React.useState<InterventionTypeRequestDTO | null>(null);

  const materialQuery = useQuery({
    queryKey: ["material-requests", tab],
    queryFn: () => getMaterialRequests({ status: tab }),
  });
  const interventionQuery = useQuery({
    queryKey: ["intervention-type-requests", tab],
    queryFn: () => getInterventionTypeRequests({ status: tab }),
  });

  const rows: UnifiedRequest[] = React.useMemo(() => {
    const material: UnifiedRequest[] = (materialQuery.data?.items ?? []).map((request) => ({ kind: "material", request }));
    const intervention: UnifiedRequest[] = (interventionQuery.data?.items ?? []).map((request) => ({ kind: "intervention", request }));
    return [...material, ...intervention].sort(
      (a, b) => new Date(b.request.createdAt).getTime() - new Date(a.request.createdAt).getTime(),
    );
  }, [materialQuery.data, interventionQuery.data]);

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
      toast.success("Demande ignorée.");
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message ?? "Erreur lors de l'action.");
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
      toast.success("Demande ignorée.");
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message ?? "Erreur lors de l'action.");
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
                return (
                  <TableRow key={`${row.kind}-${request.id}`} hover>
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
                            onClick={() =>
                              isMaterial
                                ? ignoreMaterialMutation.mutate(request.id)
                                : ignoreInterventionMutation.mutate(request.id)
                            }
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
    </Stack>
  );
}
