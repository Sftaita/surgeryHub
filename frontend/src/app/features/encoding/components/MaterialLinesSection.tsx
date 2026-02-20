import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import type {
  CatalogFirm,
  CatalogItem,
  EncodingIntervention,
  EncodingMaterialLine,
  MissionEncodingResponse,
  CreateMaterialLineBody,
  PatchMaterialLineBody,
} from "../api/encoding.types";

import { useToast } from "../../../ui/toast/useToast";
import {
  createMissionMaterialLine,
  patchMissionMaterialLine,
  deleteMissionMaterialLine,
} from "../api/encoding.api";

import AddMaterialLineDialog from "./AddMaterialLineDialog";
import EditMaterialLineDialog from "./EditMaterialLineDialog";
import ConfirmDeleteDialog from "./ConfirmDeleteDialog";

type Props = {
  missionId: number;
  canEdit: boolean;
  interventions: EncodingIntervention[];
  catalog?: {
    items: CatalogItem[];
    firms: CatalogFirm[];
  };

  // Pilotage depuis MissionEncodingPage / InterventionsSection
  openAdd: boolean;
  preferredInterventionId: number | null;
  onCloseAdd: () => void;
  onOpenAddGlobal: () => void; // (utile si tu veux un bouton global plus tard)
};

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

/**
 * NOTE:
 * - On ne rend PLUS la “carte Matériel” globale ici.
 * - Le matériel est affiché sous chaque intervention (dans InterventionsSection).
 * - Ce composant sert de contrôleur de dialogs + mutations (optimistes).
 */
export default function MaterialLinesSection({
  missionId,
  canEdit,
  interventions,
  catalog,
  openAdd,
  preferredInterventionId,
  onCloseAdd,
}: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [editTarget, setEditTarget] = React.useState<{
    line: EncodingMaterialLine;
    interventionId: number;
  } | null>(null);

  const [deleteTarget, setDeleteTarget] = React.useState<{
    line: EncodingMaterialLine;
    interventionId: number;
  } | null>(null);

  const encodingKey = React.useMemo(
    () => ["missionEncoding", missionId] as const,
    [missionId],
  );

  const sortedInterventions = React.useMemo(() => {
    return (interventions ?? [])
      .slice()
      .sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));
  }, [interventions]);

  const findCatalogItem = React.useCallback(
    (itemId: number) => {
      const items = catalog?.items ?? [];
      return items.find((it) => it.id === itemId) ?? null;
    },
    [catalog],
  );

  /**
   * Optimistic helpers: update MissionEncodingResponse in cache
   */
  const setEncodingCache = React.useCallback(
    (updater: (prev: MissionEncodingResponse) => MissionEncodingResponse) => {
      queryClient.setQueryData(encodingKey, (prev: any) => {
        if (!prev) return prev;
        return updater(prev as MissionEncodingResponse);
      });
    },
    [queryClient, encodingKey],
  );

  const createMutation = useMutation({
    mutationFn: (body: CreateMaterialLineBody) =>
      createMissionMaterialLine(missionId, body),
    onMutate: async (body) => {
      if (!canEdit) return;

      await queryClient.cancelQueries({ queryKey: encodingKey });

      const previous = queryClient.getQueryData(encodingKey) as
        | MissionEncodingResponse
        | undefined;

      // optimistic line
      const tempId = -Date.now();
      const catalogItem = findCatalogItem(body.itemId);

      const optimisticLine: EncodingMaterialLine = {
        id: tempId,
        missionInterventionId: body.missionInterventionId,
        item: catalogItem
          ? {
              id: catalogItem.id,
              label: catalogItem.label,
              referenceCode: catalogItem.referenceCode,
              unit: catalogItem.unit,
              isImplant: catalogItem.isImplant,
              firm: {
                id: catalogItem.firm.id,
                name: catalogItem.firm.name,
              },
            }
          : // fallback (devrait quasi jamais arriver car itemId vient du catalogue)
            ({
              id: body.itemId,
              label: "—",
              referenceCode: "",
              unit: "",
              isImplant: false,
              firm: { id: 0, name: "—" },
            } as any),
        quantity: body.quantity, // string
        comment: body.comment ?? "",
      };

      setEncodingCache((prev) => ({
        ...prev,
        interventions: (prev.interventions ?? []).map((itv) => {
          if (itv.id !== body.missionInterventionId) return itv;
          return {
            ...itv,
            materialLines: [...(itv.materialLines ?? []), optimisticLine],
          };
        }),
      }));

      return { previous, tempId };
    },
    onSuccess: (createdLine, body, ctx) => {
      // remplace la ligne temp par la vraie
      if (!ctx?.tempId) return;

      setEncodingCache((prev) => ({
        ...prev,
        interventions: (prev.interventions ?? []).map((itv) => {
          if (itv.id !== body.missionInterventionId) return itv;
          return {
            ...itv,
            materialLines: (itv.materialLines ?? []).map((l) =>
              l.id === ctx.tempId ? (createdLine as any) : l,
            ),
          };
        }),
      }));

      toast.success("Ligne matériel ajoutée");
      onCloseAdd();
    },
    onError: (err: any, _body, ctx) => {
      // rollback
      if (ctx?.previous) {
        queryClient.setQueryData(encodingKey, ctx.previous);
      }
      toast.error(extractErrorMessage(err));
    },
    onSettled: async () => {
      // sécurité : re-sync
      await queryClient.invalidateQueries({ queryKey: encodingKey });
      await queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
    },
  });

  const patchMutation = useMutation({
    mutationFn: (args: { lineId: number; body: PatchMaterialLineBody }) =>
      patchMissionMaterialLine(missionId, args.lineId, args.body),
    onMutate: async (args) => {
      if (!canEdit) return;

      await queryClient.cancelQueries({ queryKey: encodingKey });

      const previous = queryClient.getQueryData(encodingKey) as
        | MissionEncodingResponse
        | undefined;

      setEncodingCache((prev) => ({
        ...prev,
        interventions: (prev.interventions ?? []).map((itv) => ({
          ...itv,
          materialLines: (itv.materialLines ?? []).map((l) => {
            if (l.id !== args.lineId) return l;
            return {
              ...l,
              quantity: args.body.quantity ?? l.quantity,
              comment:
                args.body.comment !== undefined ? args.body.comment : l.comment,
            };
          }),
        })),
      }));

      return { previous };
    },
    onSuccess: (updatedLine) => {
      // remplace par la version backend (source de vérité)
      setEncodingCache((prev) => ({
        ...prev,
        interventions: (prev.interventions ?? []).map((itv) => ({
          ...itv,
          materialLines: (itv.materialLines ?? []).map((l) =>
            l.id === (updatedLine as any).id ? (updatedLine as any) : l,
          ),
        })),
      }));

      toast.success("Ligne matériel mise à jour");
      setEditTarget(null);
    },
    onError: (err: any, _args, ctx) => {
      if (ctx?.previous) {
        queryClient.setQueryData(encodingKey, ctx.previous);
      }
      toast.error(extractErrorMessage(err));
    },
    onSettled: async () => {
      await queryClient.invalidateQueries({ queryKey: encodingKey });
      await queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (lineId: number) =>
      deleteMissionMaterialLine(missionId, lineId),
    onMutate: async (lineId) => {
      if (!canEdit) return;

      await queryClient.cancelQueries({ queryKey: encodingKey });

      const previous = queryClient.getQueryData(encodingKey) as
        | MissionEncodingResponse
        | undefined;

      setEncodingCache((prev) => ({
        ...prev,
        interventions: (prev.interventions ?? []).map((itv) => ({
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
      setDeleteTarget(null);
    },
    onError: (err: any, _lineId, ctx) => {
      if (ctx?.previous) {
        queryClient.setQueryData(encodingKey, ctx.previous);
      }
      toast.error(extractErrorMessage(err));
    },
    onSettled: async () => {
      await queryClient.invalidateQueries({ queryKey: encodingKey });
      await queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
    },
  });

  const isBusy =
    createMutation.isPending ||
    patchMutation.isPending ||
    deleteMutation.isPending;

  // expose des callbacks pour ouvrir edit/delete depuis l’affichage sous intervention
  // (si tu ajoutes des boutons “éditer/supprimer” sous Matériel encodé dans InterventionsSection)
  React.useEffect(() => {
    // no-op : juste pour garder le composant vivant si tu veux y brancher des callbacks plus tard
  }, []);

  return (
    <>
      <AddMaterialLineDialog
        open={openAdd}
        loading={createMutation.isPending}
        interventions={sortedInterventions}
        catalog={catalog}
        preferredInterventionId={preferredInterventionId}
        onClose={() => (isBusy ? null : onCloseAdd())}
        onSubmit={(values) => {
          // values.quantity est string
          createMutation.mutate(values);
        }}
      />

      <EditMaterialLineDialog
        open={!!editTarget}
        loading={patchMutation.isPending}
        line={editTarget?.line ?? null}
        onClose={() => (isBusy ? null : setEditTarget(null))}
        onSubmit={(values) => {
          if (!editTarget) return;
          patchMutation.mutate({
            lineId: editTarget.line.id,
            body: values, // quantity: string | undefined ✅
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
    </>
  );
}
