import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Stack,
  Typography,
  Paper,
  Divider,
  Button,
  IconButton,
} from "@mui/material";
import EditIcon from "@mui/icons-material/Edit";
import DeleteIcon from "@mui/icons-material/Delete";

import type { EncodingIntervention } from "../api/encoding.types";
import FirmsSection from "./FirmsSection";
import { useToast } from "../../../ui/toast/useToast";
import {
  createMissionIntervention,
  patchMissionIntervention,
  deleteMissionIntervention,
} from "../api/encoding.api";
import AddInterventionDialog from "./AddInterventionDialog";
import EditInterventionDialog from "./EditInterventionDialog";
import ConfirmDeleteDialog from ".//ConfirmDeleteDialog";

type Props = {
  missionId: number;
  canEdit: boolean;
  interventions: EncodingIntervention[];
};

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

export default function InterventionsSection({
  missionId,
  canEdit,
  interventions,
}: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [openAdd, setOpenAdd] = React.useState(false);
  const [editTarget, setEditTarget] =
    React.useState<EncodingIntervention | null>(null);
  const [deleteTarget, setDeleteTarget] =
    React.useState<EncodingIntervention | null>(null);

  const invalidate = React.useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] });
    queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
  }, [queryClient, missionId]);

  const createMutation = useMutation({
    mutationFn: (body: { code: string; label: string; orderIndex: number }) =>
      createMissionIntervention(missionId, body),
    onSuccess: async () => {
      toast.success("Intervention ajoutée");
      setOpenAdd(false);
      await invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const patchMutation = useMutation({
    mutationFn: (args: {
      interventionId: number;
      body: { code?: string; label?: string; orderIndex?: number };
    }) => patchMissionIntervention(missionId, args.interventionId, args.body),
    onSuccess: async () => {
      toast.success("Intervention mise à jour");
      setEditTarget(null);
      await invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (interventionId: number) =>
      deleteMissionIntervention(missionId, interventionId),
    onSuccess: async () => {
      toast.success("Intervention supprimée");
      setDeleteTarget(null);
      await invalidate();
    },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const isBusy =
    createMutation.isPending ||
    patchMutation.isPending ||
    deleteMutation.isPending;

  const sorted = (interventions ?? [])
    .slice()
    .sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));

  return (
    <Paper variant="outlined" sx={{ p: 2 }}>
      <Stack spacing={2}>
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
              onClick={() => setOpenAdd(true)}
              disabled={isBusy}
            >
              Ajouter
            </Button>
          )}
        </Stack>

        {sorted.length === 0 ? (
          <Typography color="text.secondary">Aucune intervention</Typography>
        ) : (
          sorted.map((itv, idx) => (
            <Stack key={itv.id} spacing={1}>
              <Stack direction="row" spacing={1} alignItems="center">
                <Stack sx={{ flex: 1 }} spacing={0.5}>
                  <Typography sx={{ fontWeight: 600 }}>
                    {itv.code} — {itv.label}
                  </Typography>
                  <Typography color="text.secondary">
                    orderIndex: {String(itv.orderIndex)}
                  </Typography>
                </Stack>

                {canEdit && (
                  <Stack direction="row" spacing={0.5}>
                    <IconButton
                      aria-label="Éditer"
                      onClick={() => setEditTarget(itv)}
                      disabled={isBusy}
                      size="small"
                    >
                      <EditIcon fontSize="small" />
                    </IconButton>

                    <IconButton
                      aria-label="Supprimer"
                      onClick={() => setDeleteTarget(itv)}
                      disabled={isBusy}
                      size="small"
                    >
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Stack>
                )}
              </Stack>

              <FirmsSection firms={itv.firms ?? []} />

              {idx < sorted.length - 1 && <Divider />}
            </Stack>
          ))
        )}
      </Stack>

      {/* Dialogs */}
      <AddInterventionDialog
        open={openAdd}
        loading={createMutation.isPending}
        onClose={() => (isBusy ? null : setOpenAdd(false))}
        onSubmit={(values) => createMutation.mutate(values)}
      />

      <EditInterventionDialog
        open={!!editTarget}
        loading={patchMutation.isPending}
        intervention={editTarget}
        onClose={() => (isBusy ? null : setEditTarget(null))}
        onSubmit={(values) => {
          if (!editTarget) return;
          patchMutation.mutate({
            interventionId: editTarget.id,
            body: values,
          });
        }}
      />

      <ConfirmDeleteDialog
        open={!!deleteTarget}
        loading={deleteMutation.isPending}
        title="Supprimer l’intervention ?"
        message={
          deleteTarget ? `${deleteTarget.code} — ${deleteTarget.label}` : ""
        }
        onClose={() => (isBusy ? null : setDeleteTarget(null))}
        onConfirm={() => {
          if (!deleteTarget) return;
          deleteMutation.mutate(deleteTarget.id);
        }}
      />
    </Paper>
  );
}
