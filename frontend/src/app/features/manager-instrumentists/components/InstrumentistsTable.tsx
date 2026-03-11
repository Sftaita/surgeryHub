import { Box, Paper, Typography } from "@mui/material";

export function InstrumentistsTable() {
  return (
    <Paper variant="outlined">
      <Box sx={{ p: 2 }}>
        <Typography variant="subtitle1">Liste des instrumentistes</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
          Placeholder du lot MGR-INS-1.
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
          La table, les filtres et le chargement de données seront ajoutés dans
          le lot suivant.
        </Typography>
      </Box>
    </Paper>
  );
}
