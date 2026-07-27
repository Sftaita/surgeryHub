import * as React from "react";
import { Box, Button, Stack, SvgIconTypeMap, Typography } from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import { OverridableComponent } from "@mui/material/OverridableComponent";

import { HelpButton } from "../features/help/HelpButton";

type PageHeaderProps = {
  icon?: OverridableComponent<SvgIconTypeMap>;
  title: string;
  subtitle?: string;
  /** Clé du topic d'aide (features/help/content/registry.ts) — bouton "?" masqué si absent. */
  helpTopicId?: string;
  action?: { label: string; onClick: () => void; icon?: OverridableComponent<SvgIconTypeMap> };
  /** Pour une zone d'action plus complexe qu'un simple bouton (plusieurs boutons, filtres...). */
  actions?: React.ReactNode;
};

/**
 * Header de page manager standard : icône + titre h5 + sous-titre + action
 * principale. Remplace la structure copiée-collée dans FirmsPage,
 * HospitalsPage, InterventionTypesPage (et candidate pour toute nouvelle
 * page manager, ex. PrestationsPage, DashboardPage).
 */
export function PageHeader({ icon: Icon, title, subtitle, helpTopicId, action, actions }: PageHeaderProps) {
  const ActionIcon = action?.icon ?? AddIcon;
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="flex-start" mb={3} flexWrap="wrap" gap={1.5}>
      <Box>
        <Stack direction="row" alignItems="center" spacing={1.5} mb={0.5}>
          {Icon && <Icon sx={{ color: "primary.main", fontSize: 28 }} />}
          <Typography variant="h5">{title}</Typography>
          {helpTopicId && <HelpButton topicId={helpTopicId} />}
        </Stack>
        {subtitle && (
          <Typography variant="body2" color="text.secondary">
            {subtitle}
          </Typography>
        )}
      </Box>
      {actions ?? (action && (
        <Button variant="contained" startIcon={<ActionIcon />} onClick={action.onClick} disableElevation>
          {action.label}
        </Button>
      ))}
    </Stack>
  );
}

export default PageHeader;
