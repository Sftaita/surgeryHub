import * as React from "react";
import { Box, Tabs, Tab } from "@mui/material";
import PeopleAltOutlinedIcon from "@mui/icons-material/PeopleAltOutlined";
import PlayCircleOutlineIcon from "@mui/icons-material/PlayCircleOutline";
import NotificationsActiveOutlinedIcon from "@mui/icons-material/NotificationsActiveOutlined";
import TuneOutlinedIcon from "@mui/icons-material/TuneOutlined";

import { planningV2Colors } from "../theme/tokens";

export type PlanningV2TabKey = "posts" | "generate" | "alerts" | "settings";

const TABS: Array<{ key: PlanningV2TabKey; label: string; icon: React.ReactElement }> = [
  { key: "posts", label: "Postes", icon: <PeopleAltOutlinedIcon fontSize="small" /> },
  { key: "generate", label: "Générer planning", icon: <PlayCircleOutlineIcon fontSize="small" /> },
  { key: "alerts", label: "Alertes", icon: <NotificationsActiveOutlinedIcon fontSize="small" /> },
  { key: "settings", label: "Paramètres", icon: <TuneOutlinedIcon fontSize="small" /> },
];

interface Props {
  value: PlanningV2TabKey;
  onChange: (key: PlanningV2TabKey) => void;
  alertBadgeCount?: number;
}

export function PlanningV2Tabs({ value, onChange, alertBadgeCount }: Props) {
  return (
    <Box sx={{ borderBottom: 1, borderColor: planningV2Colors.cardBorder }}>
      <Tabs
        value={value}
        onChange={(_, v) => onChange(v)}
        TabIndicatorProps={{ sx: { backgroundColor: planningV2Colors.brand, height: 2 } }}
        sx={{
          minHeight: 48,
          "& .MuiTab-root": { minHeight: 48, textTransform: "none", fontWeight: 600, color: planningV2Colors.textBody },
          "& .Mui-selected": { color: `${planningV2Colors.brand} !important` },
        }}
      >
        {TABS.map((t) => (
          <Tab
            key={t.key}
            value={t.key}
            icon={t.icon}
            iconPosition="start"
            label={
              t.key === "alerts" && alertBadgeCount ? (
                <Box sx={{ display: "flex", alignItems: "center", gap: 0.75 }}>
                  {t.label}
                  <Box sx={{
                    minWidth: 18, height: 18, px: 0.5, borderRadius: "999px", fontSize: 11, fontWeight: 700,
                    bgcolor: planningV2Colors.warnDot, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center",
                  }}>
                    {alertBadgeCount}
                  </Box>
                </Box>
              ) : t.label
            }
          />
        ))}
      </Tabs>
    </Box>
  );
}
