import { Box, Paper, Stack, Typography } from "@mui/material";
import { usePwaInstallMenuState } from "./usePwaInstallMenuState";

/**
 * Point d'entrée permanent d'installation — §7. Pensé pour la page profil (mobile,
 * instrumentiste ; voir ProfilePage.tsx) ; le menu compte desktop utilise directement
 * `usePwaInstallMenuState()` dans un `MenuItem` MUI (voir DesktopLayout.tsx), même état,
 * pas de composant partagé forcé dans deux systèmes de menu différents.
 */
export function PwaInstallMenuItem() {
  const { label, actionLabel, onAction, disabled } = usePwaInstallMenuState();

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
      <Stack direction="row" alignItems="center" spacing={2}>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Typography variant="subtitle2" fontWeight={700}>
            {label}
          </Typography>
        </Box>
        {actionLabel && onAction && (
          <Box
            component="button"
            type="button"
            onClick={onAction}
            disabled={disabled}
            sx={{
              border: "none", borderRadius: "999px", background: "#2C7D5F", color: "#fff",
              fontFamily: "inherit", fontWeight: 700, fontSize: 13, cursor: "pointer",
              height: 36, padding: "0 16px", flexShrink: 0,
              "&:disabled": { opacity: 0.5, cursor: "default" },
            }}
          >
            {actionLabel}
          </Box>
        )}
      </Stack>
    </Paper>
  );
}
