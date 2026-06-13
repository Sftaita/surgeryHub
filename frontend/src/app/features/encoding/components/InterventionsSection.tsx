import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  Chip,
  Divider,
  IconButton,
  Paper,
  Stack,
  Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import EditIcon from "@mui/icons-material/Edit";
import RemoveIcon from "@mui/icons-material/Remove";

import type {
  EncodingIntervention,
  CatalogFirm,
  CatalogItem,
  CreateMaterialLineBody,
  PatchMaterialLineBody,
  EncodingMaterialLine,
  MissionEncodingResponse,
  CreateMaterialItemRequestBody,
} from "../api/encoding.types";
import { useToast } from "../../../ui/toast/useToast";
import {
  createMissionIntervention,
  patchMissionIntervention,
  deleteMissionIntervention,
  createMissionMaterialLine,
  patchMissionMaterialLine,
  deleteMissionMaterialLine,
  createMissionMaterialItemRequest,
} from "../api/encoding.api";

import AddInterventionDialog from "./AddInterventionDialog";
import EditInterventionDialog from "./EditInterventionDialog";
import ConfirmDeleteDialog from "./ConfirmDeleteDialog";
import AddMaterialLineDialog from "./AddMaterialLineDialog";
import EditMaterialLineDialog from "./EditMaterialLineDialog";
import MaterialItemRequestDialog from "./MaterialItemRequestDialog";

type Props = {
  missionId: number;
  canEdit: boolean;
  interventions: EncodingIntervention[];
  catalog?: { items: CatalogItem[]; firms: CatalogFirm[] };
};

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function parseQty(qty: string): number {
  const n = parseFloat(qty);
  return Number.isFinite(n) ? n : 1;
}

function displayQty(qty: string): string {
  const n = parseFloat(qty);
  if (!Number.isFinite(n)) return qty;
  return n % 1 === 0 ? String(Math.round(n)) : String(n);
}

function buildInterventionTitle(itv: EncodingIntervention, index: number): string {
  return `Intervention ${index + 1} — ${itv.label || itv.code}`;
}

export default function InterventionsSection({ missionId, canEdit, interventions, catalog }: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [openAddIntervention, setOpenAddIntervention] = React.useState(false);
  const [editIntervention, setEditIntervention] = React.useState<EncodingIntervention | null>(null);
  const [deleteInterventionTarget, setDeleteInterventionTarget] = React.useState<EncodingIntervention | null>(null);

  const [openAddMaterial, setOpenAddMaterial] = React.useState(false);
  const [preferredInterventionId, setPreferredInterventionId] = React.useState<number | null>(null);

  const [openRequestDialog, setOpenRequestDialog] = React.useState(false);
  const [preferredRequestInterventionId, setPreferredRequestInterventionId] = React.useState<number | null>(null);

  const [editLineTarget, setEditLineTarget] = React.useState<{ line: EncodingMaterialLine } | null>(null);
  const [deleteLineTarget, setDeleteLineTarget] = React.useState<{ line: EncodingMaterialLine } | null>(null);

  const invalidate = React.useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] });
    queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
  }, [queryClient, missionId]);

  const setEncodingCache = React.useCallback(
    (updater: (current: MissionEncodingResponse) => MissionEncodingResponse) => {
      queryClient.setQueryData(["missionEncoding", missionId], (old: any) => {
        if (!old) return old;
        return updater(old as MissionEncodingResponse);
      });
    },
    [queryClient, missionId],
  );

  // ── Mutations: Interventions ──────────────────────────────────────

  const createInterventionMutation = useMutation({
    mutationFn: (body: { code: string; label: string; orderIndex: number }) =>
      createMissionIntervention(missionId, body),
    onSuccess: () => { toast.success("Intervention ajoutée"); setOpenAddIntervention(false); invalidate(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const patchInterventionMutation = useMutation({
    mutationFn: (args: { interventionId: number; body: { code?: string; label?: string; orderIndex?: number } }) =>
      patchMissionIntervention(missionId, args.interventionId, args.body),
    onSuccess: () => { toast.success("Intervention mise à jour"); setEditIntervention(null); invalidate(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const deleteInterventionMutation = useMutation({
    mutationFn: (interventionId: number) => deleteMissionIntervention(missionId, interventionId),
    onSuccess: () => { toast.success("Intervention supprimée"); setDeleteInterventionTarget(null); invalidate(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  // ── Mutations: Material lines (optimistic) ────────────────────────

  const createLineMutation = useMutation({
    mutationFn: (body: CreateMaterialLineBody) => createMissionMaterialLine(missionId, body),
    onMutate: async (body) => {
      await queryClient.cancelQueries({ queryKey: ["missionEncoding", missionId] });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);
      setOpenAddMaterial(false);
      setPreferredInterventionId(null);
      const tempId = -Date.now();
      const item = (catalog?.items ?? []).find((i) => i.id === body.itemId) ?? null;
      if (item) {
        setEncodingCache((current) => ({
          ...current,
          interventions: current.interventions.map((itv) => {
            if (itv.id !== body.missionInterventionId) return itv;
            return {
              ...itv,
              materialLines: [
                ...(itv.materialLines ?? []),
                {
                  id: tempId,
                  missionInterventionId: body.missionInterventionId,
                  item: { id: item.id, label: item.label, referenceCode: item.referenceCode, unit: item.unit, isImplant: item.isImplant, firm: { id: item.firm.id, name: item.firm.name } },
                  quantity: body.quantity,
                  comment: body.comment ?? "",
                },
              ],
            };
          }),
        }));
      }
      return { previous, tempId };
    },
    onSuccess: (created, _body, ctx) => {
      if (!ctx) return;
      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => {
          if (itv.id !== created.missionInterventionId) return itv;
          return { ...itv, materialLines: (itv.materialLines ?? []).map((l) => l.id === ctx.tempId ? created : l) };
        }),
      }));
      toast.success("Matériel ajouté");
      invalidate();
    },
    onError: (err: any, _body, ctx) => {
      if (ctx?.previous) queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  const patchLineMutation = useMutation({
    mutationFn: (args: { lineId: number; body: PatchMaterialLineBody }) =>
      patchMissionMaterialLine(missionId, args.lineId, args.body),
    onMutate: async (args) => {
      await queryClient.cancelQueries({ queryKey: ["missionEncoding", missionId] });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);
      setEditLineTarget(null);
      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => ({
          ...itv,
          materialLines: (itv.materialLines ?? []).map((l) => {
            if (l.id !== args.lineId) return l;
            return { ...l, quantity: args.body.quantity ?? l.quantity, comment: args.body.comment ?? l.comment };
          }),
        })),
      }));
      return { previous };
    },
    onSuccess: (updated) => {
      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => {
          if (itv.id !== updated.missionInterventionId) return itv;
          return { ...itv, materialLines: (itv.materialLines ?? []).map((l) => l.id === updated.id ? updated : l) };
        }),
      }));
      invalidate();
    },
    onError: (err: any, _args, ctx) => {
      if (ctx?.previous) queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  const deleteLineMutation = useMutation({
    mutationFn: (lineId: number) => deleteMissionMaterialLine(missionId, lineId),
    onMutate: async (lineId) => {
      await queryClient.cancelQueries({ queryKey: ["missionEncoding", missionId] });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);
      setDeleteLineTarget(null);
      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => ({
          ...itv,
          materialLines: (itv.materialLines ?? []).filter((l) => l.id !== lineId),
        })),
      }));
      return { previous };
    },
    onSuccess: () => { toast.success("Matériel supprimé"); invalidate(); },
    onError: (err: any, _lineId, ctx) => {
      if (ctx?.previous) queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  const createRequestMutation = useMutation({
    mutationFn: (body: CreateMaterialItemRequestBody) => createMissionMaterialItemRequest(missionId, body),
    onSuccess: () => { toast.success("Demande envoyée au manager."); setOpenRequestDialog(false); invalidate(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const isBusy =
    createInterventionMutation.isPending || patchInterventionMutation.isPending ||
    deleteInterventionMutation.isPending || createLineMutation.isPending ||
    patchLineMutation.isPending || deleteLineMutation.isPending || createRequestMutation.isPending;

  const sorted = (interventions ?? []).slice().sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));

  // Inline qty handlers
  const handleQtyChange = (line: EncodingMaterialLine, delta: 1 | -1) => {
    const current = parseQty(line.quantity);
    const next = current + delta;
    if (next <= 0) {
      deleteLineMutation.mutate(line.id);
    } else {
      patchLineMutation.mutate({ lineId: line.id, body: { quantity: String(next) } });
    }
  };

  return (
    <Stack spacing={2}>
      {/* Header */}
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Typography variant="subtitle1" fontWeight={600}>
          Interventions
        </Typography>
        {canEdit && (
          <Button
            variant="outlined"
            size="small"
            startIcon={<AddIcon />}
            onClick={() => setOpenAddIntervention(true)}
            disabled={isBusy}
          >
            Ajouter
          </Button>
        )}
      </Stack>

      {/* Empty state */}
      {sorted.length === 0 ? (
        <Paper
          variant="outlined"
          sx={{ borderRadius: 2, py: 4, textAlign: "center", borderStyle: "dashed" }}
        >
          <Typography color="text.secondary" mb={1.5}>
            Aucune intervention encodée
          </Typography>
          {canEdit && (
            <Button
              variant="contained"
              disableElevation
              size="small"
              startIcon={<AddIcon />}
              onClick={() => setOpenAddIntervention(true)}
              disabled={isBusy}
            >
              Ajouter une intervention
            </Button>
          )}
        </Paper>
      ) : (
        sorted.map((itv, idx) => {
          const lines = itv.materialLines ?? [];

          return (
            <Paper key={itv.id} variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
              {/* Intervention header */}
              <Stack
                direction="row"
                alignItems="center"
                sx={{ px: 2, py: 1.25, borderBottom: "1px solid", borderColor: "divider", bgcolor: "grey.50" }}
              >
                <Typography variant="subtitle2" fontWeight={700} sx={{ flex: 1 }}>
                  {buildInterventionTitle(itv, idx)}
                </Typography>
                {canEdit && (
                  <Stack direction="row" spacing={0.5}>
                    <Button
                      size="small"
                      variant="text"
                      onClick={() => { setPreferredInterventionId(itv.id); setOpenAddMaterial(true); }}
                      disabled={isBusy}
                    >
                      + Matériel
                    </Button>
                    <IconButton size="small" disabled={isBusy} onClick={() => setEditIntervention(itv)}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                    <IconButton size="small" disabled={isBusy} onClick={() => setDeleteInterventionTarget(itv)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Stack>
                )}
              </Stack>

              {/* Material lines */}
              <Box sx={{ px: 2, py: lines.length === 0 ? 1.5 : 0.5 }}>
                {lines.length === 0 ? (
                  <Typography variant="body2" color="text.secondary">
                    Aucun matériel encodé
                    {canEdit && (
                      <>
                        {" — "}
                        <Box
                          component="span"
                          sx={{ color: "primary.main", cursor: "pointer", textDecoration: "underline" }}
                          onClick={() => { setPreferredInterventionId(itv.id); setOpenAddMaterial(true); }}
                        >
                          Ajouter
                        </Box>
                      </>
                    )}
                  </Typography>
                ) : (
                  <Stack divider={<Divider />}>
                    {lines.map((l) => (
                      <Stack
                        key={l.id}
                        direction="row"
                        alignItems="center"
                        spacing={1}
                        py={1}
                      >
                        {/* Info matériel */}
                        <Stack sx={{ flex: 1, minWidth: 0 }}>
                          <Typography variant="body2" noWrap>
                            {l.item?.label ?? "—"}
                            {l.item?.isImplant && (
                              <Chip label="implant" size="small" sx={{ ml: 0.75, fontSize: "0.65rem", height: 16 }} />
                            )}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" noWrap>
                            {l.item?.firm?.name ?? "—"}
                            {l.item?.referenceCode ? ` · ${l.item.referenceCode}` : ""}
                          </Typography>
                          {l.comment ? (
                            <Typography variant="caption" color="text.disabled">
                              {l.comment}
                            </Typography>
                          ) : null}
                        </Stack>

                        {/* Contrôles quantité */}
                        {canEdit ? (
                          <Stack direction="row" alignItems="center" spacing={0.5}>
                            <IconButton
                              size="small"
                              disabled={isBusy}
                              onClick={() => handleQtyChange(l, -1)}
                              sx={{ bgcolor: "grey.100", borderRadius: 1, p: 0.5 }}
                            >
                              <RemoveIcon fontSize="small" />
                            </IconButton>

                            <Typography
                              variant="body2"
                              fontWeight={600}
                              sx={{ minWidth: 28, textAlign: "center" }}
                            >
                              {displayQty(l.quantity)}
                            </Typography>

                            <IconButton
                              size="small"
                              disabled={isBusy}
                              onClick={() => handleQtyChange(l, 1)}
                              sx={{ bgcolor: "grey.100", borderRadius: 1, p: 0.5 }}
                            >
                              <AddIcon fontSize="small" />
                            </IconButton>

                            <IconButton
                              size="small"
                              disabled={isBusy}
                              onClick={() => setEditLineTarget({ line: l })}
                              sx={{ ml: 0.5 }}
                            >
                              <EditIcon fontSize="small" />
                            </IconButton>
                          </Stack>
                        ) : (
                          <Typography variant="body2" color="text.secondary">
                            {displayQty(l.quantity)} {l.item?.unit ?? ""}
                          </Typography>
                        )}
                      </Stack>
                    ))}
                  </Stack>
                )}
              </Box>

              {/* Material item requests */}
              {(itv.materialItemRequests ?? []).length > 0 && (
                <Box sx={{ px: 2, pb: 1.5, borderTop: "1px solid", borderColor: "divider" }}>
                  <Typography variant="overline" color="text.secondary" display="block" mt={1} mb={0.5}>
                    Demandes en attente
                  </Typography>
                  <Stack spacing={0.75}>
                    {(itv.materialItemRequests ?? []).map((req) => (
                      <Stack key={req.id} direction="row" spacing={1} alignItems="center">
                        <Stack sx={{ flex: 1 }}>
                          <Typography variant="body2">{req.label}</Typography>
                          {req.referenceCode && (
                            <Typography variant="caption" color="text.secondary">
                              Réf : {req.referenceCode}
                            </Typography>
                          )}
                        </Stack>
                        <Chip label="En attente" size="small" color="warning" variant="outlined" />
                      </Stack>
                    ))}
                  </Stack>
                </Box>
              )}
            </Paper>
          );
        })
      )}

      {/* Dialogs */}
      <AddInterventionDialog
        open={openAddIntervention}
        loading={createInterventionMutation.isPending}
        firms={catalog?.firms ?? []}
        existingCount={sorted.length}
        onClose={() => (isBusy ? null : setOpenAddIntervention(false))}
        onSubmit={(values) => createInterventionMutation.mutate(values)}
      />

      <EditInterventionDialog
        open={!!editIntervention}
        loading={patchInterventionMutation.isPending}
        intervention={editIntervention}
        onClose={() => (isBusy ? null : setEditIntervention(null))}
        onSubmit={(values) => {
          if (!editIntervention) return;
          patchInterventionMutation.mutate({ interventionId: editIntervention.id, body: values });
        }}
      />

      <ConfirmDeleteDialog
        open={!!deleteInterventionTarget}
        loading={deleteInterventionMutation.isPending}
        title="Supprimer l'intervention ?"
        message={deleteInterventionTarget ? `${deleteInterventionTarget.code} — ${deleteInterventionTarget.label}` : ""}
        onClose={() => (isBusy ? null : setDeleteInterventionTarget(null))}
        onConfirm={() => { if (!deleteInterventionTarget) return; deleteInterventionMutation.mutate(deleteInterventionTarget.id); }}
      />

      <AddMaterialLineDialog
        open={openAddMaterial}
        loading={createLineMutation.isPending}
        interventions={sorted}
        catalog={catalog}
        preferredInterventionId={preferredInterventionId}
        onClose={() => (isBusy ? null : setOpenAddMaterial(false))}
        onSubmit={(values) => {
          createLineMutation.mutate({
            missionInterventionId: values.missionInterventionId,
            itemId: values.itemId,
            quantity: String(values.quantity),
            comment: values.comment ?? "",
          });
        }}
        onNotFound={(itvId) => {
          setPreferredRequestInterventionId(itvId || preferredInterventionId);
          setOpenRequestDialog(true);
        }}
      />

      <EditMaterialLineDialog
        open={!!editLineTarget}
        loading={patchLineMutation.isPending}
        line={editLineTarget?.line ?? null}
        onClose={() => (isBusy ? null : setEditLineTarget(null))}
        onSubmit={(values: PatchMaterialLineBody) => {
          if (!editLineTarget) return;
          patchLineMutation.mutate({
            lineId: editLineTarget.line.id,
            body: {
              quantity: values.quantity != null ? String(values.quantity) : undefined,
              comment: values.comment,
            },
          });
        }}
      />

      <ConfirmDeleteDialog
        open={!!deleteLineTarget}
        loading={deleteLineMutation.isPending}
        title="Supprimer le matériel ?"
        message={deleteLineTarget ? `${deleteLineTarget.line.item.label} (${deleteLineTarget.line.item.firm.name})` : ""}
        onClose={() => (isBusy ? null : setDeleteLineTarget(null))}
        onConfirm={() => { if (!deleteLineTarget) return; deleteLineMutation.mutate(deleteLineTarget.line.id); }}
      />

      <MaterialItemRequestDialog
        open={openRequestDialog}
        loading={createRequestMutation.isPending}
        interventions={sorted}
        preferredInterventionId={preferredRequestInterventionId}
        onClose={() => (isBusy ? null : setOpenRequestDialog(false))}
        onSubmit={(values) => createRequestMutation.mutate(values)}
      />
    </Stack>
  );
}
