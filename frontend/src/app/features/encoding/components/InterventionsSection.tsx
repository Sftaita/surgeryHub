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
  CatalogInterventionType,
  CreateMaterialLineBody,
  PatchMaterialLineBody,
  EncodingMaterialLine,
  MissionEncodingResponse,
  MissionEncodingEntry,
  MissionEncodingInterventionEntry,
  CreateMaterialItemRequestBody,
  CreateInterventionBody,
  PatchInterventionBody,
  CreateInterventionTypeRequestBody,
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
  createMissionInterventionTypeRequest,
} from "../api/encoding.api";

import AddInterventionDialog, { type DraftSubmitValues } from "./AddInterventionDialog";
import EditInterventionDialog from "./EditInterventionDialog";
import ConfirmDeleteDialog from "./ConfirmDeleteDialog";
import MaterialWizard, { type MaterialTarget } from "./MaterialWizard";
import EditMaterialLineDialog from "./EditMaterialLineDialog";
import MaterialItemRequestDialog from "./MaterialItemRequestDialog";

type Props = {
  missionId: number;
  canEdit: boolean;
  entries: MissionEncodingEntry[];
  /**
   * TRANSITOIRE — uniquement pour l'enrichissement Lot 6 (suggestedMaterials/coherence)
   * d'une entrée INTERVENTION, tant que ces champs n'existent pas encore sur `entries`
   * côté backend (hors périmètre du commit 7 backend). Jamais utilisé pour construire la
   * liste ni son ordre — voir `entries`, seule source de vérité pour le rendu.
   */
  legacyInterventions?: EncodingIntervention[];
  catalog?: { items: CatalogItem[]; firms: CatalogFirm[]; interventionTypes: CatalogInterventionType[] };
  /** Called after any intervention/material mutation succeeds — drives the "Enregistré à" timestamp. */
  onSaved?: () => void;
};

const GREEN_50 = "#EFFAF5";
const GREEN_100 = "#DDF4EA";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_200 = "#DDE2E8";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_800 = "#243240";
const TEXT_STRONG = "#16202B";
const TEXT_MUTED = "#727E8C";
const AMBER_50 = "#FEF6E7";
const AMBER_700 = "#B7791F";
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

/** Convention déjà utilisée pour les lignes matériel optimistes (createLineMutation) :
 *  un id négatif = placeholder local, pas encore confirmé par le serveur. */
function isPending(id: number): boolean {
  return id < 0;
}

/** `intervention-{id}` / `draft-{id}`, `-temp-` pour les entrées optimistes — jamais
 *  seulement `id` : une intervention et un draft peuvent partager le même id numérique
 *  (deux tables, deux séquences indépendantes). */
function entryKey(entry: MissionEncodingEntry): string {
  const prefix = entry.kind === "INTERVENTION" ? "intervention" : "draft";
  return isPending(entry.id) ? `${prefix}-temp-${Math.abs(entry.id)}` : `${prefix}-${entry.id}`;
}

function entryTarget(entry: MissionEncodingEntry): MaterialTarget {
  return { kind: entry.kind, id: entry.id };
}

function statusBadge(entry: MissionEncodingEntry): { label: string; bg: string; fg: string } | null {
  if (entry.kind === "INTERVENTION") return null;
  if (entry.status === "KEPT_AS_HISTORY") {
    return { label: "Conservé comme historique", bg: "#EFF2F5", fg: GRAY_700 };
  }
  return { label: "En attente de validation manager", bg: AMBER_50, fg: AMBER_700 };
}

export default function InterventionsSection({ missionId, canEdit, entries, legacyInterventions, catalog, onSaved }: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [openAddIntervention, setOpenAddIntervention] = React.useState(false);
  const [editIntervention, setEditIntervention] = React.useState<MissionEncodingInterventionEntry | null>(null);
  const [deleteInterventionTarget, setDeleteInterventionTarget] = React.useState<MissionEncodingInterventionEntry | null>(null);

  const [openAddMaterial, setOpenAddMaterial] = React.useState(false);
  const [preferredTarget, setPreferredTarget] = React.useState<MaterialTarget | null>(null);

  const [openRequestDialog, setOpenRequestDialog] = React.useState(false);
  const [preferredRequestTarget, setPreferredRequestTarget] = React.useState<MaterialTarget | null>(null);

  // Accordéon — la première entrée est ouverte par défaut (screens/encodage/README.md).
  const [openIds, setOpenIds] = React.useState<Set<string> | null>(null);
  const toggleOpen = (key: string) => setOpenIds((prev) => {
    const base = prev ?? new Set(entries.length ? [entryKey(sortEntries(entries)[0])] : []);
    const next = new Set(base);
    if (next.has(key)) next.delete(key); else next.add(key);
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

  // ── Mutations: Interventions (réelles) ────────────────────────────

  const createInterventionMutation = useMutation({
    mutationFn: (body: CreateInterventionBody) => createMissionIntervention(missionId, body),
    onMutate: async (body) => {
      await queryClient.cancelQueries({ queryKey: ["missionEncoding", missionId] });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);
      setOpenAddIntervention(false);
      const tempId = -Date.now();
      const type = (catalog?.interventionTypes ?? []).find((t) => t.id === body.interventionTypeId) ?? null;
      const firm = body.primaryFirmId != null ? (catalog?.firms ?? []).find((f) => f.id === body.primaryFirmId) ?? null : null;
      const optimistic: MissionEncodingInterventionEntry = {
        kind: "INTERVENTION",
        id: tempId,
        requestId: null,
        orderIndex: body.orderIndex,
        label: type?.label ?? "",
        interventionType: type,
        firm: firm ? { id: firm.id, name: firm.name } : null,
        requestedFirmNameSnapshot: null,
        status: "CATALOGUED",
        readOnly: false,
        materialLines: [],
        materialItemRequests: [],
      };
      setEncodingCache((current) => ({ ...current, entries: [...(current.entries ?? []), optimistic] }));
      return { previous, tempId };
    },
    onSuccess: (created, _body, ctx) => {
      if (!ctx) return;
      setEncodingCache((current) => ({
        ...current,
        entries: (current.entries ?? []).map((e) =>
          e.kind === "INTERVENTION" && e.id === ctx.tempId ? { ...e, id: created.id } : e,
        ),
      }));
      toast.success("Intervention ajoutée");
      invalidate();
      onSaved?.();
    },
    onError: (err: any, _body, ctx) => {
      if (ctx?.previous) queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  const patchInterventionMutation = useMutation({
    mutationFn: (args: { interventionId: number; body: PatchInterventionBody }) =>
      patchMissionIntervention(missionId, args.interventionId, args.body),
    onSuccess: () => { toast.success("Intervention mise à jour"); setEditIntervention(null); invalidate(); onSaved?.(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  const deleteInterventionMutation = useMutation({
    mutationFn: (interventionId: number) => deleteMissionIntervention(missionId, interventionId),
    onSuccess: () => { toast.success("Intervention supprimée"); setDeleteInterventionTarget(null); invalidate(); onSaved?.(); },
    onError: (err: any) => toast.error(extractErrorMessage(err)),
  });

  // ── Mutations: demandes provisoires (drafts) ──────────────────────

  const createDraftMutation = useMutation({
    mutationFn: (body: CreateInterventionTypeRequestBody) => createMissionInterventionTypeRequest(missionId, body),
    onMutate: async (body) => {
      await queryClient.cancelQueries({ queryKey: ["missionEncoding", missionId] });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);
      setOpenAddIntervention(false);
      const tempId = -Date.now();
      const firm = body.requestedFirmId != null ? (catalog?.firms ?? []).find((f) => f.id === body.requestedFirmId) ?? null : null;
      setEncodingCache((current) => ({
        ...current,
        entries: [
          ...(current.entries ?? []),
          {
            kind: "DRAFT",
            id: tempId,
            requestId: tempId,
            orderIndex: (current.entries ?? []).length,
            label: body.label,
            interventionType: null,
            firm: firm ? { id: firm.id, name: firm.name } : null,
            requestedFirmNameSnapshot: firm?.name ?? null,
            status: "OPEN",
            readOnly: false,
            materialLines: [],
            materialItemRequests: [],
          },
        ],
      }));
      return { previous, tempId };
    },
    onSuccess: (created, _body, ctx) => {
      if (!ctx) return;
      setEncodingCache((current) => ({
        ...current,
        entries: (current.entries ?? []).map((e) =>
          e.kind === "DRAFT" && e.id === ctx.tempId
            ? {
                ...e,
                id: created.draftId,
                requestId: created.id,
                orderIndex: created.orderIndex,
                label: created.label,
                firm: created.requestedFirm,
                requestedFirmNameSnapshot: created.requestedFirm?.name ?? null,
              }
            : e,
        ),
      }));
      toast.success("Demande envoyée au manager.");
      invalidate();
      onSaved?.();
    },
    onError: (err: any, _body, ctx) => {
      if (ctx?.previous) queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  // ── Mutations: Material lines (optimistic, cible intervention OU draft) ─────

  const createLineMutation = useMutation({
    mutationFn: (body: CreateMaterialLineBody) => createMissionMaterialLine(missionId, body),
    onMutate: async (body) => {
      await queryClient.cancelQueries({ queryKey: ["missionEncoding", missionId] });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);
      setOpenAddMaterial(false);
      setPreferredTarget(null);
      const targetKind: MaterialTarget["kind"] = body.missionInterventionId != null ? "INTERVENTION" : "DRAFT";
      const targetId = (body.missionInterventionId ?? body.interventionDraftId)!;
      const tempId = -Date.now();
      const item = (catalog?.items ?? []).find((i) => i.id === body.itemId) ?? null;
      if (item) {
        setEncodingCache((current) => ({
          ...current,
          entries: (current.entries ?? []).map((e) => {
            if (e.kind !== targetKind || e.id !== targetId) return e;
            return {
              ...e,
              materialLines: [
                ...(e.materialLines ?? []),
                {
                  id: tempId,
                  missionInterventionId: targetKind === "INTERVENTION" ? targetId : null,
                  interventionDraftId: targetKind === "DRAFT" ? targetId : null,
                  item: { id: item.id, label: item.label, referenceCode: item.referenceCode, unit: item.unit, isImplant: item.isImplant, firm: { id: item.firm.id, name: item.firm.name } },
                  quantity: body.quantity,
                  comment: body.comment ?? "",
                },
              ],
            };
          }),
        }));
      }
      return { previous, tempId, targetKind, targetId };
    },
    onSuccess: (created, _body, ctx) => {
      if (!ctx) return;
      setEncodingCache((current) => ({
        ...current,
        entries: (current.entries ?? []).map((e) => {
          if (e.kind !== ctx.targetKind || e.id !== ctx.targetId) return e;
          return { ...e, materialLines: (e.materialLines ?? []).map((l) => l.id === ctx.tempId ? created : l) };
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
        entries: (current.entries ?? []).map((e) => ({
          ...e,
          materialLines: (e.materialLines ?? []).map((l) => {
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
        entries: (current.entries ?? []).map((e) => ({
          ...e,
          materialLines: (e.materialLines ?? []).map((l) => l.id === updated.id ? updated : l),
        })),
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
        entries: (current.entries ?? []).map((e) => ({
          ...e,
          materialLines: (e.materialLines ?? []).filter((l) => l.id !== lineId),
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
    onMutate: async (body) => {
      await queryClient.cancelQueries({ queryKey: ["missionEncoding", missionId] });
      const previous = queryClient.getQueryData(["missionEncoding", missionId]);
      setOpenRequestDialog(false);
      const targetKind: MaterialTarget["kind"] = body.missionInterventionId != null ? "INTERVENTION" : "DRAFT";
      const targetId = (body.missionInterventionId ?? body.interventionDraftId)!;
      const tempId = -Date.now();
      setEncodingCache((current) => ({
        ...current,
        entries: (current.entries ?? []).map((e) => {
          if (e.kind !== targetKind || e.id !== targetId) return e;
          return {
            ...e,
            materialItemRequests: [
              ...(e.materialItemRequests ?? []),
              { id: tempId, label: body.label, referenceCode: body.referenceCode ?? "", comment: body.comment ?? "" },
            ],
          };
        }),
      }));
      return { previous, tempId, targetKind, targetId };
    },
    onSuccess: (created, _body, ctx) => {
      if (!ctx) return;
      setEncodingCache((current) => ({
        ...current,
        entries: (current.entries ?? []).map((e) => {
          if (e.kind !== ctx.targetKind || e.id !== ctx.targetId) return e;
          return {
            ...e,
            materialItemRequests: (e.materialItemRequests ?? []).map((r) =>
              r.id === ctx.tempId ? { ...r, id: created.id } : r,
            ),
          };
        }),
      }));
      toast.success("Demande envoyée au manager.");
      invalidate();
    },
    onError: (err: any, _body, ctx) => {
      if (ctx?.previous) queryClient.setQueryData(["missionEncoding", missionId], ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  const isBusy =
    createInterventionMutation.isPending || patchInterventionMutation.isPending ||
    deleteInterventionMutation.isPending || createLineMutation.isPending ||
    patchLineMutation.isPending || deleteLineMutation.isPending || createRequestMutation.isPending ||
    createDraftMutation.isPending;

  const sorted = sortEntries(entries ?? []);
  const isOpen = (entry: MissionEncodingEntry) => (openIds ?? new Set(sorted.length ? [entryKey(sorted[0])] : [])).has(entryKey(entry));

  // Lot 6 : suggestedMaterials/coherence n'existent que sur le champ transitoire
  // `legacyInterventions` (voir docblock de la prop) — jamais utilisé pour la liste ou
  // l'ordre, uniquement pour enrichir une entrée INTERVENTION déjà présente dans `entries`.
  const legacyById = React.useMemo(() => {
    const map = new Map<number, EncodingIntervention>();
    for (const itv of legacyInterventions ?? []) map.set(itv.id, itv);
    return map;
  }, [legacyInterventions]);

  // "Marques récentes" du wizard — dérivé des marques déjà utilisées ailleurs dans
  // cette mission (données réelles, interventions ET drafts), jamais une liste inventée.
  const recentFirmIds = React.useMemo(() => {
    const ids = new Set<number>();
    for (const entry of sorted) {
      for (const line of entry.materialLines ?? []) {
        if (line.item?.firm?.id != null) ids.add(line.item.firm.id);
      }
    }
    return Array.from(ids);
  }, [sorted]);

  const materialTotal = sorted.reduce((sum, e) => sum + (e.materialLines?.length ?? 0), 0);
  const countsLabel = `${sorted.length} intervention${sorted.length > 1 ? "s" : ""} · ${materialTotal} matériel${materialTotal > 1 ? "s" : ""}`;

  // Stepper inline ±1 sur chaque ligne (prototypes/encodage-react) — ajustement rapide de
  // la quantité, jamais de suppression implicite (borne min 1, la suppression reste un
  // choix explicite via ConfirmDeleteDialog, ouvert depuis EditMaterialLineDialog).
  function bumpQty(line: EncodingMaterialLine, delta: number) {
    const current = parseFloat(line.quantity) || 0;
    const next = Math.max(1, current + delta);
    if (next === current) return;
    patchLineMutation.mutate({ lineId: line.id, body: { quantity: String(next) } });
  }

  return (
    <Stack spacing={1.75}>
      {/* Header */}
      <Stack direction="row" alignItems="center" sx={{ gap: "12px" }}>
        <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700, whiteSpace: "nowrap", flexShrink: 0 }}>
          INTERVENTIONS
        </Box>
        <Box sx={{ flex: 1, borderTop: "1px dashed", borderColor: "grey.300" }} />
        <Box sx={{ fontSize: 12.5, color: GRAY_500, whiteSpace: "nowrap", flexShrink: 0, fontVariantNumeric: "tabular-nums" }}>
          {countsLabel}
        </Box>
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
        sorted.map((entry) => {
          const lines = entry.materialLines ?? [];
          const open = isOpen(entry);
          const readOnly = entry.readOnly;
          const editableHere = canEdit && !readOnly;
          const badge = statusBadge(entry);
          const firmName = entry.firm?.name ?? entry.requestedFirmNameSnapshot ?? null;
          const legacy = entry.kind === "INTERVENTION" ? legacyById.get(entry.id) : undefined;

          return (
            <Box key={entryKey(entry)} sx={{ background: "#fff", borderRadius: "16px", boxShadow: SHADOW_XS, overflow: "hidden", opacity: readOnly ? 0.85 : 1 }}>
              {/* En-tête accordéon */}
              <Box
                component="button"
                type="button"
                onClick={() => toggleOpen(entryKey(entry))}
                sx={{
                  width: "100%", display: "flex", alignItems: "center", gap: "11px", padding: "15px 16px",
                  border: "none", background: "transparent", cursor: "pointer", fontFamily: "inherit", textAlign: "left",
                }}
              >
                <Box sx={{ width: 8, height: 8, borderRadius: "999px", background: readOnly ? GRAY_500 : GREEN_500, flexShrink: 0, mt: entry.kind === "DRAFT" ? "3px" : 0, alignSelf: entry.kind === "DRAFT" ? "flex-start" : "center" }} />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Box sx={{ display: "flex", alignItems: "center", gap: "8px" }}>
                    <Box sx={{ fontSize: 15, fontWeight: 700, color: TEXT_STRONG, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                      {entry.label}
                    </Box>
                    {isPending(entry.id) && (
                      <Typography component="span" variant="caption" color="text.disabled" sx={{ fontStyle: "italic", flexShrink: 0 }}>
                        Enregistrement…
                      </Typography>
                    )}
                  </Box>
                  {entry.kind === "DRAFT" && (
                    <Box sx={{ display: "flex", alignItems: "center", gap: "8px", mt: "3px", flexWrap: "wrap" }}>
                      <Box sx={{ fontSize: 12, color: GRAY_500 }}>
                        {firmName ?? "Sans firme"}
                      </Box>
                      {badge && (
                        <Box sx={{ display: "inline-flex", alignItems: "center", height: 18, px: "7px", borderRadius: "999px", background: badge.bg, color: badge.fg, fontSize: 10.5, fontWeight: 700 }}>
                          {badge.label}
                        </Box>
                      )}
                    </Box>
                  )}
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
                  {lines.map((l) => {
                    const qtyNum = parseFloat(l.quantity) || 0;
                    return (
                    <Box
                      key={l.id}
                      component="div"
                      role={editableHere ? "button" : undefined}
                      tabIndex={editableHere ? 0 : undefined}
                      onClick={editableHere ? () => { if (!isBusy) setEditLineTarget({ line: l }); } : undefined}
                      onKeyDown={editableHere ? (e: React.KeyboardEvent) => {
                        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); if (!isBusy) setEditLineTarget({ line: l }); }
                      } : undefined}
                      aria-label={editableHere ? `Modifier ${l.item?.label ?? "ce matériel"}` : undefined}
                      sx={{
                        width: "100%", display: "flex", alignItems: "center", gap: "8px", py: "10px",
                        border: "none", borderTop: "1px dashed", borderColor: "grey.150", background: "transparent",
                        fontFamily: "inherit", textAlign: "left", cursor: editableHere ? "pointer" : "default",
                        "&:hover": editableHere ? { background: GREEN_50 } : undefined,
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

                      {editableHere ? (
                        <Stack direction="row" alignItems="center" spacing="6px" sx={{ flexShrink: 0 }}>
                          <Box
                            component="button"
                            type="button"
                            aria-label={`Diminuer la quantité de ${l.item?.label ?? "ce matériel"}`}
                            onClick={(e) => { e.stopPropagation(); bumpQty(l, -1); }}
                            disabled={isBusy || qtyNum <= 1}
                            sx={{
                              width: 26, height: 26, border: "1.5px solid", borderColor: GRAY_200, borderRadius: "8px",
                              background: "#fff", color: GRAY_700, fontSize: 13, fontWeight: 700, cursor: "pointer",
                              display: "flex", alignItems: "center", justifyContent: "center", fontFamily: "inherit",
                              "&:hover": { borderColor: GREEN_500 },
                              "&:disabled": { opacity: 0.4, cursor: "default" },
                            }}
                          >
                            −
                          </Box>
                          <Typography
                            variant="body2"
                            fontWeight={700}
                            sx={{ color: GRAY_700, fontVariantNumeric: "tabular-nums", minWidth: 20, textAlign: "center" }}
                          >
                            x{displayQty(l.quantity)}
                          </Typography>
                          <Box
                            component="button"
                            type="button"
                            aria-label={`Augmenter la quantité de ${l.item?.label ?? "ce matériel"}`}
                            onClick={(e) => { e.stopPropagation(); bumpQty(l, 1); }}
                            disabled={isBusy}
                            sx={{
                              width: 26, height: 26, border: "1.5px solid", borderColor: GRAY_200, borderRadius: "8px",
                              background: "#fff", color: GRAY_700, fontSize: 13, fontWeight: 700, cursor: "pointer",
                              display: "flex", alignItems: "center", justifyContent: "center", fontFamily: "inherit",
                              "&:hover": { borderColor: GREEN_500 },
                              "&:disabled": { opacity: 0.4, cursor: "default" },
                            }}
                          >
                            +
                          </Box>
                        </Stack>
                      ) : (
                        <Typography
                          variant="body2"
                          fontWeight={700}
                          sx={{ color: GRAY_500, fontVariantNumeric: "tabular-nums", flexShrink: 0 }}
                        >
                          {displayQty(l.quantity)} {l.item?.unit ?? ""}
                        </Typography>
                      )}
                    </Box>
                    );
                  })}

                  {/* Demandes en attente ("À préciser") */}
                  {(entry.materialItemRequests ?? []).map((req) => (
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
                      {isPending(req.id) ? (
                        <Box sx={{ fontSize: 11, fontWeight: 600, color: GRAY_500, fontStyle: "italic", flexShrink: 0 }}>
                          Enregistrement…
                        </Box>
                      ) : (
                        <Box sx={{ display: "inline-flex", alignItems: "center", height: 20, px: "8px", borderRadius: "999px", background: "#FEF6E7", color: "#B7791F", fontSize: 11, fontWeight: 700, flexShrink: 0 }}>
                          À préciser
                        </Box>
                      )}
                    </Stack>
                  ))}

                  {/* Matériels suggérés non encore ajoutés (Lot 6) — jamais obligatoires,
                      un clic les ajoute directement (mêmes item/firme déjà connus, pas
                      besoin de repasser par l'assistant 3 étapes). Uniquement pour une
                      intervention réelle (voir docblock legacyInterventions). */}
                  {editableHere && legacy && (legacy.suggestedMaterials ?? [])
                    .filter((sm) => (legacy.coherence?.unusedSuggestedMaterialItemIds ?? []).includes(sm.id))
                    .map((sm) => (
                      <Stack
                        key={`suggested-${sm.id}`}
                        direction="row"
                        spacing={1}
                        alignItems="center"
                        sx={{ py: "10px", borderTop: "1px dashed", borderColor: "grey.150" }}
                      >
                        <Stack sx={{ flex: 1, minWidth: 0 }}>
                          <Typography variant="body2" noWrap>{sm.label}</Typography>
                          <Typography variant="caption" color="text.secondary" noWrap>
                            Suggéré — {sm.firm.name}
                          </Typography>
                        </Stack>
                        <Box
                          component="button"
                          type="button"
                          disabled={isBusy}
                          onClick={() => createLineMutation.mutate({ missionInterventionId: entry.id, itemId: sm.id, quantity: "1" })}
                          sx={{
                            border: "1px solid", borderColor: GREEN_500, borderRadius: "999px", background: "#fff",
                            color: GREEN_700, fontSize: 12, fontWeight: 700, px: "10px", py: "4px", cursor: "pointer",
                            fontFamily: "inherit", flexShrink: 0, "&:hover": { background: GREEN_50 },
                          }}
                        >
                          Ajouter
                        </Box>
                      </Stack>
                    ))}

                  {lines.length === 0 && (entry.materialItemRequests ?? []).length === 0 && (
                    <Typography variant="body2" color="text.secondary" sx={{ py: "10px" }}>
                      Aucun matériel encodé
                    </Typography>
                  )}

                  {editableHere && (
                    <Box
                      component="button"
                      type="button"
                      onClick={() => { setPreferredTarget(entryTarget(entry)); setOpenAddMaterial(true); }}
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

                  {/* Modifier/Supprimer : uniquement pour une intervention réelle (le
                      workflow manager frontend pour les drafts est hors périmètre de ce
                      commit) et jamais sur une entrée en lecture seule. */}
                  {editableHere && entry.kind === "INTERVENTION" && (
                    <Stack direction="row" spacing={2} sx={{ py: "10px", borderTop: "1px dashed", borderColor: "grey.150" }}>
                      <Box
                        component="button"
                        type="button"
                        onClick={() => setEditIntervention(entry)}
                        disabled={isBusy}
                        sx={{ border: "none", background: "none", p: 0, color: GRAY_500, fontFamily: "inherit", fontSize: 12.5, fontWeight: 600, cursor: "pointer", textDecoration: "underline" }}
                      >
                        Modifier l'intervention
                      </Box>
                      <Box
                        component="button"
                        type="button"
                        onClick={() => setDeleteInterventionTarget(entry)}
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
        loadingIntervention={createInterventionMutation.isPending}
        loadingDraft={createDraftMutation.isPending}
        interventionTypes={catalog?.interventionTypes ?? []}
        firms={catalog?.firms ?? []}
        existingCount={sorted.length}
        onClose={() => (isBusy ? null : setOpenAddIntervention(false))}
        onSubmit={(values) => createInterventionMutation.mutate(values)}
        onSubmitDraft={(values: DraftSubmitValues) => createDraftMutation.mutate(values)}
      />

      <EditInterventionDialog
        open={!!editIntervention}
        loading={patchInterventionMutation.isPending}
        intervention={editIntervention}
        interventionTypes={catalog?.interventionTypes ?? []}
        firms={catalog?.firms ?? []}
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
        message={deleteInterventionTarget?.label ?? ""}
        onClose={() => (isBusy ? null : setDeleteInterventionTarget(null))}
        onConfirm={() => { if (!deleteInterventionTarget) return; deleteInterventionMutation.mutate(deleteInterventionTarget.id); }}
        helpTopicId="mission-encoding"
      />

      <MaterialWizard
        open={openAddMaterial}
        loading={createLineMutation.isPending}
        target={preferredTarget}
        catalog={catalog}
        recentFirmIds={recentFirmIds}
        onClose={() => (isBusy ? null : setOpenAddMaterial(false))}
        onSubmit={(values) => createLineMutation.mutate(values)}
        onNotFound={(target) => {
          setPreferredRequestTarget(target ?? preferredTarget);
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
        helpTopicId="mission-encoding"
      />

      <MaterialItemRequestDialog
        open={openRequestDialog}
        loading={createRequestMutation.isPending}
        entries={sorted}
        preferredTarget={preferredRequestTarget}
        onClose={() => (isBusy ? null : setOpenRequestDialog(false))}
        onSubmit={(values) => createRequestMutation.mutate(values)}
      />
    </Stack>
  );
}

/** Tri exclusivement par orderIndex, puis secondaire déterministe (kind puis id) — même
 *  convention que le backend (MissionEncodingService::buildEncodingDto()) : en cas
 *  d'égalité d'orderIndex anormale, l'ordre reste stable plutôt que dépendant de
 *  l'itération JS, sans jamais masquer l'incohérence. */
function sortEntries(entries: MissionEncodingEntry[]): MissionEncodingEntry[] {
  return entries.slice().sort((a, b) => {
    if (a.orderIndex !== b.orderIndex) return (a.orderIndex ?? 0) - (b.orderIndex ?? 0);
    if (a.kind !== b.kind) return a.kind < b.kind ? -1 : 1;
    return a.id - b.id;
  });
}
