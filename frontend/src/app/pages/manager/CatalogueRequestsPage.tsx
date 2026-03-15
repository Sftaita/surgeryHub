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
  Paper,
  Stack,
  Tab,
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
  MaterialItemFormDialog,
  type MaterialItemFormValues,
} from "../../features/manager-catalogue/components/MaterialItemFormDialog";
import { useToast } from "../../ui/toast/useToast";

type TabValue = "PENDING" | "RESOLVED" | "IGNORED";

// Dialog to pick an existing material item OR create a new one
function ResolveDialog({
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
  const isLoading = resolving || createMutation.isPending;

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

export default function CatalogueRequestsPage() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [tab, setTab] = React.useState<TabValue>("PENDING");
  const [resolveTarget, setResolveTarget] =
    React.useState<MaterialRequestDTO | null>(null);

  const listQuery = useQuery({
    queryKey: ["material-requests", tab],
    queryFn: () => getMaterialRequests({ status: tab }),
  });

  const requests = listQuery.data?.items ?? [];

  const resolveMutation = useMutation({
    mutationFn: ({ id, materialItemId }: { id: number; materialItemId: number }) =>
      resolveMaterialRequest(id, materialItemId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["material-requests"] });
      setResolveTarget(null);
      toast.success("Demande résolue. Ligne matériel créée.");
    },
    onError: (err: any) => {
      toast.error(
        err?.response?.data?.message ?? "Erreur lors de la résolution.",
      );
    },
  });

  const ignoreMutation = useMutation({
    mutationFn: ignoreMaterialRequest,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["material-requests"] });
      toast.success("Demande ignorée.");
    },
    onError: (err: any) => {
      toast.error(
        err?.response?.data?.message ?? "Erreur lors de l'action.",
      );
    },
  });

  return (
    <Stack spacing={2}>
      <Typography variant="h6">Demandes matériel</Typography>

      <Tabs
        value={tab}
        onChange={(_, v: TabValue) => setTab(v)}
        sx={{ borderBottom: 1, borderColor: "divider" }}
      >
        <Tab label="En attente" value="PENDING" />
        <Tab label="Résolues" value="RESOLVED" />
        <Tab label="Ignorées" value="IGNORED" />
      </Tabs>

      {listQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}>
          <CircularProgress size={28} />
        </Box>
      ) : listQuery.isError ? (
        <Alert severity="error">Impossible de charger les demandes.</Alert>
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Matériel demandé</TableCell>
                <TableCell>Référence</TableCell>
                <TableCell>Mission</TableCell>
                <TableCell>Demandé par</TableCell>
                <TableCell>Statut</TableCell>
                {tab === "PENDING" ? (
                  <TableCell align="right">Actions</TableCell>
                ) : null}
              </TableRow>
            </TableHead>
            <TableBody>
              {requests.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} align="center">
                    <Typography
                      variant="body2"
                      color="text.secondary"
                      sx={{ py: 2 }}
                    >
                      Aucune demande.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                requests.map((req) => (
                  <TableRow key={req.id} hover>
                    <TableCell>
                      <Stack spacing={0.25}>
                        <Typography variant="body2">{req.label}</Typography>
                        {req.comment ? (
                          <Typography variant="caption" color="text.secondary">
                            {req.comment}
                          </Typography>
                        ) : null}
                      </Stack>
                    </TableCell>
                    <TableCell>{req.referenceCode || "—"}</TableCell>
                    <TableCell>
                      {req.mission ? (
                        <Stack spacing={0}>
                          <Typography variant="body2">#{req.mission.id}</Typography>
                          {req.mission.site ? (
                            <Typography variant="caption" color="text.secondary">
                              {req.mission.site}
                            </Typography>
                          ) : null}
                        </Stack>
                      ) : "—"}
                    </TableCell>
                    <TableCell>
                      {req.requestedBy?.displayName ?? "—"}
                    </TableCell>
                    <TableCell>
                      <Chip
                        label={statusLabel(req.status)}
                        color={statusColor(req.status)}
                        size="small"
                        variant="outlined"
                      />
                      {req.status === "RESOLVED" && req.materialItem ? (
                        <Typography variant="caption" display="block" color="text.secondary">
                          → {req.materialItem.label}
                        </Typography>
                      ) : null}
                    </TableCell>
                    {tab === "PENDING" ? (
                      <TableCell align="right">
                        <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                          <Button
                            size="small"
                            variant="contained"
                            onClick={() => setResolveTarget(req)}
                            disabled={ignoreMutation.isPending}
                          >
                            Créer produit
                          </Button>
                          <Button
                            size="small"
                            color="inherit"
                            variant="outlined"
                            onClick={() => ignoreMutation.mutate(req.id)}
                            disabled={ignoreMutation.isPending}
                          >
                            Ignorer
                          </Button>
                        </Stack>
                      </TableCell>
                    ) : null}
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <ResolveDialog
        open={resolveTarget !== null}
        request={resolveTarget}
        onClose={() => setResolveTarget(null)}
        resolving={resolveMutation.isPending}
        onResolve={(materialItemId) => {
          if (!resolveTarget) return;
          resolveMutation.mutate({ id: resolveTarget.id, materialItemId });
        }}
      />
    </Stack>
  );
}
