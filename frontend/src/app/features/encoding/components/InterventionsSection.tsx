import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Chip,
  Stack,
  Typography,
  Paper,
  Divider,
  Button,
  IconButton,
} from "@mui/material";
import EditIcon from "@mui/icons-material/Edit";
import DeleteIcon from "@mui/icons-material/Delete";

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
  catalog?: {
    items: CatalogItem[];
    firms: CatalogFirm[];
  };
};

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function buildInterventionDisplayTitles(interventions: EncodingIntervention[]) {
  // ex: LCA #1, LCA #2 si même code+label
  const keyOf = (i: EncodingIntervention) => `${i.code}||${i.label}`;
  const groups = new Map<string, EncodingIntervention[]>();
  for (const itv of interventions) {
    const k = keyOf(itv);
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k)!.push(itv);
  }

  const indexMap = new Map<number, { n?: number; total: number }>();
  for (const [_, arr] of groups.entries()) {
    const total = arr.length;
    arr.forEach((itv, idx) => {
      indexMap.set(itv.id, { n: total > 1 ? idx + 1 : undefined, total });
    });
  }
  return indexMap;
}

export default function InterventionsSection({
  missionId,
  canEdit,
  interventions,
  catalog,
}: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  // dialogs interventions
  const [openAddIntervention, setOpenAddIntervention] = React.useState(false);
  const [editIntervention, setEditIntervention] =
    React.useState<EncodingIntervention | null>(null);
  const [deleteInterventionTarget, setDeleteInterventionTarget] =
    React.useState<EncodingIntervention | null>(null);

  // dialogs material
  const [openAddMaterial, setOpenAddMaterial] = React.useState(false);
  const [preferredInterventionId, setPreferredInterventionId] = React.useState<
    number | null
  >(null);

  // dialog - material item request
  const [openRequestDialog, setOpenRequestDialog] = React.useState(false);
  const [preferredRequestInterventionId, setPreferredRequestInterventionId] =
    React.useState<number | null>(null);

  const [editLineTarget, setEditLineTarget] = React.useState<{
    line: EncodingMaterialLine;
  } | null>(null);

  const [deleteLineTarget, setDeleteLineTarget] = React.useState<{
    line: EncodingMaterialLine;
  } | null>(null);

  const invalidate = React.useCallback(() => {
    // Pas await ici -> on laisse le refetch se faire en background
    queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] });
    queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
  }, [queryClient, missionId]);

  const setEncodingCache = React.useCallback(
    (
      updater: (current: MissionEncodingResponse) => MissionEncodingResponse,
    ) => {
      queryClient.setQueryData(["missionEncoding", missionId], (old: any) => {
        if (!old) return old;
        return updater(old as MissionEncodingResponse);
      });
    },
    [queryClient, missionId],
  );

  /**
   * Mutations - Interventions
   */
  const createInterventionMutation = useMutation({
    mutationFn: (body: { code: string; label: string; orderIndex: number }) =>
      createMissionIntervention(missionId, body),
    onSuccess: async () => {
      toast.success("Intervention ajoutée");
      setOpenAddIntervention(false);
      invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const patchInterventionMutation = useMutation({
    mutationFn: (args: {
      interventionId: number;
      body: { code?: string; label?: string; orderIndex?: number };
    }) => patchMissionIntervention(missionId, args.interventionId, args.body),
    onSuccess: async () => {
      toast.success("Intervention mise à jour");
      setEditIntervention(null);
      invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const deleteInterventionMutation = useMutation({
    mutationFn: (interventionId: number) =>
      deleteMissionIntervention(missionId, interventionId),
    onSuccess: async () => {
      toast.success("Intervention supprimée");
      setDeleteInterventionTarget(null);
      invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  /**
   * Mutations - Material lines (OPTIMISTIC)
   */
  const createLineMutation = useMutation({
    mutationFn: (body: CreateMaterialLineBody) =>
      createMissionMaterialLine(missionId, body),
    onMutate: async (body) => {
      // 1) stop queries / snapshot
      await queryClient.cancelQueries({
        queryKey: ["missionEncoding", missionId],
      });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);

      // 2) close dialog immediately (optimistic UX)
      setOpenAddMaterial(false);
      setPreferredInterventionId(null);

      // 3) optimistic insert with temporary negative id
      const tempId = -Date.now();
      const item =
        (catalog?.items ?? []).find((i) => i.id === body.itemId) ?? null;

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
                  item: {
                    id: item.id,
                    label: item.label,
                    referenceCode: item.referenceCode,
                    unit: item.unit,
                    isImplant: item.isImplant,
                    firm: { id: item.firm.id, name: item.firm.name },
                  },
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
      // replace temp line with real one
      if (!ctx) return;

      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => {
          if (itv.id !== created.missionInterventionId) return itv;
          return {
            ...itv,
            materialLines: (itv.materialLines ?? []).map((l) =>
              l.id === ctx.tempId ? created : l,
            ),
          };
        }),
      }));

      toast.success("Ligne matériel ajoutée");
      invalidate();
    },
    onError: (err: any, _body, ctx) => {
      if (ctx?.previous) {
        queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      }
      toast.error(extractErrorMessage(err));
    },
  });

  const patchLineMutation = useMutation({
    mutationFn: (args: { lineId: number; body: PatchMaterialLineBody }) =>
      patchMissionMaterialLine(missionId, args.lineId, args.body),
    onMutate: async (args) => {
      await queryClient.cancelQueries({
        queryKey: ["missionEncoding", missionId],
      });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);

      // close dialog immediately
      setEditLineTarget(null);

      // optimistic patch
      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => ({
          ...itv,
          materialLines: (itv.materialLines ?? []).map((l) => {
            if (l.id !== args.lineId) return l;
            return {
              ...l,
              quantity: args.body.quantity ?? l.quantity,
              comment: args.body.comment ?? l.comment,
            };
          }),
        })),
      }));

      return { previous };
    },
    onSuccess: (updated) => {
      // ensure exact backend formatting (ex "3.00")
      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => {
          if (itv.id !== updated.missionInterventionId) return itv;
          return {
            ...itv,
            materialLines: (itv.materialLines ?? []).map((l) =>
              l.id === updated.id ? updated : l,
            ),
          };
        }),
      }));

      toast.success("Ligne matériel mise à jour");
      invalidate();
    },
    onError: (err: any, _args, ctx) => {
      if (ctx?.previous) {
        queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      }
      toast.error(extractErrorMessage(err));
    },
  });

  const deleteLineMutation = useMutation({
    mutationFn: (lineId: number) =>
      deleteMissionMaterialLine(missionId, lineId),
    onMutate: async (lineId) => {
      await queryClient.cancelQueries({
        queryKey: ["missionEncoding", missionId],
      });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);

      // close confirm immediately
      setDeleteLineTarget(null);

      // optimistic remove
      setEncodingCache((current) => ({
        ...current,
        interventions: current.interventions.map((itv) => ({
          ...itv,
          materialLines: (itv.materialLines ?? []).filter(
            (l) => l.id !== lineId,
          ),
        })),
      }));

      return { previous };
    },
    onSuccess: () => {
      toast.success("Ligne matériel supprimée");
      invalidate();
    },
    onError: (err: any, _lineId, ctx) => {
      if (ctx?.previous) {
        queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      }
      toast.error(extractErrorMessage(err));
    },
  });

  const createRequestMutation = useMutation({
    mutationFn: (body: CreateMaterialItemRequestBody) =>
      createMissionMaterialItemRequest(missionId, body),
    onSuccess: () => {
      toast.success("Demande envoyée au manager.");
      setOpenRequestDialog(false);
      invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const isBusy =
    createInterventionMutation.isPending ||
    patchInterventionMutation.isPending ||
    deleteInterventionMutation.isPending ||
    createLineMutation.isPending ||
    patchLineMutation.isPending ||
    deleteLineMutation.isPending ||
    createRequestMutation.isPending;

  const sorted = (interventions ?? [])
    .slice()
    .sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));

  const displayMap = buildInterventionDisplayTitles(sorted);

  return (
    <Paper variant="outlined" sx={{ p: 2 }}>
      <Stack spacing={2}>
        {/* Header + Add intervention outside items */}
        <Stack
          direction="row"
          justifyContent="space-between"
          alignItems="center"
        >
          <Typography variant="subtitle1">Interventions</Typography>

          {canEdit && (
            <Button
              variant="contained"
              size="small"
              onClick={() => setOpenAddIntervention(true)}
              disabled={isBusy}
            >
              Ajouter intervention
            </Button>
          )}
        </Stack>

        {sorted.length === 0 ? (
          <Typography color="text.secondary">Aucune intervention</Typography>
        ) : (
          sorted.map((itv, idx) => {
            const meta = displayMap.get(itv.id);
            const titlePrefix =
              meta?.n != null ? `${itv.code} n°${meta.n}` : itv.code;

            const lines = itv.materialLines ?? [];

            return (
              <Stack key={itv.id} spacing={1}>
                {/* Intervention line */}
                <Stack direction="row" spacing={1} alignItems="flex-start">
                  <Stack sx={{ flex: 1 }} spacing={0.5}>
                    <Typography variant="subtitle2" fontWeight={700}>
                      {titlePrefix} — {itv.label}
                    </Typography>
                  </Stack>

                  {canEdit && (
                    <Stack direction="row" spacing={1} alignItems="center">
                      <Button
                        variant="text"
                        size="small"
                        onClick={() => {
                          setPreferredInterventionId(itv.id);
                          setOpenAddMaterial(true);
                        }}
                        disabled={isBusy}
                      >
                        Encoder matériel
                      </Button>

                      <IconButton
                        aria-label="Éditer"
                        onClick={() => setEditIntervention(itv)}
                        disabled={isBusy}
                        size="small"
                      >
                        <EditIcon fontSize="small" />
                      </IconButton>

                      <IconButton
                        aria-label="Supprimer"
                        onClick={() => setDeleteInterventionTarget(itv)}
                        disabled={isBusy}
                        size="small"
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Stack>
                  )}
                </Stack>

                {/* Material lines under intervention */}
                <Stack spacing={1} sx={{ pl: 0 }}>
                  <Typography variant="overline" color="text.secondary">
                    Matériel encodé
                  </Typography>

                  {lines.length === 0 ? (
                    <Typography color="text.secondary">
                      Aucun matériel
                    </Typography>
                  ) : (
                    <Stack spacing={1}>
                      {lines.map((l) => (
                        <Stack key={l.id} spacing={0.5}>
                          <Stack
                            direction="row"
                            spacing={1}
                            alignItems="flex-start"
                          >
                            <Stack sx={{ flex: 1 }}>
                              <Typography variant="body2">
                                {l.item?.label ?? "—"}
                              </Typography>

                              <Typography variant="caption" color="text.secondary">
                                {l.item?.firm?.name ?? "—"}{l.item?.referenceCode ? ` · ${l.item.referenceCode}` : ""}
                              </Typography>

                              <Typography variant="body2" color="text.secondary">
                                {l.quantity} {l.item?.unit ?? ""}{l.item?.isImplant ? " · implant" : ""}
                              </Typography>

                              {l.comment ? (
                                <Typography variant="caption" color="text.secondary">{l.comment}</Typography>
                              ) : null}
                            </Stack>

                            {canEdit && (
                              <Stack direction="row" spacing={0.5}>
                                <IconButton
                                  size="small"
                                  disabled={isBusy}
                                  onClick={() => setEditLineTarget({ line: l })}
                                >
                                  <EditIcon fontSize="small" />
                                </IconButton>

                                <IconButton
                                  size="small"
                                  disabled={isBusy}
                                  onClick={() =>
                                    setDeleteLineTarget({ line: l })
                                  }
                                >
                                  <DeleteIcon fontSize="small" />
                                </IconButton>
                              </Stack>
                            )}
                          </Stack>

                          <Divider />
                        </Stack>
                      ))}
                    </Stack>
                  )}
                </Stack>

                {/* Material item requests under intervention */}
                {(itv.materialItemRequests ?? []).length > 0 && (
                  <Stack spacing={1} sx={{ pl: 0 }}>
                    <Typography variant="overline" color="text.secondary">
                      Demandes matériel
                    </Typography>
                    <Stack spacing={0.75}>
                      {(itv.materialItemRequests ?? []).map((req) => (
                        <Stack
                          key={req.id}
                          direction="row"
                          spacing={1}
                          alignItems="center"
                        >
                          <Stack sx={{ flex: 1 }}>
                            <Typography variant="body2">{req.label}</Typography>
                            {req.referenceCode ? (
                              <Typography variant="caption" color="text.secondary">
                                Réf : {req.referenceCode}
                              </Typography>
                            ) : null}
                            {req.comment ? (
                              <Typography variant="caption" color="text.secondary">
                                {req.comment}
                              </Typography>
                            ) : null}
                          </Stack>
                          <Chip
                            label="En attente"
                            size="small"
                            color="warning"
                            variant="outlined"
                          />
                        </Stack>
                      ))}
                    </Stack>
                  </Stack>
                )}

                {idx < sorted.length - 1 && <Divider />}
              </Stack>
            );
          })
        )}
      </Stack>

      {/* Dialogs - interventions */}
      <AddInterventionDialog
        open={openAddIntervention}
        loading={createInterventionMutation.isPending}
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
          patchInterventionMutation.mutate({
            interventionId: editIntervention.id,
            body: values,
          });
        }}
      />

      <ConfirmDeleteDialog
        open={!!deleteInterventionTarget}
        loading={deleteInterventionMutation.isPending}
        title="Supprimer l’intervention ?"
        message={
          deleteInterventionTarget
            ? `${deleteInterventionTarget.code} — ${deleteInterventionTarget.label}`
            : ""
        }
        onClose={() => (isBusy ? null : setDeleteInterventionTarget(null))}
        onConfirm={() => {
          if (!deleteInterventionTarget) return;
          deleteInterventionMutation.mutate(deleteInterventionTarget.id);
        }}
      />

      {/* Dialog - add material line */}
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

      {/* Dialog - edit material line */}
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
              quantity:
                values.quantity != null ? String(values.quantity) : undefined,
              comment: values.comment,
            },
          });
        }}
      />

      <ConfirmDeleteDialog
        open={!!deleteLineTarget}
        loading={deleteLineMutation.isPending}
        title="Supprimer la ligne matériel ?"
        message={
          deleteLineTarget
            ? `${deleteLineTarget.line.item.label} (${deleteLineTarget.line.item.firm.name})`
            : ""
        }
        onClose={() => (isBusy ? null : setDeleteLineTarget(null))}
        onConfirm={() => {
          if (!deleteLineTarget) return;
          deleteLineMutation.mutate(deleteLineTarget.line.id);
        }}
      />

      {/* Dialog - material item request */}
      <MaterialItemRequestDialog
        open={openRequestDialog}
        loading={createRequestMutation.isPending}
        interventions={sorted}
        preferredInterventionId={preferredRequestInterventionId}
        onClose={() => (isBusy ? null : setOpenRequestDialog(false))}
        onSubmit={(values) => createRequestMutation.mutate(values)}
      />
    </Paper>
  );
}
