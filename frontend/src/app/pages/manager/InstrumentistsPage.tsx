import { Box, Stack, Typography } from "@mui/material";

import { InstrumentistsTable } from "../../features/manager-instrumentists/components/InstrumentistsTable";

export default function InstrumentistsPage() {
  return (
    <Box sx={{ p: 2 }}>
      <Stack spacing={2}>
        <Stack spacing={0.5}>
          <Typography variant="h6">Instrumentistes</Typography>
          <Typography variant="body2" color="text.secondary">
            Module manager en cours d’initialisation.
          </Typography>
        </Stack>

        <InstrumentistsTable />
      </Stack>
    </Box>
  );
}
