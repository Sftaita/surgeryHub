import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Button,
  Divider,
  IconButton,
  Paper,
  Stack,
  Typography,
} from "@mui/material";
import EditIcon from "@mui/icons-material/Edit";
import DeleteIcon from "@mui/icons-material/Delete";

import type {
  CatalogFirm,
  CatalogItem,
  EncodingIntervention,
  EncodingMaterialLine,
  CreateMaterialLineBody,
  PatchMaterialLineBody,
} from "../api/encoding.types";
import { useToast } from "../../../ui/toast/useToast";
import {
  createMissionMaterialLine,
  deleteMissionMaterialLine,
  patchMissionMaterialLine,
} from "../api/encoding.api";
import ConfirmDeleteDialog from "./ConfirmDeleteDialog";
import AddMaterialLineDialog from "./AddMaterialLineDialog";
import EditMaterialLineDialog from "./EditMaterialLineDialog";

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

export default function MaterialLinesSection({
  missionId,
  canEdit,
  interventions,
  catalog,
}: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [openAdd, setOpenAdd] = React.useState(false);
  const [editTarget, setEditTarget] = React.useState<{
    line: EncodingMaterialLine;
    interventionId: number;
  } | null>(null);
  const [deleteTarget, setDeleteTarget] = React.useState<{
    line: EncodingMaterialLine;
  } | null>(null);

  const invalidate = React.useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] });
    queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
  }, [queryClient, missionId]);

  const createMutation = useMutation({
    mutationFn: (body: CreateMaterialLineBody) =>
      createMissionMaterialLine(missionId, body),
    onSuccess: async () => {
      toast.success("Ligne matériel ajoutée");
      setOpenAdd(false);
      await invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const patchMutation = useMutation({
    mutationFn: (args: { lineId: number; body: PatchMaterialLineBody }) =>
      patchMissionMaterialLine(missionId, args.lineId, args.body),
    onSuccess: async () => {
      toast.success("Ligne matériel mise à jour");
      setEditTarget(null);
      await invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (lineId: number) =>
      deleteMissionMaterialLine(missionId, lineId),
    onSuccess: async () => {
      toast.success("Ligne matériel supprimée");
      setDeleteTarget(null);
      await invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const isBusy =
    createMutation.isPending ||
    patchMutation.isPending ||
    deleteMutation.isPending;

  const sortedInterventions = (interventions ?? [])
    .slice()
    .sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));

  const hasAnyLines = sortedInterventions.some(
    (i) => (i.materialLines ?? []).length > 0,
  );
  const hasAnyReqs = sortedInterventions.some(
    (i) => (i.materialItemRequests ?? []).length > 0,
  );

  return (
    <Paper variant="outlined" sx={{ p: 2 }}>
      <Stack spacing={2}>
        <Stack
          direction="row"
          justifyContent="space-between"
          alignItems="center"
        >
          <Typography variant="subtitle2">Matériel</Typography>

          {canEdit && (
            <Button
              variant="contained"
              size="small"
              onClick={() => setOpenAdd(true)}
              disabled={isBusy}
            >
              Ajouter
            </Button>
          )}
        </Stack>

        {!hasAnyLines && !hasAnyReqs ? (
          <Typography color="text.secondary">Aucune ligne matériel</Typography>
        ) : (
          sortedInterventions.map((itv) => (
            <Stack key={itv.id} spacing={1}>
              <Typography sx={{ fontWeight: 600 }}>
                {itv.code} — {itv.label}
              </Typography>

              {itv.materialLines.map((l) => (
                <Stack key={l.id} spacing={0.5}>
                  <Stack direction="row" spacing={1} alignItems="flex-start">
                    <Stack sx={{ flex: 1 }}>
                      <Typography>
                        {l.item.label}{" "}
                        <Typography component="span" color="text.secondary">
                          ({l.item.firm.name} / {l.item.referenceCode})
                        </Typography>
                      </Typography>
                      <Typography color="text.secondary">
                        Qté: {l.quantity}
                      </Typography>
                    </Stack>

                    {canEdit && (
                      <Stack direction="row" spacing={0.5}>
                        <IconButton
                          size="small"
                          disabled={isBusy}
                          onClick={() =>
                            setEditTarget({ line: l, interventionId: itv.id })
                          }
                        >
                          <EditIcon fontSize="small" />
                        </IconButton>
                        <IconButton
                          size="small"
                          disabled={isBusy}
                          onClick={() => setDeleteTarget({ line: l })}
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
          ))
        )}
      </Stack>

      <AddMaterialLineDialog
        open={openAdd}
        loading={createMutation.isPending}
        interventions={sortedInterventions}
        catalog={catalog}
        onClose={() => (isBusy ? null : setOpenAdd(false))}
        onSubmit={(values: CreateMaterialLineBody) =>
          createMutation.mutate(values)
        }
      />

      <EditMaterialLineDialog
        open={!!editTarget}
        loading={patchMutation.isPending}
        line={editTarget?.line ?? null}
        onClose={() => (isBusy ? null : setEditTarget(null))}
        onSubmit={(values: PatchMaterialLineBody) => {
          if (!editTarget) return;
          patchMutation.mutate({
            lineId: editTarget.line.id,
            body: values,
          });
        }}
      />

      <ConfirmDeleteDialog
        open={!!deleteTarget}
        loading={deleteMutation.isPending}
        title="Supprimer la ligne matériel ?"
        message={
          deleteTarget
            ? `${deleteTarget.line.item.label} (${deleteTarget.line.item.firm.name})`
            : ""
        }
        onClose={() => (isBusy ? null : setDeleteTarget(null))}
        onConfirm={() => {
          if (!deleteTarget) return;
          deleteMutation.mutate(deleteTarget.line.id);
        }}
      />
    </Paper>
  );
}
