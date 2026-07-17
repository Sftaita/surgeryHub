import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Chip,
  Paper,
  Stack,
  Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";

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
import MaterialWizard from "./MaterialWizard";
import EditMaterialLineDialog from "./EditMaterialLineDialog";
import MaterialItemRequestDialog from "./MaterialItemRequestDialog";

type Props = {
  missionId: number;
  canEdit: boolean;
  interventions: EncodingIntervention[];
  catalog?: { items: CatalogItem[]; firms: CatalogFirm[] };
  /** Called after any intervention/material mutation succeeds — drives the "Enregistré à" timestamp. */
  onSaved?: () => void;
};

const GREEN_50 = "#EFFAF5";
const GREEN_100 = "#DDF4EA";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_800 = "#243240";
const TEXT_STRONG = "#16202B";
const TEXT_MUTED = "#727E8C";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function displayQty(qty: string): string {
  const n = parseFloat(qty);
  if (!Number.isFinite(n)) return qty;
  return n % 1 === 0 ? String(Math.round(n)) : String(n);
}

function interventionTitle(itv: EncodingIntervention): string {
  return itv.label || itv.code;
}

export default function InterventionsSection({ missionId, canEdit, interventions, catalog, onSaved }: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [openAddIntervention, setOpenAddIntervention] = React.useState(false);
  const [editIntervention, setEditIntervention] = React.useState<EncodingIntervention | null>(null);
  const [deleteInterventionTarget, setDeleteInterventionTarget] = React.useState<EncodingIntervention | null>(null);

  const [openAddMaterial, setOpenAddMaterial] = React.useState(false);
  const [preferredInterventionId, setPreferredInterventionId] = React.useState<number | null>(null);

  const [openRequestDialog, setOpenRequestDialog] = React.useState(false);
  const [preferredRequestInterventionId, setPreferredRequestInterventionId] = React.useState<number | null>(null);

  // Accordéon — la première intervention est ouverte par défaut (screens/encodage/README.md).
  const [openIds, setOpenIds] = React.useState<Set<number> | null>(null);
  const toggleOpen = (id: number) => setOpenIds((prev) => {
    const base = prev ?? new Set(interventions.length ? [interventions[0].id] : []);
    const next = new Set(base);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  const [editLineTarget, setEditLineTarget] = React.useState<{ line: EncodingMaterialLine } | null>(null);
  const [deleteLineTarget, setDeleteLineTarget] = React.useState<{ line: EncodingMaterialLine } | null>(null);

  // Badge "Nouveau" (screens/encodage) : aucun champ backend ne marque une ligne comme
  // "ajoutée récemment" — c'est un état 100% local à cette session de brouillon (jamais
  // persisté, jamais dérivé de l'ordre de la liste). Rempli uniquement par un ajout réussi
  // pendant ce montage du composant ; se réinitialise donc naturellement à la reprise d'un
  // brouillon existant (rechargement de page) — les lignes déjà enregistrées ne portent
  // jamais le badge.
  const [newLineIds, setNewLineIds] = React.useState<Set<number>>(new Set());

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
    onSuccess: () => { toast.success("Intervention ajoutée"); setOpenAddIntervention(false); invalidate(); onSaved?.(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const patchInterventionMutation = useMutation({
    mutationFn: (args: { interventionId: number; body: { code?: string; label?: string; orderIndex?: number } }) =>
      patchMissionIntervention(missionId, args.interventionId, args.body),
    onSuccess: () => { toast.success("Intervention mise à jour"); setEditIntervention(null); invalidate(); onSaved?.(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const deleteInterventionMutation = useMutation({
    mutationFn: (interventionId: number) => deleteMissionIntervention(missionId, interventionId),
    onSuccess: () => { toast.success("Intervention supprimée"); setDeleteInterventionTarget(null); invalidate(); onSaved?.(); },
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
      setNewLineIds((prev) => new Set(prev).add(created.id));
      toast.success("Matériel ajouté");
      invalidate();
      onSaved?.();
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
      onSaved?.();
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
    onSuccess: (_data, lineId) => {
      setNewLineIds((prev) => {
        if (!prev.has(lineId)) return prev;
        const next = new Set(prev);
        next.delete(lineId);
        return next;
      });
      toast.success("Matériel supprimé");
      invalidate();
      onSaved?.();
    },
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
  const isOpen = (id: number) => (openIds ?? new Set(sorted.length ? [sorted[0].id] : [])).has(id);

  // "Marques récentes" du wizard — dérivé des marques déjà utilisées ailleurs dans
  // cette mission (données réelles), jamais une liste inventée.
  const recentFirmIds = React.useMemo(() => {
    const ids = new Set<number>();
    for (const itv of sorted) {
      for (const line of itv.materialLines ?? []) {
        if (line.item?.firm?.id != null) ids.add(line.item.firm.id);
      }
    }
    return Array.from(ids);
  }, [sorted]);

  return (
    <Stack spacing={1.75}>
      {/* Header */}
      <Stack direction="row" alignItems="center" sx={{ gap: "12px" }}>
        <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700, whiteSpace: "nowrap", flexShrink: 0 }}>
          INTERVENTIONS
        </Box>
        <Box sx={{ flex: 1, borderTop: "1px dashed", borderColor: "grey.300" }} />
      </Stack>

      {/* Empty state — un seul point d'entrée pour ajouter (le bouton persistant plein-largeur
          ci-dessous), jamais un second bouton concurrent ici. */}
      {sorted.length === 0 ? (
        <Paper
          variant="outlined"
          sx={{ borderRadius: 2, py: 4, textAlign: "center", borderStyle: "dashed" }}
        >
          <Typography color="text.secondary">
            Aucune intervention encodée
          </Typography>
        </Paper>
      ) : (
        sorted.map((itv) => {
          const lines = itv.materialLines ?? [];
          const open = isOpen(itv.id);

          return (
            <Box key={itv.id} sx={{ background: "#fff", borderRadius: "16px", boxShadow: SHADOW_XS, overflow: "hidden" }}>
              {/* En-tête accordéon */}
              <Box
                component="button"
                type="button"
                onClick={() => toggleOpen(itv.id)}
                sx={{
                  width: "100%", display: "flex", alignItems: "center", gap: "11px", padding: "15px 16px",
                  border: "none", background: "transparent", cursor: "pointer", fontFamily: "inherit", textAlign: "left",
                }}
              >
                <Box sx={{ width: 8, height: 8, borderRadius: "999px", background: GREEN_500, flexShrink: 0 }} />
                <Box sx={{ flex: 1, minWidth: 0, fontSize: 15, fontWeight: 700, color: TEXT_STRONG, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                  {interventionTitle(itv)}
                </Box>
                <Box sx={{ fontSize: 12.5, color: TEXT_MUTED, whiteSpace: "nowrap", flexShrink: 0, fontVariantNumeric: "tabular-nums" }}>
                  {lines.length} matériel{lines.length > 1 ? "s" : ""}
                </Box>
                <svg
                  width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"
                  style={{ transition: "transform 200ms", transform: open ? "rotate(180deg)" : "none", flexShrink: 0 }}
                >
                  <path d="m6 9 6 6 6-6" />
                </svg>
              </Box>

              {open && (
                <Box sx={{ px: "16px" }}>
                  {lines.map((l) => (
                    <Box
                      key={l.id}
                      component={canEdit ? "button" : "div"}
                      type={canEdit ? "button" : undefined}
                      onClick={canEdit ? () => setEditLineTarget({ line: l }) : undefined}
                      disabled={canEdit ? isBusy : undefined}
                      aria-label={canEdit ? `Modifier ${l.item?.label ?? "ce matériel"}` : undefined}
                      sx={{
                        width: "100%", display: "flex", alignItems: "center", gap: "8px", py: "10px",
                        border: "none", borderTop: "1px dashed", borderColor: "grey.150", background: "transparent",
                        fontFamily: "inherit", textAlign: "left", cursor: canEdit ? "pointer" : "default",
                        "&:hover": canEdit ? { background: GREEN_50 } : undefined,
                      }}
                    >
                      <Stack sx={{ flex: 1, minWidth: 0 }}>
                        <Typography variant="body2" noWrap sx={{ color: GRAY_800 }}>
                          {l.item?.label ?? "—"}
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

                      {l.item?.isImplant && (
                        <Chip label="implant" size="small" sx={{ fontSize: "0.65rem", height: 16, flexShrink: 0 }} />
                      )}

                      {newLineIds.has(l.id) && (
                        <Box sx={{ display: "inline-flex", alignItems: "center", height: 20, px: "8px", borderRadius: "999px", background: GREEN_100, color: GREEN_800, fontSize: 11, fontWeight: 700, flexShrink: 0 }}>
                          Nouveau
                        </Box>
                      )}

                      <Typography
                        variant="body2"
                        fontWeight={700}
                        sx={{ color: GRAY_500, fontVariantNumeric: "tabular-nums", flexShrink: 0 }}
                      >
                        {canEdit ? `x${displayQty(l.quantity)}` : `${displayQty(l.quantity)} ${l.item?.unit ?? ""}`}
                      </Typography>
                    </Box>
                  ))}

                  {/* Demandes en attente ("À préciser") */}
                  {(itv.materialItemRequests ?? []).map((req) => (
                    <Stack
                      key={req.id}
                      direction="row"
                      spacing={1}
                      alignItems="center"
                      sx={{ py: "10px", borderTop: "1px dashed", borderColor: "grey.150" }}
                    >
                      <Stack sx={{ flex: 1, minWidth: 0 }}>
                        <Typography variant="body2" noWrap>{req.label}</Typography>
                        {req.referenceCode && (
                          <Typography variant="caption" color="text.secondary" noWrap>
                            Réf : {req.referenceCode}
                          </Typography>
                        )}
                      </Stack>
                      <Box sx={{ display: "inline-flex", alignItems: "center", height: 20, px: "8px", borderRadius: "999px", background: "#FEF6E7", color: "#B7791F", fontSize: 11, fontWeight: 700, flexShrink: 0 }}>
                        À préciser
                      </Box>
                    </Stack>
                  ))}

                  {lines.length === 0 && (itv.materialItemRequests ?? []).length === 0 && (
                    <Typography variant="body2" color="text.secondary" sx={{ py: "10px" }}>
                      Aucun matériel encodé
                    </Typography>
                  )}

                  {canEdit && (
                    <Box
                      component="button"
                      type="button"
                      onClick={() => { setPreferredInterventionId(itv.id); setOpenAddMaterial(true); }}
                      disabled={isBusy}
                      sx={{
                        width: "100%", display: "flex", alignItems: "center", justifyContent: "center", gap: "8px", height: 46,
                        border: "none", borderTop: "1px dashed", borderColor: "grey.150", background: "transparent",
                        color: GREEN_700, fontFamily: "inherit", fontSize: 14, fontWeight: 700, cursor: "pointer",
                        "&:hover": { background: GREEN_50 },
                        "&:active": { transform: "translateY(0.5px)" },
                      }}
                    >
                      <AddIcon sx={{ fontSize: 16 }} />
                      Ajouter du matériel
                    </Box>
                  )}

                  {canEdit && (
                    <Stack direction="row" spacing={2} sx={{ py: "10px", borderTop: "1px dashed", borderColor: "grey.150" }}>
                      <Box
                        component="button"
                        type="button"
                        onClick={() => setEditIntervention(itv)}
                        disabled={isBusy}
                        sx={{ border: "none", background: "none", p: 0, color: GRAY_500, fontFamily: "inherit", fontSize: 12.5, fontWeight: 600, cursor: "pointer", textDecoration: "underline" }}
                      >
                        Modifier l'intervention
                      </Box>
                      <Box
                        component="button"
                        type="button"
                        onClick={() => setDeleteInterventionTarget(itv)}
                        disabled={isBusy}
                        sx={{ border: "none", background: "none", p: 0, color: "error.main", fontFamily: "inherit", fontSize: 12.5, fontWeight: 600, cursor: "pointer", textDecoration: "underline" }}
                      >
                        Supprimer
                      </Box>
                    </Stack>
                  )}
                </Box>
              )}
            </Box>
          );
        })
      )}

      {/* Bouton persistant — visible liste vide ou non (screens/encodage), seul point
          d'entrée pour ajouter une intervention (l'icône "+" d'en-tête a été retirée). */}
      {canEdit && (
        <Box
          component="button"
          type="button"
          onClick={() => setOpenAddIntervention(true)}
          disabled={isBusy}
          sx={{
            height: 50, border: "1.5px solid", borderColor: GREEN_500, borderRadius: "13px",
            background: "#fff", color: GREEN_700, fontFamily: "inherit", fontSize: 14.5, fontWeight: 700,
            cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center", gap: "9px",
            "&:hover": { background: GREEN_50 },
            "&:active": { transform: "translateY(0.5px)" },
          }}
        >
          <AddIcon sx={{ fontSize: 17 }} />
          Nouvelle intervention
        </Box>
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

      <MaterialWizard
        open={openAddMaterial}
        loading={createLineMutation.isPending}
        interventionId={preferredInterventionId}
        catalog={catalog}
        recentFirmIds={recentFirmIds}
        onClose={() => (isBusy ? null : setOpenAddMaterial(false))}
        onSubmit={(values) => createLineMutation.mutate(values)}
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
        onDelete={() => {
          if (!editLineTarget) return;
          setDeleteLineTarget({ line: editLineTarget.line });
          setEditLineTarget(null);
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
