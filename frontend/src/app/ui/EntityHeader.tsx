import * as React from "react";
import { Box, Paper, Stack, Typography } from "@mui/material";

import { PersonAvatar } from "./avatar/PersonAvatar";

type EntityHeaderProps = {
  name: string;
  subtitle?: string;
  photoUrl?: string | null;
  /** Ligne de chips sous le nom (statut, type d'emploi, devise...). */
  chips?: React.ReactNode;
};

/**
 * En-tête de fiche (avatar + nom + sous-titre + chips) — remplace le bloc
 * dupliqué dans InstrumentistDrawer.tsx et SurgeonDrawer.tsx (même
 * composition `Paper > Stack > [PersonAvatar + nom/email] + chips`).
 */
export function EntityHeader({ name, subtitle, photoUrl, chips }: EntityHeaderProps) {
  return (
    <Paper variant="outlined">
      <Box sx={{ p: 2 }}>
        <Stack spacing={1.5}>
          <Stack direction="row" spacing={2} alignItems="center">
            <PersonAvatar name={name} photoUrl={photoUrl} size="lg" />
            <Stack spacing={0.25}>
              <Typography variant="h6" sx={{ lineHeight: 1.2 }}>
                {name}
              </Typography>
              {subtitle && (
                <Typography variant="body2" color="text.secondary">
                  {subtitle}
                </Typography>
              )}
            </Stack>
          </Stack>
          {chips && (
            <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
              {chips}
            </Stack>
          )}
        </Stack>
      </Box>
    </Paper>
  );
}

export default EntityHeader;
