import * as React from "react";
import {
  Box, Button, Checkbox, Chip, CircularProgress, FormControlLabel, Popover, Stack, Typography,
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
import { useMutation, useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";

import { fetchSites } from "../../sites/api/sites.api";
import { getSiteGroups, getSurgeonPosts, previewPlanningV2, generatePlanningV2, deployPlanningV2, extractErrorV2 } from "../api/planningV2.api";
import { listPlanningVersions, getAbsences } from "../../planning-manager/api/planning.api";
import type { PreviewLineStatus, PreviewLineV2, PreviewResponseV2 } from "../api/planningV2.types";
import {
  buildMonthChipIds, monthIdToYearMonth, mergePreviewResponses,
  aggregateGenerated, aggregateDeploy, type AggregatedGenerated, type AggregatedDeploy,
  severityOf, filterLines, countBySeverity, type SeverityFilter,
  groupLinesByDayAndSurgeon, formatDayHeader,
  lineKeyV2, getFreedInstrumentists, findSameDayAssignmentElsewhere,
} from "../api/generatePreviewGrouping";
import { useToast } from "../../../ui/toast/useToast";
import { SearchableSelect, type SearchableOption } from "../components/SearchableSelect";
import { PersonAvatar, EmptyAvatar } from "../../../ui/avatar/PersonAvatar";
import { buildProfilePictureUrl } from "../../manager-instrumentists/utils/instrumentists.utils";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";
import { apiClient } from "../../../api/apiClient";

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
  const navigate = useNavigate();
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

  // ── Preview Editor: local instrumentist reassignment before generate ─────────
  const [editedLines, setEditedLines] = React.useState<Map<string, Partial<PreviewLineV2>>>(new Map());
  const [selectedKeys, setSelectedKeys] = React.useState<Set<string>>(new Set());
  const [bulkInstrumentistId, setBulkInstrumentistId] = React.useState<number | "">("");
  const [editPopover, setEditPopover] = React.useState<{ anchorEl: HTMLElement; key: string; date: string } | null>(null);

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

  // Absences overlapping the day of the line currently open in the reassignment popover —
  // refetched per popover open since it's scoped to one specific date, not the whole preview.
  const absencesQuery = useQuery({
    queryKey: ["absences-on-day", editPopover?.date],
    queryFn: () => getAbsences({ from: editPopover!.date, to: editPopover!.date }),
    enabled: !!editPopover,
    staleTime: 60_000,
  });
  const absentInstrumentistIds = React.useMemo(() => {
    const ids = new Set<number>();
    for (const a of absencesQuery.data ?? []) {
      if (a.user.role === "INSTRUMENTIST") ids.add(a.user.id);
    }
    return ids;
  }, [absencesQuery.data]);

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const groupsQuery = useQuery({ queryKey: ["planning-v2", "site-groups"], queryFn: getSiteGroups });

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

  function resetGen() {
    setPreview(null);
    setPreviewResponses([]);
    setGenerated(null);
    setDeployed(null);
    setGenFilter("all");
    setEditedLines(new Map());
    setSelectedKeys(new Set());
  }

  const lines: PreviewLineV2[] = preview?.lines ?? [];

  // Merge local instrumentist-reassignment edits over the raw preview lines for display and generate.
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

  const editPopoverLine = editPopover ? effectiveLines.find((l) => lineKeyV2(l) === editPopover.key) ?? null : null;

  // Per-option annotations scoped to the line currently open in the popover — absence and
  // same-day-elsewhere are both relative to *that* line's date, not a global property.
  const popoverInstrumentistOptions: SearchableOption[] = React.useMemo(() => {
    if (!editPopoverLine) return instrumentistOptions;
    return instrumentistOptions.map((opt) => {
      const absent = absentInstrumentistIds.has(opt.id);
      const elsewhere = !absent && findSameDayAssignmentElsewhere(effectiveLines, editPopoverLine, opt.id);
      return {
        ...opt,
        muted: absent,
        badge: absent ? "En congé" : elsewhere ? "Déjà affecté ailleurs" : undefined,
      };
    });
  }, [instrumentistOptions, editPopoverLine, absentInstrumentistIds, effectiveLines]);

  const monthsLabel = selectedMonthIds.length === 0
    ? "Sélectionnez au moins un mois"
    : selectedMonthIds.length === 1
      ? `${MONTH_LABELS[monthIdToYearMonth(selectedMonthIds[0]).month - 1]} ${monthIdToYearMonth(selectedMonthIds[0]).year}`
      : `${selectedMonthIds.length} mois`;

  const stepIndex = deployed ? 3 : generated ? 2 : preview ? 1 : 0;

  return (
    <Box>
      <Typography sx={{ fontSize: 22, fontWeight: 800, letterSpacing: "-0.02em" }}>Générer le planning</Typography>
      <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted, mt: 0.5, mb: 3 }}>
        Prévisualisez, vérifiez, puis déployez les missions des mois sélectionnés.
      </Typography>

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
            return (
              <Chip
                key={id}
                clickable
                onClick={() => toggleMonth(id)}
                icon={selected ? <CheckIcon sx={{ fontSize: 14, color: "#fff !important" }} /> : undefined}
                label={`${MONTH_LABELS[ym.month - 1]} ${ym.year}`}
                sx={{
                  height: 36, fontSize: 13, fontWeight: 600, borderRadius: planningV2Radii.pill,
                  bgcolor: selected ? planningV2Colors.brand : "#F8FAFC",
                  color: selected ? "#fff" : planningV2Colors.textBody,
                  border: `1px solid ${selected ? planningV2Colors.brand : "#E7EBEF"}`,
                  "&:hover": { bgcolor: selected ? planningV2Colors.brandHover : "#F1F4F7" },
                }}
              />
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

      {/* Idle state: ready prompt + generation history */}
      {!preview && !previewMutation.isPending && (
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
                  const isDeployed = v.status !== "DRAFT";
                  return (
                    <Stack
                      key={v.id} direction="row" alignItems="center" spacing={2}
                      onClick={() => navigate(`/app/m/planning/versions/${v.id}`)}
                      sx={{
                        px: 2.25, py: 1.75, cursor: "pointer",
                        borderBottom: `1px solid ${planningV2Colors.divider}`,
                        "&:last-child": { borderBottom: "none" },
                        "&:hover": { bgcolor: "#FAFBFC" },
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
                        label={isDeployed ? "Déployé" : "Brouillon"}
                        sx={{
                          height: 24, fontSize: 11.5, fontWeight: 700,
                          bgcolor: isDeployed ? "#EFFAF5" : planningV2Colors.warnBg,
                          color: isDeployed ? "#2C7D5F" : planningV2Colors.warnFg,
                        }}
                      />
                      <ChevronRightOutlinedIcon sx={{ color: planningV2Colors.textSecondary }} />
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

      {/* Preview results */}
      {preview && !deployed && (
        <Box>
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
                          return (
                            <Stack
                              key={lIdx} direction="row" alignItems="center" spacing={1.5}
                              sx={{
                                px: 2, py: 1.1, borderTop: `1px solid ${planningV2Colors.divider}`,
                                bgcolor: severityOf(line.status) === "crit" ? "#FBF2F1" : "transparent",
                              }}
                            >
                              <Checkbox
                                size="small"
                                checked={selectedKeys.has(key)}
                                onChange={() => toggleSelected(key)}
                                sx={{ p: 0, flex: "none" }}
                              />
                              <Box sx={{ width: 6, height: 6, borderRadius: "999px", bgcolor: tokens.dot, flex: "none" }} />
                              <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody, minWidth: 90 }} noWrap>{line.siteName ?? "—"}</Typography>
                              <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textStrong, minWidth: 100, fontVariantNumeric: "tabular-nums" }}>
                                {line.startTime}–{line.endTime}
                              </Typography>
                              <Stack
                                direction="row" alignItems="center" spacing={0.9} sx={{ flex: 1, minWidth: 0, cursor: "pointer", borderRadius: planningV2Radii.button, px: 0.75, py: 0.4, mx: -0.75, "&:hover": { bgcolor: "#F1F4F7" } }}
                                onClick={(e) => setEditPopover({ anchorEl: e.currentTarget, key, date: line.date })}
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
              <Button onClick={resetGen} sx={{ height: 40, px: 2, borderRadius: planningV2Radii.button, border: "1px solid #DDE2E8", color: planningV2Colors.textStrong, textTransform: "none", fontWeight: 600 }}>
                Recommencer
              </Button>
              {!generated ? (
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

          {generated && (
            <Stack direction="row" alignItems="center" spacing={1} sx={{ mt: 1.5 }}>
              <FormControlLabel
                control={<Checkbox size="small" checked={sendPdf} onChange={(e) => setSendPdf(e.target.checked)} />}
                label={<Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody }}>Envoyer les PDFs/emails de récapitulatif</Typography>}
              />
            </Stack>
          )}
        </Box>
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

      {/* Instrumentist edit popover — click a line's instrumentist to reassign before generating */}
      <Popover
        open={!!editPopover}
        anchorEl={editPopover?.anchorEl ?? null}
        onClose={() => setEditPopover(null)}
        anchorOrigin={{ vertical: "bottom", horizontal: "left" }}
      >
        {editPopoverLine && (
          <Box sx={{ p: 2, width: 300 }}>
            <SearchableSelect
              label="Instrumentiste"
              options={popoverInstrumentistOptions}
              value={editPopoverLine.instrumentistId}
              onChange={(id) => { handleInstrumentistChange(editPopoverLine, id); setEditPopover(null); }}
              placeholder="Rechercher un instrumentiste…"
            />
            {absencesQuery.isLoading && (
              <Typography sx={{ fontSize: 11, color: planningV2Colors.textSecondary, mt: 0.5 }}>
                Vérification des congés…
              </Typography>
            )}
            {(() => {
              const freed = getFreedInstrumentists(effectiveLines, editPopoverLine);
              if (freed.length === 0) return null;
              return (
                <Box sx={{ mt: 1.5 }}>
                  <Typography sx={{ fontSize: 11, fontWeight: 700, color: planningV2Colors.textSecondary, mb: 0.5 }}>
                    Libérés disponibles
                  </Typography>
                  <Stack spacing={0.75}>
                    {freed.map((f) => (
                      <Stack
                        key={f.id} direction="row" alignItems="center" justifyContent="space-between"
                        sx={{ px: 1, py: 0.75, bgcolor: "#F0FDF4", border: "1px solid #BBF7D0", borderRadius: planningV2Radii.button }}
                      >
                        <Box>
                          <Typography sx={{ fontSize: 12, fontWeight: 600 }}>{f.name}</Typography>
                          <Typography sx={{ fontSize: 10, color: "#2C7D5F" }}>{f.reason}</Typography>
                        </Box>
                        <Button
                          size="small" variant="text" color="success"
                          onClick={() => { handleInstrumentistChange(editPopoverLine, f.id); setEditPopover(null); }}
                        >
                          Assigner
                        </Button>
                      </Stack>
                    ))}
                  </Stack>
                </Box>
              );
            })()}
          </Box>
        )}
      </Popover>
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
