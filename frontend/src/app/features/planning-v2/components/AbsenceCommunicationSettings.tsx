import * as React from "react";
import { Alert, Box, CircularProgress, Stack, Typography } from "@mui/material";
import CampaignOutlinedIcon from "@mui/icons-material/CampaignOutlined";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getAbsenceCommunicationSettings, updateAbsenceCommunicationSettings, extractErrorV2 } from "../api/planningV2.api";
import { useToast } from "../../../ui/toast/useToast";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

/**
 * Communication des absences chirurgiens — Lot A (D-114). Un seul réglage par site en Lot A
 * (« Informer les chirurgiens du site » — Libération de salle). Les champs « gestion du
 * bloc » (To/CC/délai) seront ajoutés à ce même composant au Lot B, une fois le contrat API
 * correspondant exposé côté backend.
 */
export function AbsenceCommunicationSettings() {
  const toast = useToast();
  const qc = useQueryClient();

  const settingsQuery = useQuery({
    queryKey: ["planning-v2", "absence-communication-settings"],
    queryFn: () => getAbsenceCommunicationSettings(),
  });

  const toggleMutation = useMutation({
    mutationFn: (vars: { siteId: number; notifyColleaguesEnabled: boolean }) =>
      updateAbsenceCommunicationSettings(vars.siteId, { notifyColleaguesEnabled: vars.notifyColleaguesEnabled }),
    onSuccess: () => {
      toast.success("Réglage enregistré");
      qc.invalidateQueries({ queryKey: ["planning-v2", "absence-communication-settings"] });
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const items = React.useMemo(
    () => [...(settingsQuery.data?.items ?? [])].sort((a, b) => a.site.name.localeCompare(b.site.name)),
    [settingsQuery.data],
  );

  return (
    <Box>
      <Typography sx={{ fontSize: 14.5, fontWeight: 700, mb: 2 }}>Communication des absences</Typography>

      {settingsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>
      ) : settingsQuery.isError ? (
        <Alert severity="error">{extractErrorV2(settingsQuery.error)}</Alert>
      ) : items.length === 0 ? (
        <Alert severity="info">Aucun site configuré.</Alert>
      ) : (
        <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
          {items.map((item, idx) => (
            <Stack
              key={item.site.id} direction="row" alignItems="flex-start" spacing={1.75}
              sx={{ px: 2.25, py: 2, borderBottom: idx < items.length - 1 ? `1px solid ${planningV2Colors.divider}` : "none" }}
            >
              <Box sx={{
                width: 34, height: 34, borderRadius: planningV2Radii.button, flex: "none", display: "flex", alignItems: "center", justifyContent: "center",
                bgcolor: item.notifyColleaguesEnabled ? planningV2Colors.infoBg : "#F1F4F7",
                color: item.notifyColleaguesEnabled ? planningV2Colors.brand : planningV2Colors.textSecondary,
              }}>
                <CampaignOutlinedIcon sx={{ fontSize: 17 }} />
              </Box>
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography sx={{ fontSize: 14, fontWeight: 700 }}>{item.site.name}</Typography>
                <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, mt: 0.25 }}>
                  Envoie aux chirurgiens du site les dates des blocs opératoires libérés lorsqu'un chirurgien encode une absence.
                </Typography>
              </Box>
              <SwitchPill
                on={item.notifyColleaguesEnabled}
                disabled={toggleMutation.isPending}
                onClick={() => toggleMutation.mutate({ siteId: item.site.id, notifyColleaguesEnabled: !item.notifyColleaguesEnabled })}
                ariaLabel={`Informer les chirurgiens du site ${item.site.name}`}
              />
            </Stack>
          ))}
        </Box>
      )}
    </Box>
  );
}

function SwitchPill({ on, disabled, onClick, ariaLabel }: { on: boolean; disabled?: boolean; onClick: () => void; ariaLabel: string }) {
  return (
    <Box
      component="button"
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={ariaLabel}
      onClick={onClick}
      disabled={disabled}
      sx={{
        width: 42, height: 24, borderRadius: planningV2Radii.pill, flex: "none", position: "relative", border: "none", padding: 0,
        cursor: disabled ? "default" : "pointer",
        bgcolor: on ? planningV2Colors.brand : "#E7EBEF", opacity: disabled ? 0.6 : 1, transition: "background .15s",
      }}
    >
      <Box sx={{
        position: "absolute", top: 2, left: on ? 21 : 2, width: 20, height: 20, borderRadius: "999px",
        bgcolor: "#fff", boxShadow: "0 1px 3px rgba(0,0,0,.2)", transition: "left .15s",
      }} />
    </Box>
  );
}
