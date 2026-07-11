import * as React from "react";
import {
  Box, Button, Checkbox, Chip, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle,
  FormControlLabel, IconButton, Stack, Tooltip, Typography,
} from "@mui/material";
import RocketLaunchOutlinedIcon from "@mui/icons-material/RocketLaunchOutlined";
import SearchOutlinedIcon from "@mui/icons-material/SearchOutlined";
import CheckCircleOutlinedIcon from "@mui/icons-material/CheckCircleOutlined";
import ErrorOutlineOutlinedIcon from "@mui/icons-material/ErrorOutlineOutlined";
import SyncOutlinedIcon from "@mui/icons-material/SyncOutlined";
import EventBusyOutlinedIcon from "@mui/icons-material/EventBusyOutlined";
import CalendarTodayOutlinedIcon from "@mui/icons-material/CalendarTodayOutlined";
import ChevronRightOutlinedIcon from "@mui/icons-material/ChevronRightOutlined";
import CheckIcon from "@mui/icons-material/Check";
import EditOutlinedIcon from "@mui/icons-material/EditOutlined";
import ArrowBackOutlinedIcon from "@mui/icons-material/ArrowBackOutlined";
import DeleteOutlineOutlinedIcon from "@mui/icons-material/DeleteOutlineOutlined";
import { useMutation, useQuery } from "@tanstack/react-query";

import { fetchSites } from "../../sites/api/sites.api";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import { fetchMissions } from "../../missions/api/missions.api";
import {
  getSiteGroups, getSurgeonPosts, previewPlanningV2, generatePlanningV2, deployPlanningV2,
  applyModifications, cancelAllMissions, extractErrorV2,
} from "../api/planningV2.api";
import { listPlanningVersions, getAbsences } from "../../planning-manager/api/planning.api";
import type { PreviewLineStatus, PreviewLineV2, PreviewResponseV2 } from "../api/planningV2.types";
import {
  buildMonthChipIds, monthIdToYearMonth, mergePreviewResponses,
  aggregateGenerated, aggregateDeploy, type AggregatedGenerated, type AggregatedDeploy,
  severityOf, filterLines, countBySeverity, type SeverityFilter,
  groupLinesByDayAndSurgeon, formatDayHeader,
  lineKeyV2, getFreedInstrumentists, findSameDayAssignmentElsewhere, missionToPreviewLine,
} from "../api/generatePreviewGrouping";
import { useToast } from "../../../ui/toast/useToast";
import { SearchableSelect, type SearchableOption } from "../components/SearchableSelect";
import { Inspector, type NewMissionDraft } from "../components/Inspector";
import { PersonAvatar, EmptyAvatar } from "../../../ui/avatar/PersonAvatar";
import { buildProfilePictureUrl } from "../../manager-instrumentists/utils/instrumentists.utils";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";
import { apiClient } from "../../../api/apiClient";

type PlanningEditorMode = "generation" | "modification";

// Génération = medical blue (existing brand). Modification = amber — "attention, this is in
// production; your changes only take effect after redeploy" (handoff: MODES-Generation-vs-Modification.md).
const MODIFICATION_ACCENT = { main: "#B5761A", hover: "#7A4E12", bg: "#FBF6E9" };
const GENERATION_ACCENT = { main: planningV2Colors.brand, hover: planningV2Colors.brandHover, bg: planningV2Colors.infoBg };

const STATUS_TOKENS: Record<PreviewLineStatus, { label: string; fg: string; bg: string; dot: string; icon: React.ReactElement }> = {
  COVERED:   { label: "OK",                   fg: "#2C7D5F", bg: "#EFFAF5", dot: "#5BBE96", icon: <CheckCircleOutlinedIcon sx={{ fontSize: 14 }} /> },
  UNCOVERED: { label: "Mission ouverte",      fg: planningV2Colors.infoFg, bg: planningV2Colors.infoBg, dot: "#7AA0D4", icon: <SyncOutlinedIcon sx={{ fontSize: 14 }} /> },
  MODIFIED:  { label: "Modifié",              fg: "#3B6296", bg: "#EFF4FB", dot: "#7AA0D4", icon: <SyncOutlinedIcon sx={{ fontSize: 14 }} /> },
  CONFLICT:  { label: "Conflit",              fg: "#A8554F", bg: "#FBF2F1", dot: "#D58A84", icon: <ErrorOutlineOutlinedIcon sx={{ fontSize: 14 }} /> },
  SKIPPED:   { label: "Chirurgien absent",    fg: "#8A6420", bg: "#FAF5E9", dot: "#DBAB4E", icon: <EventBusyOutlinedIcon sx={{ fontSize: 14 }} /> },
};

const FILTER_CHIPS: Array<{ key: SeverityFilter; label: string; dot: string }> = [
  { key: "all", label: "Tout", dot: "#98A2AE" },
  { key: "ok", label: "OK", dot: "#5BBE96" },
  { key: "info", label: "Missions ouvertes", dot: "#7AA0D4" },
  { key: "warn", label: "À surveiller", dot: "#DBAB4E" },
  { key: "crit", label: "Conflits", dot: "#D58A84" },
];

const MONTH_LABELS = [
  "Janvier", "Février", "Mars", "Avril", "Mai", "Juin",
  "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre",
];

function defaultYearMonth(): { year: number; month: number } {
  const now = new Date();
  return { year: now.getFullYear(), month: now.getMonth() + 1 };
}

export function GeneratePlanningTab() {
  const toast = useToast();
  const { year: defYear, month: defMonth } = defaultYearMonth();

  const monthChipIds = React.useMemo(() => buildMonthChipIds({ year: defYear, month: defMonth }, 6), [defYear, defMonth]);
  const [selectedMonthIds, setSelectedMonthIds] = React.useState<number[]>([monthChipIds[0]]);
  const [targetId, setTargetId] = React.useState<number | null>(null);

  const [preview, setPreview] = React.useState<PreviewResponseV2 | null>(null);
  // Per-month raw responses, aligned with selectedMonthIds at the time of preview — each
  // generate() call is scoped to one month and needs its own previewVersion token; the merged
  // `preview` above only keeps the first month's token (see mergePreviewResponses), so it can't
  // be reused for later months.
  const [previewResponses, setPreviewResponses] = React.useState<PreviewResponseV2[]>([]);
  const [generated, setGenerated] = React.useState<AggregatedGenerated | null>(null);
  const [deployed, setDeployed] = React.useState<AggregatedDeploy | null>(null);
  const [sendPdf, setSendPdf] = React.useState(true);
  const [genFilter, setGenFilter] = React.useState<SeverityFilter>("all");

  // ── Mode Modification — editing an already-generated/deployed PlanningVersion in place ────
  // Same editor, same state shape as Génération; only the data source, palette and CTAs differ.
  // See handoff `MODES-Generation-vs-Modification.md` and docs/planning-v2-architecture-freeze.md §L
  // ("planning vivant": post-deploy changes mutate Missions directly, never a new generate/deploy cycle).
  const [modificationVersionId, setModificationVersionId] = React.useState<number | null>(null);
  const [modificationLabel, setModificationLabel] = React.useState<string | null>(null);
  const [newLines, setNewLines] = React.useState<PreviewLineV2[]>([]);
  const [isCreatingMission, setIsCreatingMission] = React.useState(false);
  const [modificationApplied, setModificationApplied] = React.useState<{ created: number; updated: number; cancelled: number; released: number; unchanged: number } | null>(null);
  const [deleteMonthConfirmOpen, setDeleteMonthConfirmOpen] = React.useState(false);
  const nextDraftIdRef = React.useRef(-1);

  const mode: PlanningEditorMode = modificationVersionId !== null ? "modification" : "generation";
  const isModification = mode === "modification";
  const accent = isModification ? MODIFICATION_ACCENT : GENERATION_ACCENT;

  // ── Preview Editor: local instrumentist reassignment before generate/redeploy ────────────
  const [editedLines, setEditedLines] = React.useState<Map<string, Partial<PreviewLineV2>>>(new Map());
  const [selectedKeys, setSelectedKeys] = React.useState<Set<string>>(new Set());
  const [bulkInstrumentistId, setBulkInstrumentistId] = React.useState<number | "">("");
  const [selectedLineKey, setSelectedLineKey] = React.useState<string | null>(null);

  const instrumentistsQuery = useQuery({
    queryKey: ["instrumentists-all"],
    queryFn: async () => {
      const r = await apiClient.get("/api/instrumentists", { params: { active: true } });
      return r.data.items as { id: number; displayName: string; profilePicturePath?: string | null }[];
    },
    staleTime: 5 * 60_000,
  });
  const instrumentists = instrumentistsQuery.data ?? [];
  const instrumentistOptions: SearchableOption[] = instrumentists.map((i) => ({
    id: i.id, label: i.displayName, avatarUrl: buildProfilePictureUrl(i.profilePicturePath) ?? null,
  }));

  // Absences/reassignment annotations for the line currently selected in the inspector are
  // computed further below, once `selectedLine` is resolved from effectiveLines.

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const groupsQuery = useQuery({ queryKey: ["planning-v2", "site-groups"], queryFn: getSiteGroups });
  const surgeonsQuery = useQuery({ queryKey: ["surgeons-all"], queryFn: () => getSurgeons({ active: true }), staleTime: 5 * 60_000 });
  const surgeonOptions: SearchableOption[] = (surgeonsQuery.data?.items ?? []).map((s) => ({
    id: s.id, label: s.displayName, avatarUrl: buildProfilePictureUrl(s.profilePicturePath) ?? null,
  }));
  const siteOptionsForCreate: SearchableOption[] = (sitesQuery.data ?? []).map((s) => ({ id: s.id, label: s.name }));

  // Mode Modification — loads the real Missions of the PlanningVersion being edited, mapped
  // to the same PreviewLineV2 shape so every render/filter/edit path below stays unchanged.
  const modificationMissionsQuery = useQuery({
    queryKey: ["planning-v2", "modification-missions", modificationVersionId],
    queryFn: async () => {
      const res = await fetchMissions(1, 500, { planningVersionId: modificationVersionId! });
      return res.items.filter((m) => m.status !== "REJECTED").map(missionToPreviewLine);
    },
    enabled: modificationVersionId !== null,
  });

  // Sites and site-groups share one searchable field — ids are offset for groups so
  // they stay distinguishable without inventing a second id namespace on the wire.
  const GROUP_ID_OFFSET = 1_000_000;
  const targetOptions: SearchableOption[] = React.useMemo(() => {
    const siteOpts = (sitesQuery.data ?? []).map((s) => ({ id: s.id, label: s.name, sub: "Site" }));
    const groupOpts = (groupsQuery.data?.items ?? []).map((g) => ({ id: g.id + GROUP_ID_OFFSET, label: g.name, sub: "Groupe de sites" }));
    return [...siteOpts, ...groupOpts];
  }, [sitesQuery.data, groupsQuery.data]);

  const target = React.useMemo(() => {
    if (targetId === null) return null;
    if (targetId >= GROUP_ID_OFFSET) return { siteId: null as number | null, siteGroupId: targetId - GROUP_ID_OFFSET };
    return { siteId: targetId, siteGroupId: null as number | null };
  }, [targetId]);

  const activePostsQuery = useQuery({
    queryKey: ["planning-v2", "active-posts-count", targetId],
    queryFn: () => getSurgeonPosts({
      siteId: target?.siteId ?? undefined,
      siteGroupId: target?.siteGroupId ?? undefined,
      active: true,
    }),
    enabled: !!target,
  });
  const activePostsCount = activePostsQuery.data?.items.length ?? 0;

  const historyQuery = useQuery({
    queryKey: ["planning-v2", "versions-history", targetId],
    queryFn: () => listPlanningVersions({ limit: 10, siteId: targetId !== null && targetId < GROUP_ID_OFFSET ? targetId : undefined }),
    enabled: !preview && !generated,
  });

  function toggleMonth(id: number) {
    setSelectedMonthIds((prev) => (prev.includes(id) ? prev.filter((m) => m !== id) : [...prev, id]));
  }

  function enterModification(version: { id: number; periodStart: string; site?: { name: string } | null }) {
    setPreview(null);
    setPreviewResponses([]);
    setGenerated(null);
    setDeployed(null);
    setGenFilter("all");
    setEditedLines(new Map());
    setSelectedKeys(new Set());
    setSelectedLineKey(null);
    setNewLines([]);
    setIsCreatingMission(false);
    setModificationApplied(null);
    const d = new Date(version.periodStart);
    setModificationLabel(`${MONTH_LABELS[d.getMonth()]} ${d.getFullYear()} · ${version.site?.name ?? "Tous sites"}`);
    setModificationVersionId(version.id);
  }

  function exitModification() {
    setModificationVersionId(null);
    setModificationLabel(null);
    setEditedLines(new Map());
    setSelectedKeys(new Set());
    setSelectedLineKey(null);
    setNewLines([]);
    setIsCreatingMission(false);
    setModificationApplied(null);
  }

  const previewMutation = useMutation({
    mutationFn: async () => {
      const months = selectedMonthIds.map(monthIdToYearMonth);
      const responses = await Promise.all(
        months.map((ym) => previewPlanningV2({ siteId: target!.siteId, siteGroupId: target!.siteGroupId, ...ym })),
      );
      return { merged: mergePreviewResponses(responses), responses };
    },
    onSuccess: ({ merged, responses }) => {
      setPreview(merged);
      setPreviewResponses(responses);
      setEditedLines(new Map());
      setSelectedKeys(new Set());
      setGenerated(null);
      setDeployed(null);
      setGenFilter("all");
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const generateMutation = useMutation({
    mutationFn: async () => {
      const months = selectedMonthIds.map(monthIdToYearMonth);
      const versions = [];
      for (let i = 0; i < months.length; i++) {
        const ym = months[i];
        const monthKey = `${ym.year}-${String(ym.month).padStart(2, "0")}`;
        const monthLines = effectiveLines.filter((l) => l.date.startsWith(monthKey));
        versions.push(await generatePlanningV2({
          siteId: target!.siteId,
          siteGroupId: target!.siteGroupId,
          ...ym,
          previewVersion: previewResponses[i]?.previewVersion,
          lines: monthLines.length > 0 ? monthLines : undefined,
        }));
      }
      return aggregateGenerated(versions);
    },
    onSuccess: (data) => {
      setGenerated(data);
      toast.success(`Brouillon créé — ${data.created} mission(s) créée(s)`);
    },
    onError: (err: any) => {
      if (err?.response?.status === 409 && err?.response?.data?.code === "PREVIEW_EXPIRED") {
        toast.error("Le planning a changé depuis la prévisualisation — régénérez l'aperçu.");
        resetGen();
      } else {
        toast.error(extractErrorV2(err));
      }
    },
  });

  const deployMutation = useMutation({
    mutationFn: async () => {
      const results = [];
      for (const v of generated!.versions) {
        results.push(await deployPlanningV2(v.versionId, sendPdf));
      }
      return aggregateDeploy(results);
    },
    onSuccess: (data) => {
      toast.success(`Planning déployé — ${data.missionCount} mission(s) publiée(s)`);
      setDeployed(data);
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const applyModsMutation = useMutation({
    mutationFn: () => applyModifications(modificationVersionId!, effectiveLines),
    onSuccess: async (result) => {
      const total = result.created + result.updated + result.cancelled + result.released;
      toast.success(total > 0 ? `Planning mis à jour — ${total} changement(s) appliqué(s)` : "Aucun changement à appliquer");
      setModificationApplied(result);
      setEditedLines(new Map());
      setNewLines([]);
      setSelectedLineKey(null);
      setIsCreatingMission(false);
      // The mutation itself is already confirmed applied server-side above — but the list
      // still shows what was on screen *before* the redeploy until this refetch lands. If it
      // fails (session expired between the two requests, network blip…), that stale view
      // would otherwise look identical to "nothing was saved" — surface it explicitly rather
      // than leaving the user staring at pre-edit data with no explanation.
      const refreshed = await modificationMissionsQuery.refetch();
      if (refreshed.isError) {
        toast.error("Les changements sont enregistrés, mais l'affichage n'a pas pu être actualisé — rechargez la page.");
      }
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const cancelAllMutation = useMutation({
    mutationFn: () => cancelAllMissions(modificationVersionId!),
    onSuccess: (result) => {
      setDeleteMonthConfirmOpen(false);
      toast.success(result.cancelled > 0 ? `${result.cancelled} mission(s) annulée(s) — mois supprimé` : "Rien à annuler, ce mois était déjà vide");
      exitModification();
    },
    onError: (err) => {
      setDeleteMonthConfirmOpen(false);
      toast.error(extractErrorV2(err));
    },
  });

  function resetGen() {
    setPreview(null);
    setPreviewResponses([]);
    setGenerated(null);
    setDeployed(null);
    setGenFilter("all");
    setEditedLines(new Map());
    setSelectedKeys(new Set());
  }

  // Génération sources lines from the backend Preview; Modification sources them from the real
  // Missions of the PlanningVersion being edited, plus any not-yet-applied local draft creations.
  const lines: PreviewLineV2[] = isModification
    ? [...(modificationMissionsQuery.data ?? []), ...newLines]
    : (preview?.lines ?? []);

  // Merge local edits (reassignment, schedule, cancel, release) over the base lines for display and submit.
  const effectiveLines = React.useMemo<PreviewLineV2[]>(
    () => lines.map((line) => {
      const patch = editedLines.get(lineKeyV2(line));
      return patch ? { ...line, ...patch } : line;
    }),
    [lines, editedLines],
  );

  const filteredLines = filterLines(effectiveLines, genFilter);
  const dayGroups = groupLinesByDayAndSurgeon(filteredLines);
  const severityCounts = countBySeverity(effectiveLines);
  const dirtyCount = editedLines.size;

  // ── Line edit helpers ──────────────────────────────────────────────────────

  function handleEditLine(key: string, patch: Partial<PreviewLineV2>) {
    setEditedLines((prev) => {
      const next = new Map(prev);
      next.set(key, { ...(next.get(key) ?? {}), ...patch });
      return next;
    });
  }

  function handleResetLine(key: string) {
    setEditedLines((prev) => {
      const next = new Map(prev);
      next.delete(key);
      return next;
    });
  }

  function handleInstrumentistChange(line: PreviewLineV2, newId: number | null) {
    const key = lineKeyV2(line);
    if (newId === null) {
      handleEditLine(key, { instrumentistId: null, instrumentistName: null, status: "UNCOVERED" });
    } else {
      const inst = instrumentists.find((i) => i.id === newId);
      handleEditLine(key, {
        instrumentistId: newId,
        instrumentistName: inst?.displayName ?? null,
        status: line.status === "SKIPPED" ? "UNCOVERED" : (line.status === "UNCOVERED" || line.status === "CONFLICT" ? "COVERED" : line.status),
      });

      // They can't be in two places the same day — clear their other slot instead of
      // silently double-booking them (non-blocking: the reassignment itself still goes through).
      const elsewhere = findSameDayAssignmentElsewhere(effectiveLines, line, newId);
      if (elsewhere) {
        handleEditLine(lineKeyV2(elsewhere), { instrumentistId: null, instrumentistName: null, status: "UNCOVERED" });
      }
    }
  }

  function toggleSelected(key: string) {
    setSelectedKeys((prev) => {
      const next = new Set(prev);
      next.has(key) ? next.delete(key) : next.add(key);
      return next;
    });
  }

  function handleBulkAssign() {
    if (bulkInstrumentistId === "") return;
    const inst = instrumentists.find((i) => i.id === bulkInstrumentistId);
    if (!inst) return;
    setEditedLines((prev) => {
      const next = new Map(prev);
      for (const key of selectedKeys) {
        const line = effectiveLines.find((l) => lineKeyV2(l) === key);
        if (!line || line.status === "SKIPPED") continue;
        next.set(key, {
          ...(next.get(key) ?? {}),
          instrumentistId: inst.id,
          instrumentistName: inst.displayName,
          status: line.status === "UNCOVERED" || line.status === "CONFLICT" ? "COVERED" : line.status,
        });
      }
      return next;
    });
    setSelectedKeys(new Set());
    setBulkInstrumentistId("");
  }

  function handleBulkSkip() {
    setEditedLines((prev) => {
      const next = new Map(prev);
      for (const key of selectedKeys) {
        next.set(key, { ...(next.get(key) ?? {}), status: "SKIPPED" as PreviewLineStatus });
      }
      return next;
    });
    setSelectedKeys(new Set());
  }

  // ── Schedule / cancel / release / create — Inspector actions ─────────────────────────────

  function handleScheduleChange(line: PreviewLineV2, patch: { startTime?: string; endTime?: string }) {
    handleEditLine(lineKeyV2(line), patch);
  }

  function handleCancelMission(line: PreviewLineV2) {
    // A line just drafted in this Modification session (existingMissionId null, staged in
    // newLines — never persisted) has nothing to "cancel" server-side: remove it outright
    // instead of marking it SKIPPED, which would still submit it to apply-modifications as
    // a brand-new mission (createFromLine() branches only on existingMissionId, not status).
    const key = lineKeyV2(line);
    if (isModification && newLines.some((l) => lineKeyV2(l) === key)) {
      setNewLines((prev) => prev.filter((l) => lineKeyV2(l) !== key));
      setEditedLines((prev) => {
        if (!prev.has(key)) return prev;
        const next = new Map(prev);
        next.delete(key);
        return next;
      });
      setSelectedLineKey((prev) => (prev === key ? null : prev));
      return;
    }
    handleEditLine(key, { status: "SKIPPED" });
  }

  function handleReleaseMission(line: PreviewLineV2) {
    handleInstrumentistChange(line, null);
  }

  function handleStartCreate() {
    setSelectedLineKey(null);
    setIsCreatingMission(true);
  }

  function handleCancelCreate() {
    setIsCreatingMission(false);
  }

  function handleSubmitCreate(draft: NewMissionDraft) {
    const surgeon = surgeonsQuery.data?.items.find((s) => s.id === draft.surgeonId);
    const site = sitesQuery.data?.find((s) => s.id === draft.siteId);
    const instrumentist = draft.instrumentistId !== null ? instrumentists.find((i) => i.id === draft.instrumentistId) : undefined;
    const draftId = nextDraftIdRef.current--;
    const newLine: PreviewLineV2 = {
      date: draft.date,
      postId: draftId,
      surgeonId: draft.surgeonId ?? 0,
      surgeonName: surgeon?.displayName ?? "—",
      missionType: draft.missionType,
      startTime: draft.startTime,
      endTime: draft.endTime,
      siteId: draft.siteId,
      siteName: site?.name ?? null,
      instrumentistId: draft.instrumentistId,
      instrumentistName: instrumentist?.displayName ?? null,
      status: draft.instrumentistId !== null ? "COVERED" : "UNCOVERED",
      existingMissionId: null,
      existingInstrumentistId: null,
      existingInstrumentistName: null,
      freedFrom: false,
    };
    setNewLines((prev) => [...prev, newLine]);
    setIsCreatingMission(false);
    setSelectedLineKey(lineKeyV2(newLine));
  }

  const selectedLine = selectedLineKey ? effectiveLines.find((l) => lineKeyV2(l) === selectedLineKey) ?? null : null;

  // Absences overlapping the day of the line currently selected in the inspector.
  const absencesQuery = useQuery({
    queryKey: ["absences-on-day", selectedLine?.date],
    queryFn: () => getAbsences({ from: selectedLine!.date, to: selectedLine!.date }),
    enabled: !!selectedLine,
    staleTime: 60_000,
  });
  const absentInstrumentistIds = React.useMemo(() => {
    const ids = new Set<number>();
    for (const a of absencesQuery.data ?? []) {
      if (a.user.role === "INSTRUMENTIST") ids.add(a.user.id);
    }
    return ids;
  }, [absencesQuery.data]);

  // Per-option annotations scoped to the line currently selected — absence and
  // same-day-elsewhere are both relative to *that* line's date, not a global property.
  const instrumentistOptionsForSelected: SearchableOption[] = React.useMemo(() => {
    if (!selectedLine) return instrumentistOptions;
    return instrumentistOptions.map((opt) => {
      const absent = absentInstrumentistIds.has(opt.id);
      const elsewhere = !absent && findSameDayAssignmentElsewhere(effectiveLines, selectedLine, opt.id);
      return {
        ...opt,
        muted: absent,
        badge: absent ? "En congé" : elsewhere ? "Déjà affecté ailleurs" : undefined,
      };
    });
  }, [instrumentistOptions, selectedLine, absentInstrumentistIds, effectiveLines]);

  const freedForSelected = selectedLine ? getFreedInstrumentists(effectiveLines, selectedLine) : [];

  const monthsLabel = selectedMonthIds.length === 0
    ? "Sélectionnez au moins un mois"
    : selectedMonthIds.length === 1
      ? `${MONTH_LABELS[monthIdToYearMonth(selectedMonthIds[0]).month - 1]} ${monthIdToYearMonth(selectedMonthIds[0]).year}`
      : `${selectedMonthIds.length} mois`;

  const stepIndex = deployed ? 3 : generated ? 2 : preview ? 1 : 0;

  return (
    <Box>
      <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={2} sx={{ mb: isModification ? 2.25 : 0 }}>
        <Box>
          {isModification && (
            <Box sx={{
              display: "inline-flex", alignItems: "center", gap: 0.6, fontSize: 11, fontWeight: 700,
              letterSpacing: "0.04em", textTransform: "uppercase", color: accent.main, bgcolor: accent.bg,
              px: 1.1, py: 0.35, borderRadius: planningV2Radii.pill, mb: 1,
            }}>
              Modification · Planning déployé
            </Box>
          )}
          <Typography sx={{ fontSize: 22, fontWeight: 800, letterSpacing: "-0.02em" }}>
            {isModification ? `Modifier le planning — ${modificationLabel}` : "Générer le planning"}
          </Typography>
          <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted, mt: 0.5 }}>
            {isModification
              ? "Vous retrouvez exactement le planning déployé. Vos changements ne prennent effet qu'après redéploiement."
              : "Prévisualisez, vérifiez, puis déployez les missions des mois sélectionnés."}
          </Typography>
        </Box>
        {isModification && (
          <Stack direction="row" spacing={1} sx={{ flexShrink: 0 }}>
            <Button
              startIcon={<DeleteOutlineOutlinedIcon sx={{ fontSize: 16 }} />}
              onClick={() => setDeleteMonthConfirmOpen(true)}
              sx={{ height: 38, px: 2, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, color: "#B42318", border: "1px solid #FDA29B", "&:hover": { bgcolor: "#FEF3F2" } }}
            >
              Supprimer ce mois
            </Button>
            <Button
              startIcon={<ArrowBackOutlinedIcon sx={{ fontSize: 16 }} />}
              onClick={exitModification}
              sx={{ height: 38, px: 2, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, color: planningV2Colors.textStrong, border: "1px solid #DDE2E8" }}
            >
              Quitter la modification
            </Button>
          </Stack>
        )}
      </Stack>

      <Dialog open={deleteMonthConfirmOpen} onClose={() => setDeleteMonthConfirmOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ fontSize: 16, fontWeight: 700 }}>Supprimer {modificationLabel} ?</DialogTitle>
        <DialogContent>
          <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted }}>
            Toutes les missions assignées ou ouvertes de ce mois seront <strong>annulées</strong>.
            Les chirurgiens et instrumentistes concernés en seront informés par email. Cette action
            n&apos;efface rien de l&apos;historique et n&apos;est pas automatiquement réversible.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2.5 }}>
          <Button onClick={() => setDeleteMonthConfirmOpen(false)} sx={{ textTransform: "none", fontWeight: 600 }}>
            Annuler
          </Button>
          <Button
            variant="contained" disableElevation color="error" disabled={cancelAllMutation.isPending}
            onClick={() => cancelAllMutation.mutate()}
            sx={{ textTransform: "none", fontWeight: 600 }}
          >
            {cancelAllMutation.isPending ? "Suppression…" : "Confirmer la suppression"}
          </Button>
        </DialogActions>
      </Dialog>

      {!isModification && (
        <>
          <Box sx={{ mt: 3 }} />
          <Stepper stepIndex={stepIndex} />

          <Box sx={{ mb: 2.75 }}>
            <Stack direction="row" alignItems="baseline" spacing={1} sx={{ mb: 1 }}>
              <Typography sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.textBody }}>Mois</Typography>
              <Typography sx={{ fontSize: 12, color: planningV2Colors.textSecondary }}>
                {selectedMonthIds.length === 0
                  ? "Sélectionnez au moins un mois"
                  : `${selectedMonthIds.length} mois · ${activePostsCount} poste${activePostsCount > 1 ? "s" : ""}`}
              </Typography>
            </Stack>
            <Stack direction="row" spacing={1} sx={{ flexWrap: "wrap" }} useFlexGap>
              {monthChipIds.map((id) => {
                const ym = monthIdToYearMonth(id);
                const selected = selectedMonthIds.includes(id);
                // Only an ACTIVE (currently live) version is eligible for Modification mode —
                // a DRAFT was never deployed (nothing to redeploy against post-deploy), and an
                // ARCHIVED one is already superseded by a newer ACTIVE version for this same
                // period+site (editing it would be a dead end: apply-modifications/cancel-all
                // both reject anything that isn't ACTIVE server-side).
                const matchedVersion = historyQuery.data?.items.find((v) => {
                  const d = new Date(v.periodStart);
                  return v.status === "ACTIVE" && d.getFullYear() === ym.year && d.getMonth() + 1 === ym.month
                    && (targetId === null || targetId >= GROUP_ID_OFFSET || v.site?.id === targetId);
                });
                return (
                  <Stack key={id} direction="row" alignItems="center" spacing={0.5}>
                    <Chip
                      clickable
                      onClick={() => toggleMonth(id)}
                      icon={selected ? <CheckIcon sx={{ fontSize: 14, color: "#fff !important" }} /> : undefined}
                      label={`${MONTH_LABELS[ym.month - 1]} ${ym.year}${matchedVersion ? " · déjà généré" : ""}`}
                      sx={{
                        height: 36, fontSize: 13, fontWeight: 600, borderRadius: planningV2Radii.pill,
                        bgcolor: selected ? planningV2Colors.brand : "#F8FAFC",
                        color: selected ? "#fff" : planningV2Colors.textBody,
                        border: `1px solid ${matchedVersion ? MODIFICATION_ACCENT.main : selected ? planningV2Colors.brand : "#E7EBEF"}`,
                        "&:hover": { bgcolor: selected ? planningV2Colors.brandHover : "#F1F4F7" },
                      }}
                    />
                    {matchedVersion && (
                      <Tooltip title="Modifier ce mois déjà généré">
                        <IconButton
                          size="small"
                          aria-label="Modifier ce mois déjà généré"
                          onClick={() => enterModification(matchedVersion)}
                          sx={{
                            width: 30, height: 30, border: `1px solid ${MODIFICATION_ACCENT.main}`,
                            color: MODIFICATION_ACCENT.main, "&:hover": { bgcolor: MODIFICATION_ACCENT.bg },
                          }}
                        >
                          <EditOutlinedIcon sx={{ fontSize: 15 }} />
                        </IconButton>
                      </Tooltip>
                    )}
                  </Stack>
                );
              })}
            </Stack>
          </Box>

          <Stack direction="row" spacing={1.75} sx={{ mb: 2.75, flexWrap: "wrap" }} useFlexGap>
            <Box sx={{ flex: 1, minWidth: 220 }}>
              <SearchableSelect
                label="Site ou groupe de sites"
                required
                icon={<SearchOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.textSecondary, mr: 0.5 }} />}
                options={targetOptions}
                value={targetId}
                onChange={setTargetId}
                placeholder="Rechercher un site ou groupe…"
              />
            </Box>
            <Box sx={{ display: "flex", alignItems: "flex-end" }}>
              <Button
                variant="contained" disableElevation
                disabled={!target || selectedMonthIds.length === 0 || previewMutation.isPending}
                onClick={() => previewMutation.mutate()}
                sx={{
                  height: 42, px: 2.5, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600,
                  bgcolor: !target || selectedMonthIds.length === 0 ? "#E7EBEF" : planningV2Colors.textTitle,
                  color: !target || selectedMonthIds.length === 0 ? "#98A2AE" : "#fff",
                  cursor: !target || selectedMonthIds.length === 0 ? "not-allowed" : "pointer",
                  "&:hover": { bgcolor: !target || selectedMonthIds.length === 0 ? "#E7EBEF" : "#243240" },
                  "&.Mui-disabled": { bgcolor: "#E7EBEF", color: "#98A2AE" },
                }}
              >
                Prévisualiser
              </Button>
            </Box>
          </Stack>
        </>
      )}

      {/* Idle state: ready prompt + generation history */}
      {!isModification && !preview && !previewMutation.isPending && (
        <>
          <Box sx={{ bgcolor: "#fff", border: "1px dashed #DDE2E8", borderRadius: planningV2Radii.cardLg, p: 7, textAlign: "center" }}>
            <Box sx={{ width: 52, height: 52, borderRadius: planningV2Radii.cardLg, bgcolor: planningV2Colors.infoBg, display: "flex", alignItems: "center", justifyContent: "center", mx: "auto", mb: 1.75 }}>
              <RocketLaunchOutlinedIcon sx={{ fontSize: 24, color: planningV2Colors.brand }} />
            </Box>
            <Typography sx={{ fontSize: 15, fontWeight: 700 }}>Prêt à générer {monthsLabel}</Typography>
            <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mt: 0.75, maxWidth: 380, mx: "auto" }}>
              Choisissez les mois et le périmètre, puis lancez la prévisualisation pour vérifier chaque poste avant de déployer.
            </Typography>
          </Box>

          <Box sx={{ mt: 3.5 }}>
            <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1.5 }}>
              <Typography sx={{ fontSize: 11, fontWeight: 700, letterSpacing: "0.06em", textTransform: "uppercase", color: planningV2Colors.textSecondary }}>
                Plannings déjà générés
              </Typography>
              <Typography sx={{ fontSize: 11, fontWeight: 600, color: planningV2Colors.textMuted, bgcolor: "#F1F4F7", px: 1, py: 0.2, borderRadius: planningV2Radii.pill, fontVariantNumeric: "tabular-nums" }}>
                {historyQuery.data?.total ?? 0}
              </Typography>
            </Stack>
            <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
              {historyQuery.isLoading ? (
                <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>
              ) : (historyQuery.data?.items.length ?? 0) === 0 ? (
                <Typography sx={{ fontSize: 13, color: planningV2Colors.textSecondary, textAlign: "center", py: 3 }}>
                  Aucun planning généré pour le moment.
                </Typography>
              ) : (
                historyQuery.data!.items.map((v) => {
                  // Only an ACTIVE version can enter Modification mode — see the month-chip
                  // comment above for why DRAFT/ARCHIVED are dead ends (apply-modifications/
                  // cancel-all both reject non-ACTIVE server-side).
                  const isEditable = v.status === "ACTIVE";
                  const statusLabel = v.status === "ACTIVE" ? "Déployé" : v.status === "ARCHIVED" ? "Archivé" : "Brouillon";
                  return (
                    <Stack
                      key={v.id} direction="row" alignItems="center" spacing={2}
                      onClick={isEditable ? () => enterModification(v) : undefined}
                      sx={{
                        px: 2.25, py: 1.75, cursor: isEditable ? "pointer" : "default",
                        borderBottom: `1px solid ${planningV2Colors.divider}`,
                        "&:last-child": { borderBottom: "none" },
                        "&:hover": isEditable ? { bgcolor: "#FAFBFC" } : undefined,
                      }}
                    >
                      <Box sx={{ width: 38, height: 38, borderRadius: planningV2Radii.button, flex: "none", bgcolor: planningV2Colors.infoBg, display: "flex", alignItems: "center", justifyContent: "center" }}>
                        <CalendarTodayOutlinedIcon sx={{ fontSize: 17, color: planningV2Colors.brand }} />
                      </Box>
                      <Box sx={{ width: 160, flex: "none" }}>
                        <Typography sx={{ fontSize: 14, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>
                          {MONTH_LABELS[new Date(v.periodStart).getMonth()]} {new Date(v.periodStart).getFullYear()}
                        </Typography>
                        <Typography sx={{ fontSize: 12, color: planningV2Colors.textSecondary }}>{v.site?.name ?? "Tous sites"}</Typography>
                      </Box>
                      <Stack direction="row" spacing={2.25} sx={{ flex: 1 }}>
                        <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody }}>
                          <Box component="span" sx={{ fontWeight: 700, color: planningV2Colors.textTitle, fontVariantNumeric: "tabular-nums" }}>{v.summary.total}</Box> missions
                        </Typography>
                        <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody }}>
                          <Box component="span" sx={{ fontWeight: 700, color: planningV2Colors.warnFg, fontVariantNumeric: "tabular-nums" }}>{v.summary.open}</Box> ouvertes
                        </Typography>
                        {v.deployedAt && (
                          <Typography sx={{ fontSize: 12, color: planningV2Colors.textSecondary }}>
                            Déployé le <Box component="span" sx={{ fontVariantNumeric: "tabular-nums" }}>{new Date(v.deployedAt).toLocaleDateString("fr-FR")}</Box>
                          </Typography>
                        )}
                      </Stack>
                      <Chip
                        size="small"
                        label={statusLabel}
                        sx={{
                          height: 24, fontSize: 11.5, fontWeight: 700,
                          bgcolor: v.status === "ACTIVE" ? "#EFFAF5" : v.status === "ARCHIVED" ? "#F1F4F7" : planningV2Colors.warnBg,
                          color: v.status === "ACTIVE" ? "#2C7D5F" : v.status === "ARCHIVED" ? planningV2Colors.textSecondary : planningV2Colors.warnFg,
                        }}
                      />
                      {isEditable && (
                        <Stack direction="row" alignItems="center" spacing={0.4} sx={{ color: MODIFICATION_ACCENT.main, flex: "none" }}>
                          <Typography sx={{ fontSize: 12, fontWeight: 700 }}>Modifier</Typography>
                          <ChevronRightOutlinedIcon sx={{ fontSize: 18 }} />
                        </Stack>
                      )}
                    </Stack>
                  );
                })
              )}
            </Box>
          </Box>
        </>
      )}

      {/* Loading */}
      {previewMutation.isPending && (
        <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, p: 7, textAlign: "center", boxShadow: planningV2Shadows.card }}>
          <CircularProgress size={34} sx={{ mb: 1.75, color: planningV2Colors.brand }} />
          <Typography sx={{ fontSize: 14, fontWeight: 600, color: planningV2Colors.textBody }}>
            Analyse des postes et des disponibilités…
          </Typography>
        </Box>
      )}
      {isModification && modificationMissionsQuery.isLoading && (
        <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, p: 7, textAlign: "center", boxShadow: planningV2Shadows.card }}>
          <CircularProgress size={34} sx={{ mb: 1.75, color: accent.main }} />
          <Typography sx={{ fontSize: 14, fontWeight: 600, color: planningV2Colors.textBody }}>
            Chargement du planning déployé…
          </Typography>
        </Box>
      )}

      {/* Modification applied — success recap */}
      {isModification && modificationApplied && (
        <Box sx={{ bgcolor: "#fff", border: "1px solid #BCE9D6", borderRadius: planningV2Radii.cardLg, p: 5, textAlign: "center", boxShadow: planningV2Shadows.card, mb: 2.75 }}>
          <Box sx={{ width: 52, height: 52, borderRadius: planningV2Radii.pill, bgcolor: "#EFFAF5", display: "flex", alignItems: "center", justifyContent: "center", mx: "auto", mb: 1.75 }}>
            <CheckCircleOutlinedIcon sx={{ fontSize: 26, color: "#2C7D5F" }} />
          </Box>
          <Typography sx={{ fontSize: 16, fontWeight: 700 }}>Planning redéployé</Typography>
          <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mt: 0.75, mb: 2 }}>
            {modificationApplied.created} créée{modificationApplied.created > 1 ? "s" : ""} · {modificationApplied.updated} modifiée{modificationApplied.updated > 1 ? "s" : ""} · {modificationApplied.cancelled} annulée{modificationApplied.cancelled > 1 ? "s" : ""} · {modificationApplied.released} libérée{modificationApplied.released > 1 ? "s" : ""}.
            Seules les personnes concernées par un changement ont reçu un email récapitulatif.
          </Typography>
          <Stack direction="row" spacing={1.25} justifyContent="center">
            <Button onClick={() => setModificationApplied(null)} sx={{ height: 38, px: 2.25, borderRadius: planningV2Radii.button, border: "1px solid #DDE2E8", color: planningV2Colors.textStrong, textTransform: "none", fontWeight: 600 }}>
              Continuer la modification
            </Button>
            <Button
              onClick={exitModification}
              sx={{ height: 38, px: 2.25, borderRadius: planningV2Radii.button, bgcolor: planningV2Colors.textTitle, color: "#fff", textTransform: "none", fontWeight: 600, "&:hover": { bgcolor: "#243240" } }}
            >
              Terminer
            </Button>
          </Stack>
        </Box>
      )}

      {/* Preview / Modification editor — same two-pane layout, same filters/selection/list for both modes */}
      {(preview || (isModification && modificationMissionsQuery.data)) && !deployed && !modificationApplied && (
        <Stack direction="row" spacing={2.5} alignItems="flex-start">
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Stack direction="row" spacing={1.25} sx={{ mb: 1.75, flexWrap: "wrap" }} useFlexGap>
            {FILTER_CHIPS.map((chip) => {
              const n = chip.key === "all" ? lines.length : severityCounts[chip.key];
              const active = genFilter === chip.key;
              return (
                <Box
                  key={chip.key}
                  component="button"
                  onClick={() => setGenFilter(active ? "all" : chip.key)}
                  sx={{
                    display: "flex", alignItems: "center", gap: 0.9, px: 1.5, py: 0.75,
                    border: `1px solid ${active ? planningV2Colors.textTitle : planningV2Colors.cardBorder}`,
                    borderRadius: planningV2Radii.pill, fontSize: 12.5, fontWeight: 600, cursor: "pointer",
                    fontFamily: "inherit", bgcolor: active ? planningV2Colors.textTitle : "#fff",
                    color: active ? "#fff" : planningV2Colors.textStrong,
                  }}
                >
                  <Box sx={{ width: 8, height: 8, borderRadius: "999px", bgcolor: chip.dot }} />
                  {chip.label}
                  <Box component="span" sx={{ color: active ? "rgba(255,255,255,.75)" : planningV2Colors.textSecondary, fontVariantNumeric: "tabular-nums" }}>{n}</Box>
                </Box>
              );
            })}
          </Stack>

          {/* Bulk action bar */}
          {selectedKeys.size > 0 && (
            <Stack
              direction="row" alignItems="center" spacing={1.5} flexWrap="wrap"
              sx={{ mb: 1.75, p: 1.25, borderRadius: planningV2Radii.cardLg, bgcolor: planningV2Colors.infoBg, border: `1px solid ${planningV2Colors.cardBorder}` }}
            >
              <Typography sx={{ fontSize: 12.5, fontWeight: 700, color: planningV2Colors.textStrong }}>
                {selectedKeys.size} poste{selectedKeys.size > 1 ? "s" : ""} sélectionné{selectedKeys.size > 1 ? "s" : ""}
              </Typography>
              <Box sx={{ minWidth: 200 }}>
                <SearchableSelect
                  label="Assigner à" required
                  options={instrumentistOptions}
                  value={bulkInstrumentistId === "" ? null : bulkInstrumentistId}
                  onChange={(id) => setBulkInstrumentistId(id ?? "")}
                  placeholder="Choisir un instrumentiste…"
                />
              </Box>
              <Button
                size="small" variant="contained" disableElevation disabled={bulkInstrumentistId === ""}
                onClick={handleBulkAssign}
                sx={{ height: 36, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
              >
                Assigner
              </Button>
              <Button size="small" color="inherit" onClick={handleBulkSkip} sx={{ textTransform: "none", fontWeight: 600 }}>
                Ignorer la sélection
              </Button>
              <Button size="small" color="inherit" onClick={() => setSelectedKeys(new Set())} sx={{ textTransform: "none" }}>
                Désélectionner
              </Button>
            </Stack>
          )}

          {dirtyCount > 0 && (
            <Stack direction="row" alignItems="center" justifyContent="flex-end" spacing={1} sx={{ mb: 1.25 }}>
              <Typography sx={{ fontSize: 12, fontWeight: 600, color: planningV2Colors.warnFg }}>
                {dirtyCount} modification{dirtyCount > 1 ? "s" : ""} locale{dirtyCount > 1 ? "s" : ""} non générée{dirtyCount > 1 ? "s" : ""}
              </Typography>
              <Button size="small" color="inherit" onClick={() => setEditedLines(new Map())} sx={{ textTransform: "none" }}>
                Tout réinitialiser
              </Button>
            </Stack>
          )}

          {filteredLines.length === 0 ? (
            <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, p: 4, textAlign: "center" }}>
              <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted }}>
                {lines.length === 0
                  ? "Aucune occurrence pour cette période — vérifiez que des postes actifs existent."
                  : "Aucun poste dans ce filtre · Choisissez Tout pour revoir l'ensemble."}
              </Typography>
            </Box>
          ) : (
            <Stack spacing={1.75}>
              {dayGroups.map((day) => (
                <Box key={day.dateKey}>
                  <Stack direction="row" alignItems="center" spacing={1.25} sx={{ mb: 1 }}>
                    <Typography sx={{ fontSize: 13, fontWeight: 700, color: planningV2Colors.textTitle, fontVariantNumeric: "tabular-nums" }}>
                      {formatDayHeader(day.dateKey)}
                    </Typography>
                    <Box sx={{ flex: 1, height: "1px", bgcolor: planningV2Colors.divider }} />
                    <Chip
                      size="small"
                      label={`${day.postsCount} poste${day.postsCount > 1 ? "s" : ""}`}
                      sx={{ height: 22, fontSize: 11, fontWeight: 700, bgcolor: "#F1F4F7", color: planningV2Colors.textSecondary }}
                    />
                  </Stack>

                  <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
                    {day.surgeons.map((surgeon, sIdx) => (
                      <Box key={surgeon.surgeonId} sx={{ borderTop: sIdx > 0 ? `1px solid ${planningV2Colors.divider}` : "none" }}>
                        <Stack direction="row" alignItems="center" spacing={1.1} sx={{ px: 2, py: 1.1, bgcolor: "#FAFBFC" }}>
                          <PersonAvatar name={surgeon.surgeonName} size="xs" />
                          <Typography sx={{ fontSize: 12.5, fontWeight: 700, color: planningV2Colors.textTitle }}>{surgeon.surgeonName}</Typography>
                          <Typography sx={{ fontSize: 11.5, color: planningV2Colors.textSecondary }}>
                            {surgeon.lines.length} poste{surgeon.lines.length > 1 ? "s" : ""}
                          </Typography>
                        </Stack>
                        {surgeon.lines.map((line, lIdx) => {
                          const tokens = STATUS_TOKENS[line.status];
                          const key = lineKeyV2(line);
                          const dirty = editedLines.has(key);
                          const isSelectedInInspector = selectedLineKey === key;
                          return (
                            <Stack
                              key={lIdx} direction="row" alignItems="center" spacing={1.5}
                              onClick={() => { setSelectedLineKey(key); setIsCreatingMission(false); }}
                              sx={{
                                px: 2, py: 1.1, borderTop: `1px solid ${planningV2Colors.divider}`, cursor: "pointer",
                                bgcolor: isSelectedInInspector ? accent.bg : severityOf(line.status) === "crit" ? "#FBF2F1" : "transparent",
                                borderLeft: isSelectedInInspector ? `3px solid ${accent.main}` : "3px solid transparent",
                              }}
                            >
                              <Checkbox
                                size="small"
                                checked={selectedKeys.has(key)}
                                onClick={(e) => e.stopPropagation()}
                                onChange={() => toggleSelected(key)}
                                sx={{ p: 0, flex: "none" }}
                              />
                              <Box sx={{ width: 6, height: 6, borderRadius: "999px", bgcolor: tokens.dot, flex: "none" }} />
                              <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody, minWidth: 90 }} noWrap>{line.siteName ?? "—"}</Typography>
                              <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textStrong, minWidth: 100, fontVariantNumeric: "tabular-nums" }}>
                                {line.startTime}–{line.endTime}
                              </Typography>
                              <Stack
                                direction="row" alignItems="center" spacing={0.9} sx={{ flex: 1, minWidth: 0 }}
                              >
                                {line.instrumentistName ? (
                                  <>
                                    <PersonAvatar name={line.instrumentistName} size="xs" />
                                    <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textStrong }} noWrap>{line.instrumentistName}</Typography>
                                  </>
                                ) : (
                                  <>
                                    <EmptyAvatar size={22} />
                                    <Typography sx={{ fontSize: 12, fontWeight: 600, fontStyle: "italic", color: planningV2Colors.warnFg }}>
                                      À pourvoir
                                    </Typography>
                                  </>
                                )}
                                <EditOutlinedIcon sx={{ fontSize: 13, color: planningV2Colors.textSecondary, flex: "none" }} />
                                {dirty && (
                                  <Chip
                                    label="Édité" size="small"
                                    onDelete={(e) => { e.stopPropagation(); handleResetLine(key); }}
                                    sx={{ height: 18, fontSize: 10, fontWeight: 700, bgcolor: planningV2Colors.warnBg, color: planningV2Colors.warnFg, flex: "none" }}
                                  />
                                )}
                              </Stack>
                              <Box sx={{
                                display: "inline-flex", alignItems: "center", gap: 0.6, fontSize: 11.5, fontWeight: 700,
                                color: tokens.fg, bgcolor: tokens.bg, px: 1, py: 0.4, borderRadius: planningV2Radii.pill, width: "fit-content", flex: "none",
                              }}>
                                <Box sx={{ width: 6, height: 6, borderRadius: "999px", bgcolor: tokens.dot }} />
                                {tokens.label}
                              </Box>
                            </Stack>
                          );
                        })}
                      </Box>
                    ))}
                  </Box>
                </Box>
              ))}
            </Stack>
          )}

          <Stack direction="row" justifyContent="space-between" alignItems="center" spacing={1.5} flexWrap="wrap" sx={{ mt: 2.25 }}>
            <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted }}>
              {lines.length} poste{lines.length > 1 ? "s" : ""} analysé{lines.length > 1 ? "s" : ""}
              {severityCounts.info + severityCounts.crit > 0
                ? ` · ${severityCounts.info + severityCounts.crit} à corriger avant déploiement`
                : ""}
            </Typography>
            <Stack direction="row" spacing={1.25}>
              {!isModification && (
                <Button onClick={resetGen} sx={{ height: 40, px: 2, borderRadius: planningV2Radii.button, border: "1px solid #DDE2E8", color: planningV2Colors.textStrong, textTransform: "none", fontWeight: 600 }}>
                  Recommencer
                </Button>
              )}
              {isModification ? (
                <Button
                  variant="contained" disableElevation disabled={applyModsMutation.isPending || dirtyCount + newLines.length === 0}
                  onClick={() => applyModsMutation.mutate()}
                  startIcon={<RocketLaunchOutlinedIcon />}
                  sx={{ height: 40, px: 2.25, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, bgcolor: accent.main, boxShadow: planningV2Shadows.button, "&:hover": { bgcolor: accent.hover } }}
                >
                  Redéployer
                </Button>
              ) : !generated ? (
                <Button
                  variant="contained" disableElevation disabled={generateMutation.isPending} onClick={() => generateMutation.mutate()}
                  sx={{ height: 40, px: 2.25, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, bgcolor: planningV2Colors.brand, boxShadow: planningV2Shadows.button, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
                >
                  {dirtyCount > 0 ? "Générer avec modifications" : "Générer les missions"}
                </Button>
              ) : (
                <Button
                  variant="contained" disableElevation disabled={deployMutation.isPending} onClick={() => deployMutation.mutate()}
                  startIcon={<RocketLaunchOutlinedIcon />}
                  sx={{ height: 40, px: 2.25, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, bgcolor: planningV2Colors.brand, boxShadow: planningV2Shadows.button, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
                >
                  Déployer le planning
                </Button>
              )}
            </Stack>
          </Stack>

          {generated && !isModification && (
            <Stack direction="row" alignItems="center" spacing={1} sx={{ mt: 1.5 }}>
              <FormControlLabel
                control={<Checkbox size="small" checked={sendPdf} onChange={(e) => setSendPdf(e.target.checked)} />}
                label={<Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody }}>Envoyer les PDFs/emails de récapitulatif</Typography>}
              />
            </Stack>
          )}
        </Box>

        <Inspector
          line={selectedLine}
          isDirty={!!selectedLineKey && editedLines.has(selectedLineKey)}
          isModification={isModification}
          instrumentistOptions={instrumentistOptionsForSelected}
          freedInstrumentists={freedForSelected}
          absencesLoading={absencesQuery.isLoading}
          accent={accent}
          onInstrumentistChange={(newId) => selectedLine && handleInstrumentistChange(selectedLine, newId)}
          onScheduleChange={(patch) => selectedLine && handleScheduleChange(selectedLine, patch)}
          onCancelMission={() => selectedLine && handleCancelMission(selectedLine)}
          onReleaseMission={() => selectedLine && handleReleaseMission(selectedLine)}
          onReset={() => selectedLineKey && handleResetLine(selectedLineKey)}
          isCreating={isCreatingMission}
          surgeonOptions={surgeonOptions}
          siteOptions={siteOptionsForCreate}
          onStartCreate={handleStartCreate}
          onSubmitCreate={handleSubmitCreate}
          onCancelCreate={handleCancelCreate}
        />
        </Stack>
      )}

      {/* Deployed success */}
      {deployed && (
        <Box sx={{ bgcolor: "#fff", border: "1px solid #BCE9D6", borderRadius: planningV2Radii.cardLg, p: 5, textAlign: "center", boxShadow: planningV2Shadows.card }}>
          <Box sx={{ width: 52, height: 52, borderRadius: planningV2Radii.pill, bgcolor: "#EFFAF5", display: "flex", alignItems: "center", justifyContent: "center", mx: "auto", mb: 1.75 }}>
            <CheckCircleOutlinedIcon sx={{ fontSize: 26, color: "#2C7D5F" }} />
          </Box>
          <Typography sx={{ fontSize: 16, fontWeight: 700 }}>Planning déployé pour {monthsLabel}</Typography>
          <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mt: 0.75, mb: 2, maxWidth: 420, mx: "auto" }}>
            Les missions sont publiées et les instrumentistes notifiées. Les postes sans instrumentiste sont ouverts en missions disponibles.
          </Typography>
          <Button
            onClick={resetGen}
            sx={{ height: 38, px: 2.25, borderRadius: planningV2Radii.button, bgcolor: planningV2Colors.textTitle, color: "#fff", textTransform: "none", fontWeight: 600, "&:hover": { bgcolor: "#243240" } }}
          >
            Générer d'autres mois
          </Button>
        </Box>
      )}

    </Box>
  );
}

function Stepper({ stepIndex }: { stepIndex: number }) {
  const steps = ["Prévisualiser", "Générer les missions", "Déployer"];
  return (
    <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 3 }}>
      {steps.map((label, i) => {
        const state = i < stepIndex ? "done" : i === stepIndex ? "active" : "todo";
        return (
          <React.Fragment key={label}>
            <Stack
              direction="row" alignItems="center" spacing={1}
              sx={{
                px: 1.5, py: 0.75, borderRadius: planningV2Radii.pill, fontSize: 12.5, fontWeight: 700,
                color: state === "todo" ? planningV2Colors.textSecondary : "#fff",
                bgcolor: state === "todo" ? "#F1F4F7" : planningV2Colors.brand,
              }}
            >
              <Box sx={{
                width: 18, height: 18, borderRadius: "999px", display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 11, fontWeight: 700, bgcolor: state === "todo" ? "#E7EBEF" : "rgba(255,255,255,.25)",
                color: state === "todo" ? planningV2Colors.textSecondary : "#fff",
              }}>
                {i + 1}
              </Box>
              <Typography component="span" sx={{ fontSize: 12.5, fontWeight: 700, color: state === "todo" ? planningV2Colors.textSecondary : "#fff" }}>
                {label}
              </Typography>
            </Stack>
            {i < steps.length - 1 && (
              <Box sx={{ flex: 1, height: 2, maxWidth: 40, borderRadius: "999px", bgcolor: i < stepIndex ? planningV2Colors.brand : "#E7EBEF" }} />
            )}
          </React.Fragment>
        );
      })}
    </Stack>
  );
}
