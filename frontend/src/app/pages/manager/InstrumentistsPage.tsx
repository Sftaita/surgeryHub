import { useState } from "react";
import { Box, Button, Stack, Typography } from "@mui/material";

import { InstrumentistsTable } from "../../features/manager-instrumentists/components/InstrumentistsTable";
import { CreateInstrumentistDialog } from "../../features/manager-instrumentists/components/CreateInstrumentistDialog";

export default function InstrumentistsPage() {
  const [createOpen, setCreateOpen] = useState(false);

  return (
    <Box sx={{ p: 2 }}>
      <Stack spacing={2}>
        <Stack
          direction={{ xs: "column", sm: "row" }}
          spacing={1.5}
          justifyContent="space-between"
          alignItems={{ xs: "stretch", sm: "center" }}
        >
          <Stack spacing={0.5}>
            <Typography variant="h6">Instrumentistes</Typography>
            <Typography variant="body2" color="text.secondary">
              Module manager en cours d’initialisation.
            </Typography>
          </Stack>

          <Button variant="contained" onClick={() => setCreateOpen(true)}>
            + Instrumentiste
          </Button>
        </Stack>

        <InstrumentistsTable />

        <CreateInstrumentistDialog
          open={createOpen}
          onClose={() => setCreateOpen(false)}
        />
      </Stack>
    </Box>
  );
}
