import * as React from "react";
import { Alert, Box, CircularProgress, Stack, Typography } from "@mui/material";
import CheckCircleOutlinedIcon from "@mui/icons-material/CheckCircleOutlined";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getAlerts, acknowledgeAlert, resolveAlert, ignoreAlert, reassignAlert, openAlertAsAvailable,
  extractErrorV2,
} from "../api/planningV2.api";
import type { PlanningAlertV2 } from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { AlertCard } from "../components/AlertCard";
import { ReassignDialog } from "../components/ReassignDialog";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

type FilterKey = "all" | "open" | "priority";

export function PlanningAlertsTab() {
  const toast = useToast();
  const qc = useQueryClient();

  const [filter, setFilter] = React.useState<FilterKey>("open");
  const [reassigning, setReassigning] = React.useState<PlanningAlertV2 | null>(null);

  const alertsQuery = useQuery({
    queryKey: ["planning-v2", "alerts", "list", "unfiltered"],
    queryFn: () => getAlerts({ limit: 100 }),
  });

  function invalidate() {
    qc.invalidateQueries({ queryKey: ["planning-v2", "alerts"] });
  }

  const ackMutation = useMutation({
    mutationFn: (a: PlanningAlertV2) => acknowledgeAlert(a.id),
    onSuccess: () => { toast.success("Alerte acquittée"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const resolveMutation = useMutation({
    mutationFn: (a: PlanningAlertV2) => resolveAlert(a.id),
    onSuccess: () => { toast.success("Alerte résolue"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const ignoreMutation = useMutation({
    mutationFn: (a: PlanningAlertV2) => ignoreAlert(a.id),
    onSuccess: () => { toast.success("Alerte ignorée"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const openAvailableMutation = useMutation({
    mutationFn: (a: PlanningAlertV2) => openAlertAsAvailable(a.id),
    onSuccess: () => { toast.success("Mission ouverte au pool"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const reassignMutation = useMutation({
    mutationFn: ({ id, instrumentistId, note }: { id: number; instrumentistId: number; note: string }) =>
      reassignAlert(id, instrumentistId, note || undefined),
    onSuccess: () => { toast.success("Mission réassignée"); invalidate(); setReassigning(null); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const busy = ackMutation.isPending || resolveMutation.isPending || ignoreMutation.isPending || openAvailableMutation.isPending;

  const allAlerts = alertsQuery.data?.items ?? [];
  const activeAlerts = allAlerts.filter((a) => a.status === "OPEN" || a.status === "ACKNOWLEDGED");
  const PRIORITY_TYPES = new Set(["REASSIGNMENT_REQUIRED", "SURGEON_CONFLICT", "INSTRUMENTIST_CONFLICT"]);

  const filteredAlerts = React.useMemo(() => {
    if (filter === "all") return allAlerts;
    if (filter === "open") return activeAlerts;
    return activeAlerts.filter((a) => PRIORITY_TYPES.has(a.type));
  }, [filter, allAlerts, activeAlerts]);

  const filters: Array<{ key: FilterKey; label: string; n: number }> = [
    { key: "all", label: "Toutes", n: allAlerts.length },
    { key: "open", label: "À traiter", n: activeAlerts.length },
    { key: "priority", label: "Prioritaires", n: activeAlerts.filter((a) => PRIORITY_TYPES.has(a.type)).length },
  ];

  if (alertsQuery.isLoading) {
    return <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}><CircularProgress size={28} /></Box>;
  }
  if (alertsQuery.isError) {
    return <Alert severity="error">{extractErrorV2(alertsQuery.error)}</Alert>;
  }

  return (
    <Box>
      <Stack direction="row" justifyContent="space-between" alignItems="flex-end" sx={{ mb: 0.75 }}>
        <Box>
          <Typography sx={{ fontSize: 22, fontWeight: 800, letterSpacing: "-0.02em" }}>Alertes</Typography>
          <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted, mt: 0.5 }}>
            Le système a repéré quelques points et vous suggère quoi faire. Rien d'urgent à craindre.
          </Typography>
        </Box>
      </Stack>

      <Stack direction="row" spacing={1} sx={{ my: 2.25, flexWrap: "wrap" }} useFlexGap>
        {filters.map((f) => {
          const active = f.key === filter;
          return (
            <Box
              key={f.key} component="button" onClick={() => setFilter(f.key)}
              sx={{
                border: "none", cursor: "pointer", fontFamily: "inherit", fontSize: 13, fontWeight: 600,
                px: 1.75, py: 0.9, borderRadius: planningV2Radii.pill,
                bgcolor: active ? planningV2Colors.textTitle : "#fff",
                color: active ? "#fff" : planningV2Colors.textBody,
                boxShadow: active ? "none" : planningV2Shadows.card,
              }}
            >
              {f.label} <Box component="span" sx={{ opacity: 0.6, fontVariantNumeric: "tabular-nums" }}>{f.n}</Box>
            </Box>
          );
        })}
      </Stack>

      {filteredAlerts.length === 0 ? (
        <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, p: 7, textAlign: "center", boxShadow: planningV2Shadows.card }}>
          <Box sx={{ width: 52, height: 52, borderRadius: "999px", bgcolor: "#EFFAF5", display: "flex", alignItems: "center", justifyContent: "center", mx: "auto", mb: 1.75 }}>
            <CheckCircleOutlinedIcon sx={{ fontSize: 24, color: "#2C7D5F" }} />
          </Box>
          <Typography sx={{ fontSize: 15, fontWeight: 700 }}>Tout est sous contrôle</Typography>
          <Typography sx={{ fontSize: 13, color: planningV2Colors.textMuted, mt: 0.75, maxWidth: 340, mx: "auto" }}>
            Aucune alerte dans ce filtre. Le système surveille les absences et conflits en continu.
          </Typography>
        </Box>
      ) : (
        <Stack spacing={1.5}>
          {filteredAlerts.map((alert) => (
            <AlertCard
              key={alert.id}
              alert={alert}
              busy={busy}
              onAcknowledge={(a) => ackMutation.mutate(a)}
              onResolve={(a) => resolveMutation.mutate(a)}
              onIgnore={(a) => ignoreMutation.mutate(a)}
              onOpenAsAvailable={(a) => openAvailableMutation.mutate(a)}
              onReassign={(a) => setReassigning(a)}
            />
          ))}
        </Stack>
      )}

      <ReassignDialog
        open={!!reassigning}
        onClose={() => setReassigning(null)}
        alert={reassigning}
        submitting={reassignMutation.isPending}
        onConfirm={(instrumentistId, note) =>
          reassigning && reassignMutation.mutate({ id: reassigning.id, instrumentistId, note })
        }
      />
    </Box>
  );
}
