import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Box } from "@mui/material";

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
import ConfirmChoiceChangeDialog from "./ConfirmChoiceChangeDialog";
import MaterialWizard, { type MaterialTarget } from "./MaterialWizard";
import EditMaterialLineDialog from "./EditMaterialLineDialog";
import MaterialItemRequestDialog from "./MaterialItemRequestDialog";
import { EncodingProgress } from "./EncodingProgress";
import { scrollElementToTop } from "../utils/cardScroll";

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
  /** Autorisation backend (allowedActions "submit") — pilote la présence du pied de
   *  validation, jamais un calcul de complétude côté client (voir onValidate). */
  canSubmit: boolean;
  /** Ouvre le récapitulatif final (SubmitDialog) — jamais de validation directe ici. */
  onValidate: () => void;
};

const GREEN_900 = "#144D38";
const GREEN_800 = "#1F6B4F";
const GREEN_700 = "#2C7D5F";
const GREEN_600 = "#338F6E";
const GREEN_500 = "#42A882";
const GREEN_300 = "#8FDABF";
const GREEN_200 = "#BCE9D6";
const GREEN_100 = "#DDF4EA";
const GREEN_50 = "#EFFAF5";
const GRAY_950 = "#0B1320";
const GRAY_700 = "#3A4754";
const GRAY_500 = "#727E8C";
const GRAY_400 = "#98A2AE";
const GRAY_200 = "#DDE2E8";
const GRAY_150 = "#E7EBEF";
const GRAY_75 = "#F1F4F7";
const AMBER_500 = "#F0A91B";
const AMBER_100 = "#FBEACB";
const AMBER_50 = "#FEF6E7";
const AMBER_700 = "#B7791F";
const BLUE_700 = "#1B5FD0";
const BLUE_100 = "#D6E6FE";
const BLUE_50 = "#EDF4FF";
const RED_700 = "#C62F36";
const RED_100 = "#FAD7D8";
const RED_50 = "#FDEEEE";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";
const SHADOW_MD = "0 2px 6px rgba(22,32,43,.06), 0 8px 20px rgba(22,32,43,.08)";

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

function plural(n: number, singular: string, pluralWord: string): string {
  return `${n} ${n > 1 ? pluralWord : singular}`;
}

function sumUnits(lines: EncodingMaterialLine[]): number {
  return lines.reduce((a, l) => a + (parseFloat(l.quantity) || 0), 0);
}

function initials(name: string): string {
  return name.split(/[\s&·-]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();
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

/** Adapté au bandeau vert foncé (DOCUMENTATION §5) — mêmes règles métier que l'ancien
 *  statusBadge(), teintes ajustées pour rester lisibles sur fond sombre. */
function draftBadge(entry: MissionEncodingEntry): { label: string; bg: string; fg: string } | null {
  if (entry.kind === "INTERVENTION") return null;
  if (entry.status === "KEPT_AS_HISTORY") {
    return { label: "Conservé comme historique", bg: "rgba(255,255,255,.14)", fg: "rgba(255,255,255,.75)" };
  }
  return { label: "En attente de validation manager", bg: "rgba(255,255,255,.16)", fg: AMBER_100 };
}

/** Trouve, en ordre chronologique, la dernière entrée sans matériel — celle que
 *  DOCUMENTATION §11 demande d'ouvrir au chargement. Repli sur la plus récente
 *  (dernière chronologique, affichée en premier une fois la liste inversée) si tout
 *  est déjà encodé ou si la liste est vide. */
function defaultOpenKey(sorted: MissionEncodingEntry[]): string | null {
  if (sorted.length === 0) return null;
  for (let i = sorted.length - 1; i >= 0; i--) {
    if ((sorted[i].materialLines?.length ?? 0) === 0) return entryKey(sorted[i]);
  }
  return entryKey(sorted[sorted.length - 1]);
}

function PlusIcon({ size = 17, stroke = 2.6 }: { size?: number; stroke?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={stroke} strokeLinecap="round">
      <path d="M12 5v14M5 12h14" />
    </svg>
  );
}
function CheckIcon({ size = 19, stroke = 3 }: { size?: number; stroke?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={stroke} strokeLinecap="round" strokeLinejoin="round">
      <path d="m5 13 4 4L19 7" />
    </svg>
  );
}
function ChevronDownIcon({ open }: { open: boolean }) {
  return (
    <svg
      width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round"
      style={{ transition: "transform 260ms cubic-bezier(.22,1,.36,1)", transform: open ? "rotate(180deg)" : "none" }}
    >
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}
function TrashIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
    </svg>
  );
}
function WarnIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={AMBER_700} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 2.4 18a1.8 1.8 0 0 0 1.6 2.7h16a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0Z" />
    </svg>
  );
}

export default function InterventionsSection({ missionId, canEdit, entries, legacyInterventions, catalog, onSaved, canSubmit, onValidate }: Props) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [openAddIntervention, setOpenAddIntervention] = React.useState(false);
  const [editIntervention, setEditIntervention] = React.useState<MissionEncodingInterventionEntry | null>(null);
  const [deleteInterventionTarget, setDeleteInterventionTarget] = React.useState<MissionEncodingInterventionEntry | null>(null);
  // Tarification firme conditionnée à un choix obligatoire, §8 du prompt — 409
  // CHOICE_OPTION_CHANGE_REQUIRES_CONFIRMATION intercepté ici pour reproposer le même
  // PATCH avec confirmRemoveIncompatibleMaterial=true après confirmation explicite.
  const [choiceChangeConfirm, setChoiceChangeConfirm] = React.useState<{
    interventionId: number;
    body: PatchInterventionBody;
    message: string;
  } | null>(null);

  const [openAddMaterial, setOpenAddMaterial] = React.useState(false);
  const [preferredTarget, setPreferredTarget] = React.useState<MaterialTarget | null>(null);

  const [openRequestDialog, setOpenRequestDialog] = React.useState(false);
  const [preferredRequestTarget, setPreferredRequestTarget] = React.useState<MaterialTarget | null>(null);

  // Accordéon exclusif (DOCUMENTATION §1/§11) — `undefined` = jamais touché (on retombe
  // sur defaultOpenKey), `null` = explicitement tout fermé, sinon la clé ouverte.
  const [openKey, setOpenKey] = React.useState<string | null | undefined>(undefined);
  const cardRefs = React.useRef<Record<string, HTMLElement | null>>({});
  const listHeadRef = React.useRef<HTMLDivElement | null>(null);

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
        representativePresent: body.representativePresent ?? null,
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
    onSuccess: () => { toast.success("Intervention mise à jour"); setEditIntervention(null); setChoiceChangeConfirm(null); invalidate(); onSaved?.(); },
    onError: (err: any, args) => {
      const apiError = err?.response?.data?.error;
      if (apiError?.code === "CHOICE_OPTION_CHANGE_REQUIRES_CONFIRMATION") {
        setChoiceChangeConfirm({ interventionId: args.interventionId, body: args.body, message: apiError.message ?? "" });
        return;
      }
      toast.error(extractErrorMessage(err));
    },
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
  const effectiveOpenKey = openKey === undefined ? defaultOpenKey(sorted) : openKey;

  function toggle(entry: MissionEncodingEntry) {
    const key = entryKey(entry);
    const willOpen = effectiveOpenKey !== key;
    setOpenKey(willOpen ? key : null);
    scrollElementToTop(willOpen ? cardRefs.current[key] : listHeadRef.current);
  }

  // Lot 6 : suggestedMaterials/coherence n'existent que sur le champ transitoire
  // `legacyInterventions` (voir docblock de la prop) — jamais utilisé pour la liste ou
  // l'ordre, uniquement pour enrichir une entrée INTERVENTION déjà présente dans `entries`.
  const legacyById = React.useMemo(() => {
    const map = new Map<number, EncodingIntervention>();
    for (const itv of legacyInterventions ?? []) map.set(itv.id, itv);
    return map;
  }, [legacyInterventions]);

  const materialTotal = sorted.reduce((sum, e) => sum + (e.materialLines?.length ?? 0), 0);
  const doneCount = sorted.filter((e) => (e.materialLines?.length ?? 0) > 0).length;
  const countsLabel = `${plural(sorted.length, "intervention", "interventions")} · ${plural(materialTotal, "matériel", "matériels")}`;

  // Stepper inline ±1 sur chaque ligne (prototypes/encodage-react) — ajustement rapide de
  // la quantité, jamais de suppression implicite (borne min 1, la suppression reste un
  // choix explicite via ConfirmDeleteDialog).
  function bumpQty(line: EncodingMaterialLine, delta: number) {
    const current = parseFloat(line.quantity) || 0;
    const next = Math.max(1, current + delta);
    if (next === current) return;
    patchLineMutation.mutate({ lineId: line.id, body: { quantity: String(next) } });
  }

  // Numérotation chronologique AVANT inversion (DOCUMENTATION §1) : la carte du haut
  // peut porter un numéro qui n'est pas 1, les numéros ne bougent jamais.
  const numbered = sorted.map((entry, i) => ({ entry, index: i + 1 }));
  const reversedForDisplay = [...numbered].reverse();

  const allDone = sorted.length - doneCount === 0;

  return (
    <>
      <Box
        sx={{
          display: "flex", flexDirection: "column", gap: "14px",
          background: GREEN_50, border: "1px solid", borderColor: GREEN_200, borderRadius: "16px",
          padding: "12px 11px 13px",
          "@media (min-width:600px)": { borderRadius: "18px", padding: "13px 13px 14px" },
        }}
      >
        {/* En-tête de bac (zone 2) */}
        <Box sx={{ display: "flex", alignItems: "center", gap: "9px", padding: "0 3px" }}>
          <Box sx={{ width: 20, height: 20, flexShrink: 0, borderRadius: "6px", background: GREEN_700, color: "#fff", display: "grid", placeItems: "center", fontSize: 11.5, fontWeight: 800 }}>
            2
          </Box>
          <Box sx={{ flex: 1, fontSize: 11.5, fontWeight: 800, letterSpacing: ".1em", textTransform: "uppercase", color: GREEN_800 }}>
            Matériel par intervention
          </Box>
          <Box sx={{ flexShrink: 0, fontSize: 11.5, fontWeight: 700, color: GREEN_700, fontVariantNumeric: "tabular-nums" }}>
            {doneCount} / {sorted.length}
          </Box>
        </Box>

        {/* Sans intervention, "0 / 0 — Mission complète" serait faux : l'état vide
            ci-dessous suffit. */}
        {sorted.length > 0 && <EncodingProgress done={doneCount} total={sorted.length} />}

        {/* En-tête de liste */}
        <Box ref={listHeadRef} sx={{ display: "flex", alignItems: "center", gap: "12px" }}>
          <Box sx={{ flexShrink: 0, fontSize: 12, fontWeight: 800, letterSpacing: ".08em", textTransform: "uppercase", color: GREEN_700 }}>
            Interventions
          </Box>
          <Box sx={{ flex: 1, fontSize: 12, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
            {countsLabel}
          </Box>
          {canEdit && (
            <Box
              component="button"
              type="button"
              onClick={() => setOpenAddIntervention(true)}
              disabled={isBusy}
              aria-label="Nouvelle intervention"
              title="Ajouter une intervention"
              sx={{
                flexShrink: 0, display: "flex", alignItems: "center", gap: "8px", height: 40, padding: "0 15px 0 12px",
                border: 0, borderRadius: "12px", background: GREEN_600, color: "#fff", boxShadow: SHADOW_SM,
                fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer", transition: "background 120ms",
                "&:hover": { background: GREEN_700 },
              }}
            >
              <PlusIcon size={19} stroke={2.8} />
              Intervention
            </Box>
          )}
        </Box>

        {/* Empty state — un seul point d'entrée pour ajouter (le bouton d'en-tête
            ci-dessus), jamais un second bouton concurrent en pied de liste. */}
        {sorted.length === 0 ? (
          <Box sx={{ borderRadius: "12px", border: "1px dashed", borderColor: GRAY_200, padding: "32px 16px", textAlign: "center", color: GRAY_500, fontSize: 14 }}>
            Aucune intervention encodée
          </Box>
        ) : (
          <Box sx={{ display: "flex", flexDirection: "column", gap: "16px" }}>
            {reversedForDisplay.map(({ entry, index }) => {
              const key = entryKey(entry);
              const readOnly = entry.readOnly;
              const editableHere = canEdit && !readOnly;
              const legacy = entry.kind === "INTERVENTION" ? legacyById.get(entry.id) : undefined;

              return (
                <div key={key} ref={(el) => { cardRefs.current[key] = el; }}>
                  <EntryCard
                    entry={entry}
                    index={index}
                    open={effectiveOpenKey === key}
                    onToggle={() => toggle(entry)}
                    editableHere={editableHere}
                    readOnly={!!readOnly}
                    isBusy={isBusy}
                    newLineIds={newLineIds}
                    legacy={legacy}
                    onBumpQty={bumpQty}
                    onOpenEditLine={(line) => setEditLineTarget({ line })}
                    onDeleteLine={(line) => setDeleteLineTarget({ line })}
                    onAddMaterial={() => { setPreferredTarget(entryTarget(entry)); setOpenAddMaterial(true); }}
                    onAddSuggested={(itemId) => createLineMutation.mutate({ missionInterventionId: entry.id, itemId, quantity: "1" })}
                    onEditIntervention={() => setEditIntervention(entry as MissionEncodingInterventionEntry)}
                    onDeleteIntervention={() => setDeleteInterventionTarget(entry as MissionEncodingInterventionEntry)}
                  />
                </div>
              );
            })}
          </Box>
        )}

        {/* Pied de validation — n'apparaît que si le backend autorise "submit" (RBAC via
            Voters, jamais un calcul de complétude côté client). Clique toujours vers
            SubmitDialog, jamais de validation directe (docs/decisions.md). */}
        {canSubmit && (
          <Box
            sx={{
              display: "flex", alignItems: "center", gap: "14px", padding: "14px 16px", flexWrap: "wrap",
              borderRadius: "16px", background: GREEN_900, boxShadow: SHADOW_MD,
              "@media (min-width:600px)": { flexWrap: "nowrap" },
            }}
          >
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Box sx={{ fontSize: 15, fontWeight: 800, color: "#fff", fontVariantNumeric: "tabular-nums" }}>
                Terminer l'encodage · {doneCount}/{sorted.length}
              </Box>
              <Box sx={{ mt: "3px", fontSize: 12.5, fontWeight: 600, color: GREEN_300 }}>
                {sorted.length === 0
                  ? "Aucune intervention encodée."
                  : allDone
                  ? "Tout est encodé. Vous pouvez valider la mission."
                  : `Encore ${sorted.length - doneCount > 1 ? `${sorted.length - doneCount} interventions` : "1 intervention"} et la mission est complète.`}
              </Box>
            </Box>
            <Box
              component="button"
              type="button"
              onClick={onValidate}
              sx={{
                flex: "1 1 100%", display: "flex", alignItems: "center", justifyContent: "center", gap: "8px",
                height: 46, padding: "0 20px", border: 0, borderRadius: "12px", background: "#fff", color: GREEN_900,
                fontFamily: "inherit", fontSize: 14.5, fontWeight: 800, cursor: "pointer",
                "&:hover": { background: GREEN_50 },
                "@media (min-width:600px)": { flex: "none" },
              }}
            >
              {allDone && sorted.length > 0 && <CheckIcon size={17} stroke={2.8} />}
              Valider
            </Box>
          </Box>
        )}
      </Box>

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

      <ConfirmChoiceChangeDialog
        open={!!choiceChangeConfirm}
        loading={patchInterventionMutation.isPending}
        message={choiceChangeConfirm?.message ?? ""}
        onClose={() => (isBusy ? null : setChoiceChangeConfirm(null))}
        onConfirm={() => {
          if (!choiceChangeConfirm) return;
          patchInterventionMutation.mutate({
            interventionId: choiceChangeConfirm.interventionId,
            body: { ...choiceChangeConfirm.body, confirmRemoveIncompatibleMaterial: true },
          });
        }}
      />

      <MaterialWizard
        open={openAddMaterial}
        loading={createLineMutation.isPending}
        target={preferredTarget}
        catalog={catalog}
        recentFirmIds={recentFirmIds(sorted)}
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
    </>
  );
}

/** "Marques récentes" du wizard — dérivé des marques déjà utilisées ailleurs dans cette
 *  mission (données réelles, interventions ET drafts), jamais une liste inventée. */
function recentFirmIds(sorted: MissionEncodingEntry[]): number[] {
  const ids = new Set<number>();
  for (const entry of sorted) {
    for (const line of entry.materialLines ?? []) {
      if (line.item?.firm?.id != null) ids.add(line.item.firm.id);
    }
  }
  return Array.from(ids);
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

// ─────────────────────────────────────────────────────────────────────────
// Carte intervention — bandeau vert foncé, accordéon animé (DOCUMENTATION §5/§5bis).
// ─────────────────────────────────────────────────────────────────────────

type EntryCardProps = {
  entry: MissionEncodingEntry;
  /** Rang chronologique 1-based — pastille quand l'entrée est vide. */
  index: number;
  open: boolean;
  onToggle: () => void;
  editableHere: boolean;
  readOnly: boolean;
  isBusy: boolean;
  newLineIds: Set<number>;
  legacy?: EncodingIntervention;
  onBumpQty: (line: EncodingMaterialLine, delta: number) => void;
  onOpenEditLine: (line: EncodingMaterialLine) => void;
  onDeleteLine: (line: EncodingMaterialLine) => void;
  onAddMaterial: () => void;
  onAddSuggested: (itemId: number) => void;
  onEditIntervention: () => void;
  onDeleteIntervention: () => void;
};

function EntryCard({
  entry, index, open, onToggle, editableHere, readOnly, isBusy, newLineIds, legacy,
  onBumpQty, onOpenEditLine, onDeleteLine, onAddMaterial, onAddSuggested, onEditIntervention, onDeleteIntervention,
}: EntryCardProps) {
  const lines = entry.materialLines ?? [];
  const requests = entry.materialItemRequests ?? [];
  const done = lines.length > 0;
  const units = sumUnits(lines);
  const countLabel = done
    ? `${plural(lines.length, "matériel", "matériels")} · ${plural(Math.round(units), "unité", "unités")}`
    : "Aucun matériel encodé";
  const badge = draftBadge(entry);
  const firmName = entry.kind === "DRAFT" ? (entry.firm?.name ?? entry.requestedFirmNameSnapshot ?? null) : null;

  const bodyRef = React.useRef<HTMLDivElement | null>(null);
  const [height, setHeight] = React.useState(0);

  // Mesure du corps : à l'ouverture, quand le matériel change, et sur redimensionnement
  // (le titre peut passer sur deux lignes en rotation d'écran) — DOCUMENTATION §5bis.
  React.useLayoutEffect(() => {
    const el = bodyRef.current;
    if (!el) return;
    const measure = () => setHeight(el.scrollHeight);
    measure();
    if (typeof ResizeObserver === "undefined") {
      window.addEventListener("resize", measure);
      return () => window.removeEventListener("resize", measure);
    }
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [lines.length, requests.length, open]);

  const unusedSuggested = editableHere && legacy
    ? (legacy.suggestedMaterials ?? []).filter((sm) => (legacy.coherence?.unusedSuggestedMaterialItemIds ?? []).includes(sm.id))
    : [];

  return (
    <Box sx={{ background: "#fff", borderRadius: "16px", overflow: "hidden", boxShadow: SHADOW_MD, opacity: readOnly ? 0.85 : 1 }}>
      <Box
        component="button"
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        sx={{
          width: "100%", display: "flex", alignItems: "center", gap: "10px", padding: "13px", flexWrap: "wrap",
          border: 0, background: GREEN_800, fontFamily: "inherit", cursor: "pointer", textAlign: "left", transition: "background 120ms",
          "&:hover": { background: GREEN_900 },
          "@media (min-width:600px)": { gap: "14px", padding: "14px 16px", flexWrap: "nowrap" },
          "@media (min-width:900px)": { padding: "15px 18px" },
          "@media (min-width:1280px)": { padding: "16px 20px" },
          "@media (max-height:480px) and (orientation:landscape)": { padding: "11px 14px" },
        }}
      >
        {done ? (
          <Box aria-hidden sx={{ width: 34, height: 34, flexShrink: 0, borderRadius: "999px", background: "#fff", color: GREEN_700, display: "grid", placeItems: "center" }}>
            <CheckIcon size={19} stroke={3} />
          </Box>
        ) : (
          <Box aria-hidden sx={{
            width: 34, height: 34, flexShrink: 0, borderRadius: "10px", background: "rgba(255,255,255,.16)",
            border: "1.5px dashed rgba(255,255,255,.55)", color: "#fff", display: "grid", placeItems: "center",
            fontSize: 15, fontWeight: 800, fontVariantNumeric: "tabular-nums",
          }}>
            {index}
          </Box>
        )}

        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Box sx={{ fontSize: 15.5, fontWeight: 700, lineHeight: 1.3, color: "#fff", textWrap: "pretty", "@media (min-width:600px)": { fontSize: 16 }, "@media (min-width:900px)": { fontSize: 16.5 }, "@media (min-width:1280px)": { fontSize: 17 } }}>
            {entry.label}
            {isPending(entry.id) && (
              <Box component="span" sx={{ ml: "8px", fontSize: 11.5, fontWeight: 600, fontStyle: "italic", color: "rgba(255,255,255,.6)" }}>
                Enregistrement…
              </Box>
            )}
          </Box>
          <Box sx={{ mt: "4px", fontSize: 12.5, fontWeight: 600, color: GREEN_300, fontVariantNumeric: "tabular-nums" }}>
            {countLabel}
          </Box>
          {entry.kind === "DRAFT" && (
            <Box sx={{ mt: "4px", display: "flex", alignItems: "center", gap: "8px", flexWrap: "wrap" }}>
              <Box sx={{ fontSize: 11.5, color: "rgba(255,255,255,.7)" }}>{firmName ?? "Sans firme"}</Box>
              {badge && (
                <Box sx={{ display: "inline-flex", alignItems: "center", height: 18, px: "8px", borderRadius: "999px", background: badge.bg, color: badge.fg, fontSize: 10.5, fontWeight: 800 }}>
                  {badge.label}
                </Box>
              )}
            </Box>
          )}
        </Box>

        <Box sx={{
          flexShrink: 0, display: "inline-flex", alignItems: "center", height: 26, padding: "0 11px", borderRadius: "999px",
          fontSize: 11.5, fontWeight: 800, letterSpacing: ".03em", textTransform: "uppercase",
          background: done ? "rgba(255,255,255,.16)" : AMBER_500, color: done ? GREEN_100 : GRAY_950,
          order: 3, ml: "44px",
          "@media (min-width:600px)": { order: 0, ml: 0 },
        }}>
          {done ? "Complété" : "À compléter"}
        </Box>

        <Box aria-hidden sx={{ width: 34, height: 34, flexShrink: 0, borderRadius: "999px", background: "rgba(255,255,255,.14)", color: "#fff", display: "grid", placeItems: "center" }}>
          <ChevronDownIcon open={open} />
        </Box>
      </Box>

      <Box sx={{ overflow: "hidden", transition: "max-height 300ms cubic-bezier(.22,1,.36,1)", maxHeight: open ? height : 0 }} aria-hidden={!open}>
        <Box
          ref={bodyRef}
          sx={{
            opacity: open ? 1 : 0, transform: open ? "none" : "translateY(-6px)",
            transition: "opacity 200ms ease 40ms, transform 260ms cubic-bezier(.22,1,.36,1) 40ms",
          }}
        >
          <Box sx={{ padding: "6px 13px 0", "@media (min-width:600px)": { padding: "6px 16px 0" }, "@media (min-width:900px)": { padding: "6px 18px 0" }, "@media (min-width:1280px)": { padding: "8px 20px 0" } }}>
            {lines.map((l) => (
              <MaterialLineRow
                key={l.id}
                line={l}
                editable={editableHere}
                busy={isBusy}
                isNew={newLineIds.has(l.id)}
                tabIndex={open ? 0 : -1}
                onInc={() => onBumpQty(l, 1)}
                onDec={() => onBumpQty(l, -1)}
                onEdit={() => onOpenEditLine(l)}
                onRemove={() => onDeleteLine(l)}
              />
            ))}

            {requests.map((req) => (
              <MaterialRequestRow key={req.id} label={req.label} referenceCode={req.referenceCode} pending={isPending(req.id)} />
            ))}

            {unusedSuggested.map((sm) => (
              <SuggestedMaterialRow key={`suggested-${sm.id}`} label={sm.label} firmName={sm.firm.name} disabled={isBusy} tabIndex={open ? 0 : -1} onAdd={() => onAddSuggested(sm.id)} />
            ))}

            {!done && (
              <Box sx={{ display: "flex", alignItems: "center", gap: "10px", margin: "12px 0 2px", padding: "12px 14px", borderRadius: "12px", background: AMBER_50, border: "1px solid", borderColor: AMBER_100 }}>
                <Box sx={{ flexShrink: 0, display: "flex" }}><WarnIcon /></Box>
                <Box sx={{ fontSize: 13, fontWeight: 600, color: AMBER_700, textWrap: "pretty" }}>
                  Ajoutez le matériel utilisé pour valider cette intervention.
                </Box>
              </Box>
            )}
          </Box>

          {editableHere && (
            <Box sx={{
              display: "flex", alignItems: "center", gap: "10px", padding: "12px 13px 13px", flexWrap: "wrap",
              "@media (min-width:600px)": { padding: "12px 16px 14px", flexWrap: "nowrap" },
              "@media (min-width:900px)": { padding: "12px 18px 15px" },
              "@media (min-width:1280px)": { padding: "14px 20px 16px" },
            }}>
              <Box
                component="button"
                type="button"
                onClick={onAddMaterial}
                disabled={isBusy}
                tabIndex={open ? 0 : -1}
                sx={{
                  display: "flex", alignItems: "center", justifyContent: "center", gap: "8px",
                  flex: "1 1 100%", order: -1, height: 46, border: 0, borderRadius: "12px", background: GREEN_600, color: "#fff",
                  fontFamily: "inherit", fontSize: 14, fontWeight: 700, cursor: "pointer", transition: "background 120ms",
                  "&:hover": { background: GREEN_700 },
                  "@media (min-width:600px)": { flex: 1, order: 0 },
                }}
              >
                <PlusIcon size={17} stroke={2.6} />
                Ajouter du matériel
              </Box>
              {entry.kind === "INTERVENTION" && (
                <Box
                  component="button"
                  type="button"
                  onClick={onEditIntervention}
                  disabled={isBusy}
                  tabIndex={open ? 0 : -1}
                  sx={{
                    flexShrink: 0, height: 46, padding: "0 14px", border: "1px solid", borderColor: GRAY_200, borderRadius: "12px",
                    background: "#fff", color: GRAY_700, fontFamily: "inherit", fontSize: 13.5, fontWeight: 600, cursor: "pointer",
                    "&:hover": { background: GRAY_75 },
                  }}
                >
                  Modifier l'intervention
                </Box>
              )}
              {entry.kind === "INTERVENTION" && (
                <Box
                  component="button"
                  type="button"
                  onClick={onDeleteIntervention}
                  disabled={isBusy}
                  tabIndex={open ? 0 : -1}
                  aria-label="Supprimer l'intervention"
                  title="Supprimer l'intervention"
                  sx={{
                    flexShrink: 0, width: 46, height: 46, border: "1px solid", borderColor: GRAY_200, borderRadius: "12px",
                    background: "#fff", color: GRAY_500, display: "grid", placeItems: "center", cursor: "pointer",
                    "&:hover": { background: RED_50, color: RED_700, borderColor: RED_100 },
                  }}
                >
                  <TrashIcon />
                </Box>
              )}
            </Box>
          )}
        </Box>
      </Box>
    </Box>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Ligne matériel (DOCUMENTATION §7)
// ─────────────────────────────────────────────────────────────────────────

type MaterialLineRowProps = {
  line: EncodingMaterialLine;
  editable: boolean;
  busy: boolean;
  isNew: boolean;
  tabIndex: number;
  onInc: () => void;
  onDec: () => void;
  onEdit: () => void;
  onRemove: () => void;
};

function MaterialLineRow({ line, editable, busy, isNew, tabIndex, onInc, onDec, onEdit, onRemove }: MaterialLineRowProps) {
  const qtyNum = parseFloat(line.quantity) || 0;
  const name = line.item?.label ?? "—";
  const firmName = line.item?.firm?.name ?? "—";

  return (
    <Box
      role={editable ? "button" : undefined}
      tabIndex={editable ? tabIndex : undefined}
      onClick={editable ? onEdit : undefined}
      onKeyDown={editable ? (e: React.KeyboardEvent) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onEdit(); } } : undefined}
      aria-label={editable ? `Modifier ${name}` : undefined}
      sx={{
        display: "flex", alignItems: "center", gap: "12px", padding: "13px 0", borderBottom: "1px solid", borderColor: GRAY_150,
        flexWrap: "wrap", rowGap: "8px", cursor: editable ? "pointer" : "default",
        "&:hover": editable ? { background: GREEN_50 } : undefined,
        "@media (min-width:600px)": { flexWrap: "nowrap" },
      }}
    >
      <Box aria-hidden sx={{ width: 38, height: 38, flexShrink: 0, borderRadius: "10px", background: GREEN_50, border: "1px solid", borderColor: GREEN_200, color: GREEN_800, display: "grid", placeItems: "center", fontSize: 10.5, fontWeight: 800 }}>
        {initials(firmName)}
      </Box>
      <Box sx={{ flex: "1 1 60%", minWidth: 0, "@media (min-width:600px)": { flex: 1 } }}>
        <Box sx={{ display: "flex", alignItems: "center", gap: "8px", flexWrap: "wrap" }}>
          <Box sx={{ fontSize: 15, fontWeight: 700, color: GRAY_950 }}>{name}</Box>
          {line.item?.isImplant && (
            <Box sx={{ display: "inline-flex", alignItems: "center", height: 19, px: "8px", borderRadius: "999px", background: BLUE_50, border: "1px solid", borderColor: BLUE_100, color: BLUE_700, fontSize: 10.5, fontWeight: 800, letterSpacing: ".03em", textTransform: "uppercase" }}>
              Implant
            </Box>
          )}
          {isNew && (
            <Box sx={{ display: "inline-flex", alignItems: "center", height: 19, px: "8px", borderRadius: "999px", background: GREEN_100, color: GREEN_800, fontSize: 10.5, fontWeight: 800, letterSpacing: ".03em", textTransform: "uppercase" }}>
              Nouveau
            </Box>
          )}
        </Box>
        <Box sx={{ mt: "3px", fontSize: 12, color: GRAY_500 }}>
          {firmName}{line.item?.referenceCode ? ` · ${line.item.referenceCode}` : ""}
        </Box>
        {line.comment ? (
          <Box sx={{ mt: "2px", fontSize: 11.5, color: GRAY_400 }}>{line.comment}</Box>
        ) : null}
      </Box>

      {editable ? (
        <Box sx={{ flexShrink: 0, display: "flex", alignItems: "center", gap: "2px", background: GRAY_75, border: "1px solid", borderColor: GRAY_200, borderRadius: "11px", padding: "3px" }}>
          <Box
            component="button"
            type="button"
            aria-label={`Diminuer la quantité de ${name}`}
            onClick={(e) => { e.stopPropagation(); onDec(); }}
            disabled={busy || qtyNum <= 1}
            sx={{
              width: 32, height: 32, border: "1px solid", borderColor: GRAY_200, borderRadius: "8px", background: "#fff",
              color: GRAY_700, fontFamily: "inherit", fontSize: 16, fontWeight: 700, cursor: "pointer",
              display: "grid", placeItems: "center", transition: "border-color 120ms, color 120ms",
              "&:hover:not(:disabled)": { borderColor: GREEN_600, color: GREEN_700 },
              "&:disabled": { opacity: 0.45, cursor: "default" },
            }}
          >
            −
          </Box>
          <Box sx={{ minWidth: 38, textAlign: "center", fontSize: 15, fontWeight: 800, color: GRAY_950, fontVariantNumeric: "tabular-nums" }} aria-live="polite">
            x{displayQty(line.quantity)}
          </Box>
          <Box
            component="button"
            type="button"
            aria-label={`Augmenter la quantité de ${name}`}
            onClick={(e) => { e.stopPropagation(); onInc(); }}
            disabled={busy}
            sx={{
              width: 32, height: 32, border: "1px solid", borderColor: GRAY_200, borderRadius: "8px", background: "#fff",
              color: GRAY_700, fontFamily: "inherit", fontSize: 16, fontWeight: 700, cursor: "pointer",
              display: "grid", placeItems: "center", transition: "border-color 120ms, color 120ms",
              "&:hover:not(:disabled)": { borderColor: GREEN_600, color: GREEN_700 },
              "&:disabled": { opacity: 0.45, cursor: "default" },
            }}
          >
            +
          </Box>
        </Box>
      ) : (
        <Box sx={{ flexShrink: 0, fontSize: 15, fontWeight: 700, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
          {displayQty(line.quantity)} {line.item?.unit ?? ""}
        </Box>
      )}

      {editable && (
        <Box
          component="button"
          type="button"
          onClick={(e) => { e.stopPropagation(); onRemove(); }}
          disabled={busy}
          aria-label={`Retirer ${name}`}
          title="Retirer ce matériel"
          sx={{
            flexShrink: 0, width: 34, height: 34, border: "1px solid", borderColor: GRAY_200, borderRadius: "9px",
            background: "#fff", color: GRAY_400, display: "grid", placeItems: "center", cursor: "pointer",
            transition: "background 120ms, color 120ms, border-color 120ms",
            "&:hover": { background: RED_50, color: RED_700, borderColor: RED_100 },
          }}
        >
          <TrashIcon />
        </Box>
      )}
    </Box>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Demande hors catalogue ("À préciser") et matériel suggéré (Lot 6)
// ─────────────────────────────────────────────────────────────────────────

function MaterialRequestRow({ label, referenceCode, pending }: { label: string; referenceCode: string; pending: boolean }) {
  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "12px", padding: "13px 0", borderBottom: "1px solid", borderColor: GRAY_150 }}>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ fontSize: 15, fontWeight: 700, color: GRAY_950, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{label}</Box>
        {referenceCode && <Box sx={{ mt: "3px", fontSize: 12, color: GRAY_500 }}>Réf : {referenceCode}</Box>}
      </Box>
      {pending ? (
        <Box sx={{ flexShrink: 0, fontSize: 11, fontWeight: 600, fontStyle: "italic", color: GRAY_500 }}>Enregistrement…</Box>
      ) : (
        <Box sx={{ flexShrink: 0, display: "inline-flex", alignItems: "center", height: 20, px: "8px", borderRadius: "999px", background: AMBER_50, color: AMBER_700, fontSize: 11, fontWeight: 700 }}>
          À préciser
        </Box>
      )}
    </Box>
  );
}

function SuggestedMaterialRow({ label, firmName, disabled, tabIndex, onAdd }: { label: string; firmName: string; disabled: boolean; tabIndex: number; onAdd: () => void }) {
  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "12px", padding: "13px 0", borderBottom: "1px solid", borderColor: GRAY_150 }}>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ fontSize: 15, fontWeight: 700, color: GRAY_950, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{label}</Box>
        <Box sx={{ mt: "3px", fontSize: 12, color: GRAY_500 }}>Suggéré — {firmName}</Box>
      </Box>
      <Box
        component="button"
        type="button"
        disabled={disabled}
        tabIndex={tabIndex}
        onClick={onAdd}
        sx={{
          flexShrink: 0, border: "1px solid", borderColor: GREEN_500, borderRadius: "999px", background: "#fff",
          color: GREEN_700, fontSize: 12, fontWeight: 700, padding: "4px 10px", cursor: "pointer", fontFamily: "inherit",
          "&:hover": { background: GREEN_50 },
        }}
      >
        Ajouter
      </Box>
    </Box>
  );
}
