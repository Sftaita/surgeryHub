import * as React from "react";
import {
  Box, Button, Checkbox, Chip, CircularProgress, FormControlLabel, Stack, Typography,
} from "@mui/material";
import RocketLaunchOutlinedIcon from "@mui/icons-material/RocketLaunchOutlined";
import SearchOutlinedIcon from "@mui/icons-material/SearchOutlined";
import CheckCircleOutlinedIcon from "@mui/icons-material/CheckCircleOutlined";
import ErrorOutlineOutlinedIcon from "@mui/icons-material/ErrorOutlineOutlined";
import SyncOutlinedIcon from "@mui/icons-material/SyncOutlined";
import EventBusyOutlinedIcon from "@mui/icons-material/EventBusyOutlined";
import CalendarTodayOutlinedIcon from "@mui/icons-material/CalendarTodayOutlined";
import ChevronRightOutlinedIcon from "@mui/icons-material/ChevronRightOutlined";
import { useMutation, useQuery } from "@tanstack/react-query";

import { fetchSites } from "../../sites/api/sites.api";
import { getSiteGroups, previewPlanningV2, generatePlanningV2, deployPlanningV2, extractErrorV2 } from "../api/planningV2.api";
import { listPlanningVersions } from "../../planning-manager/api/planning.api";
import type { PreviewLineStatus, PreviewLineV2, PreviewResponseV2, GeneratedPlanningV2 } from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { SearchableSelect, type SearchableOption } from "../components/SearchableSelect";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

const STATUS_TOKENS: Record<PreviewLineStatus, { label: string; fg: string; bg: string; dot: string; icon: React.ReactElement }> = {
  COVERED:   { label: "OK",                   fg: "#2C7D5F", bg: "#EFFAF5", dot: "#5BBE96", icon: <CheckCircleOutlinedIcon sx={{ fontSize: 14 }} /> },
  UNCOVERED: { label: "Mission ouverte",      fg: planningV2Colors.infoFg, bg: planningV2Colors.infoBg, dot: "#7AA0D4", icon: <SyncOutlinedIcon sx={{ fontSize: 14 }} /> },
  MODIFIED:  { label: "Modifié",              fg: "#3B6296", bg: "#EFF4FB", dot: "#7AA0D4", icon: <SyncOutlinedIcon sx={{ fontSize: 14 }} /> },
  CONFLICT:  { label: "Conflit",              fg: "#A8554F", bg: "#FBF2F1", dot: "#D58A84", icon: <ErrorOutlineOutlinedIcon sx={{ fontSize: 14 }} /> },
  SKIPPED:   { label: "Chirurgien absent",    fg: "#8A6420", bg: "#FAF5E9", dot: "#DBAB4E", icon: <EventBusyOutlinedIcon sx={{ fontSize: 14 }} /> },
};

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

  const [year, setYear] = React.useState(defYear);
  const [month, setMonth] = React.useState(defMonth);
  const [targetId, setTargetId] = React.useState<number | null>(null);

  const [preview, setPreview] = React.useState<PreviewResponseV2 | null>(null);
  const [generated, setGenerated] = React.useState<GeneratedPlanningV2 | null>(null);
  const [deployed, setDeployed] = React.useState(false);
  const [sendPdf, setSendPdf] = React.useState(true);

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
    if (targetId >= GROUP_ID_OFFSET) return { siteId: null, siteGroupId: targetId - GROUP_ID_OFFSET, year, month };
    return { siteId: targetId, siteGroupId: null, year, month };
  }, [targetId, year, month]);

  // ±12 months around the current one, encoded as year*12+(month-1) so a single
  // searchable field covers both month and year without a separate year input.
  const monthOptions: SearchableOption[] = React.useMemo(() => {
    const base = defYear * 12 + (defMonth - 1);
    const opts: SearchableOption[] = [];
    for (let offset = -12; offset <= 12; offset++) {
      const id = base + offset;
      const y = Math.floor(id / 12);
      const m = (id % 12) + 1;
      opts.push({ id, label: `${MONTH_LABELS[m - 1]} ${y}` });
    }
    return opts;
  }, [defYear, defMonth]);

  const historyQuery = useQuery({
    queryKey: ["planning-v2", "versions-history", targetId],
    queryFn: () => listPlanningVersions({ limit: 10, siteId: targetId !== null && targetId < GROUP_ID_OFFSET ? targetId : undefined }),
    enabled: !preview && !generated,
  });

  const previewMutation = useMutation({
    mutationFn: () => previewPlanningV2(target!),
    onSuccess: (data) => { setPreview(data); setGenerated(null); setDeployed(false); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const generateMutation = useMutation({
    mutationFn: () => generatePlanningV2(target!),
    onSuccess: (data) => {
      setGenerated(data);
      toast.success(`Brouillon créé — ${data.created} mission(s) créée(s)`);
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const deployMutation = useMutation({
    mutationFn: () => deployPlanningV2(generated!.versionId, sendPdf),
    onSuccess: (data) => {
      toast.success(`Planning déployé — ${data.missionCount} mission(s) publiée(s)`);
      setDeployed(true);
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  function resetGen() {
    setPreview(null);
    setGenerated(null);
    setDeployed(false);
  }

  const lines: PreviewLineV2[] = preview?.lines ?? [];
  const monthLabel = `${MONTH_LABELS[month - 1]} ${year}`;

  const stepIndex = deployed ? 3 : generated ? 2 : preview ? 1 : 0;

  return (
    <Box>
      <Typography sx={{ fontSize: 22, fontWeight: 800, letterSpacing: "-0.02em" }}>Générer le planning</Typography>
      <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted, mt: 0.5, mb: 3 }}>
        Prévisualisez, vérifiez, puis déployez les missions du mois.
      </Typography>

      <Stepper stepIndex={stepIndex} />

      <Stack direction="row" spacing={1.75} sx={{ mb: 2.75, flexWrap: "wrap" }} useFlexGap>
        <Box sx={{ flex: 1, minWidth: 220 }}>
          <SearchableSelect
            label="Mois"
            required
            icon={<CalendarTodayOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.textSecondary, mr: 0.5 }} />}
            options={monthOptions}
            value={year * 12 + (month - 1)}
            onChange={(id) => {
              if (id === null) return;
              setYear(Math.floor(id / 12));
              setMonth((id % 12) + 1);
            }}
          />
        </Box>
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
            variant="contained" disableElevation disabled={!target || previewMutation.isPending}
            onClick={() => previewMutation.mutate()}
            sx={{
              height: 42, px: 2.5, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600,
              bgcolor: planningV2Colors.textTitle, "&:hover": { bgcolor: "#243240" },
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
            <Typography sx={{ fontSize: 15, fontWeight: 700 }}>Prêt à générer {monthLabel}</Typography>
            <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mt: 0.75, maxWidth: 380, mx: "auto" }}>
              Choisissez le mois et le périmètre, puis lancez la prévisualisation pour vérifier chaque poste avant de déployer.
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
                      sx={{ px: 2.25, py: 1.75, borderBottom: `1px solid ${planningV2Colors.divider}`, "&:last-child": { borderBottom: "none" } }}
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
            <SummaryPill dot="#98A2AE" label="Total" n={preview.summary.total} />
            <SummaryPill dot="#5BBE96" label="OK" n={preview.summary.covered} />
            <SummaryPill dot="#7AA0D4" label="Mission ouverte" n={preview.summary.uncovered} />
            <SummaryPill dot="#DBAB4E" label="Chirurgien absent" n={preview.summary.skipped} />
            <SummaryPill dot="#D58A84" label="Conflit" n={preview.summary.conflict} />
          </Stack>

          {lines.length === 0 ? (
            <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, p: 4, textAlign: "center" }}>
              <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted }}>
                Aucune occurrence pour cette période — vérifiez que des postes actifs existent.
              </Typography>
            </Box>
          ) : (
            <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
              <Box sx={{
                display: "grid", gridTemplateColumns: "108px 1.3fr 1fr 96px 1.1fr 150px", gap: 1.5,
                px: 2.25, py: 1.4, bgcolor: "#F8FAFC", borderBottom: `1px solid ${planningV2Colors.cardBorder}`,
                fontSize: 11, fontWeight: 700, letterSpacing: "0.04em", textTransform: "uppercase", color: planningV2Colors.textSecondary,
              }}>
                <span>Date</span><span>Chirurgien</span><span>Site</span><span>Période</span><span>Instrumentiste</span><span>État</span>
              </Box>
              <Box sx={{ maxHeight: 480, overflowY: "auto" }}>
                {lines.map((line, idx) => {
                  const tokens = STATUS_TOKENS[line.status];
                  return (
                    <Box
                      key={idx}
                      sx={{
                        display: "grid", gridTemplateColumns: "108px 1.3fr 1fr 96px 1.1fr 150px", gap: 1.5,
                        px: 2.25, py: 1.25, alignItems: "center",
                        borderBottom: `1px solid ${planningV2Colors.divider}`,
                        bgcolor: line.status === "CONFLICT" ? "#FBF2F1" : "transparent",
                      }}
                    >
                      <Typography sx={{ fontSize: 12.5, fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>{line.date}</Typography>
                      <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textStrong }} noWrap>{line.surgeonName}</Typography>
                      <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody }} noWrap>{line.siteName ?? "—"}</Typography>
                      <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textBody }}>{line.startTime}</Typography>
                      <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textStrong }} noWrap>{line.instrumentistName || "Aucun"}</Typography>
                      <Box sx={{
                        display: "inline-flex", alignItems: "center", gap: 0.6, fontSize: 11.5, fontWeight: 700,
                        color: tokens.fg, bgcolor: tokens.bg, px: 1, py: 0.4, borderRadius: planningV2Radii.pill, width: "fit-content",
                      }}>
                        <Box sx={{ width: 6, height: 6, borderRadius: "999px", bgcolor: tokens.dot }} />
                        {tokens.label}
                      </Box>
                    </Box>
                  );
                })}
              </Box>
            </Box>
          )}

          <Stack direction="row" justifyContent="space-between" alignItems="center" spacing={1.5} flexWrap="wrap" sx={{ mt: 2.25 }}>
            <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted }}>
              {preview.summary.total} poste{preview.summary.total > 1 ? "s" : ""} analysé{preview.summary.total > 1 ? "s" : ""}
              {preview.summary.uncovered + preview.summary.conflict > 0
                ? ` · ${preview.summary.uncovered + preview.summary.conflict} à corriger avant déploiement`
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
                  Générer les missions
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
          <Typography sx={{ fontSize: 16, fontWeight: 700 }}>Planning déployé pour {monthLabel}</Typography>
          <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mt: 0.75, mb: 2, maxWidth: 420, mx: "auto" }}>
            Les missions sont publiées et les instrumentistes notifiées. Les postes sans instrumentiste sont ouverts en missions disponibles.
          </Typography>
          <Button
            onClick={resetGen}
            sx={{ height: 38, px: 2.25, borderRadius: planningV2Radii.button, bgcolor: planningV2Colors.textTitle, color: "#fff", textTransform: "none", fontWeight: 600, "&:hover": { bgcolor: "#243240" } }}
          >
            Générer un autre mois
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

function SummaryPill({ dot, label, n }: { dot: string; label: string; n: number }) {
  return (
    <Stack direction="row" alignItems="center" spacing={0.9} sx={{ px: 1.5, py: 0.75, bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.pill, fontSize: 12.5, fontWeight: 600, color: planningV2Colors.textStrong }}>
      <Box sx={{ width: 8, height: 8, borderRadius: "999px", bgcolor: dot }} />
      {label}
      <Box component="span" sx={{ color: planningV2Colors.textSecondary, fontVariantNumeric: "tabular-nums" }}>{n}</Box>
    </Stack>
  );
}
