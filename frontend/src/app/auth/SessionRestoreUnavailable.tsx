import { Box, Button, Typography } from "@mui/material";
import { useAuth } from "./AuthContext";

/**
 * D-157 — shown while a stored session cannot be checked because the server is unreachable
 * (offline launch of the PWA, timeout, 5xx). The session is kept and retried automatically:
 * this is never a logout, so the login form is not shown.
 */
export function SessionRestoreUnavailable() {
  const { retryRestore } = useAuth();

  return (
    <Box role="status" sx={{ p: 3, maxWidth: 420, mx: "auto", mt: 8, textAlign: "center" }}>
      <Typography variant="h6" sx={{ mb: 1 }}>
        Connexion au serveur impossible
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
        Vérifiez votre connexion Internet. Votre session est conservée : SurgicalHub se reconnecte
        automatiquement dès que le serveur répond.
      </Typography>
      <Button variant="contained" onClick={retryRestore}>
        Réessayer maintenant
      </Button>
    </Box>
  );
}
