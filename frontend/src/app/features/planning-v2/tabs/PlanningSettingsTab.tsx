import * as React from "react";
import { Box, Stack, Typography } from "@mui/material";
import AccessTimeOutlinedIcon from "@mui/icons-material/AccessTimeOutlined";
import AccountTreeOutlinedIcon from "@mui/icons-material/AccountTreeOutlined";
import NotificationsNoneOutlinedIcon from "@mui/icons-material/NotificationsNoneOutlined";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";

import { ShiftPeriodSettings } from "../components/ShiftPeriodSettings";
import { SiteGroupSettings } from "../components/SiteGroupSettings";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

type Section = "periodes" | "groupes" | "notifications";

const NAV: Array<{ key: Section; label: string; icon: React.ReactNode }> = [
  { key: "periodes", label: "Périodes", icon: <AccessTimeOutlinedIcon sx={{ fontSize: 17 }} /> },
  { key: "groupes", label: "Groupes de sites", icon: <AccountTreeOutlinedIcon sx={{ fontSize: 17 }} /> },
  { key: "notifications", label: "Notifications", icon: <NotificationsNoneOutlinedIcon sx={{ fontSize: 17 }} /> },
];

interface NotifChannel {
  channel: string;
  desc: string;
  active: boolean;
  soon?: boolean;
}

const NOTIF_CHANNELS: NotifChannel[] = [
  { channel: "In-app", desc: "Badges et alertes visibles dans l'application.", active: true },
  { channel: "Email", desc: "Récapitulatifs et alertes envoyés par email.", active: true },
  { channel: "Push mobile", desc: "Notifications push sur l'application mobile.", active: false, soon: true },
];

export function PlanningSettingsTab() {
  const [section, setSection] = React.useState<Section>("periodes");

  return (
    <Box>
      <Typography sx={{ fontSize: 22, fontWeight: 800, letterSpacing: "-0.02em" }}>Paramètres</Typography>
      <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted, mt: 0.5, mb: 3.5 }}>
        Configurez les périodes, les groupes de sites et les notifications.
      </Typography>

      <Stack direction="row" spacing={3.5} alignItems="flex-start">
        <Stack sx={{ width: 200, flex: "none", position: "sticky", top: 0 }} spacing={0.25}>
          {NAV.map((n) => {
            const active = section === n.key;
            return (
              <Box
                key={n.key} component="button" onClick={() => setSection(n.key)}
                sx={{
                  display: "flex", alignItems: "center", gap: 1.1, border: "none", cursor: "pointer", fontFamily: "inherit",
                  textAlign: "left", px: 1.5, py: 1.1, borderRadius: planningV2Radii.button, fontSize: 13.5, fontWeight: 600,
                  bgcolor: active ? planningV2Colors.selectedBg : "transparent",
                  color: active ? planningV2Colors.brand : planningV2Colors.textBody,
                  "&:hover": { bgcolor: active ? planningV2Colors.selectedBg : "#F8FAFC" },
                }}
              >
                {n.icon}
                {n.label}
              </Box>
            );
          })}
        </Stack>

        <Box sx={{ flex: 1, minWidth: 0 }}>
          {section === "periodes" && <ShiftPeriodSettings />}
          {section === "groupes" && <SiteGroupSettings />}
          {section === "notifications" && (
            <Box>
              <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
                {NOTIF_CHANNELS.map((n, idx) => (
                  <Stack
                    key={n.channel} direction="row" alignItems="center" spacing={1.75}
                    sx={{ px: 2.25, py: 2, borderBottom: idx < NOTIF_CHANNELS.length - 1 ? `1px solid ${planningV2Colors.divider}` : "none" }}
                  >
                    <Box sx={{
                      width: 34, height: 34, borderRadius: planningV2Radii.button, flex: "none", display: "flex", alignItems: "center", justifyContent: "center",
                      bgcolor: n.active ? planningV2Colors.infoBg : "#F1F4F7", color: n.active ? planningV2Colors.brand : planningV2Colors.textSecondary,
                    }}>
                      <NotificationsNoneOutlinedIcon sx={{ fontSize: 17 }} />
                    </Box>
                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Stack direction="row" alignItems="center" spacing={1}>
                        <Typography sx={{ fontSize: 14, fontWeight: 600 }}>{n.channel}</Typography>
                        {n.soon && (
                          <Typography sx={{ fontSize: 10.5, fontWeight: 700, letterSpacing: "0.04em", textTransform: "uppercase", color: planningV2Colors.warnFg, bgcolor: planningV2Colors.warnBg, px: 0.9, py: 0.15, borderRadius: planningV2Radii.pill }}>
                            Bientôt
                          </Typography>
                        )}
                      </Stack>
                      <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, mt: 0.25 }}>{n.desc}</Typography>
                    </Box>
                    <SwitchPill on={n.active} disabled={n.soon} />
                  </Stack>
                ))}
              </Box>
              <Stack direction="row" alignItems="center" spacing={1} sx={{ mt: 1.75 }}>
                <InfoOutlinedIcon sx={{ fontSize: 15, color: planningV2Colors.textSecondary }} />
                <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textSecondary }}>
                  Les notifications push arriveront avec l'application mobile. Le réglage est déjà prévu — les préférences détaillées par canal seront configurables ici une fois l'API disponible.
                </Typography>
              </Stack>
            </Box>
          )}
        </Box>
      </Stack>
    </Box>
  );
}

function SwitchPill({ on, disabled }: { on: boolean; disabled?: boolean }) {
  return (
    <Box
      sx={{
        width: 42, height: 24, borderRadius: planningV2Radii.pill, flex: "none", position: "relative",
        bgcolor: on ? planningV2Colors.brand : "#E7EBEF", opacity: disabled ? 0.5 : 1, transition: "background .15s",
      }}
    >
      <Box sx={{
        position: "absolute", top: 2, left: on ? 21 : 2, width: 20, height: 20, borderRadius: "999px",
        bgcolor: "#fff", boxShadow: "0 1px 3px rgba(0,0,0,.2)", transition: "left .15s",
      }} />
    </Box>
  );
}
