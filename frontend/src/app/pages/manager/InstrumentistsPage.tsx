import { useState } from "react";
import { Box, Button, Stack, Typography } from "@mui/material";

import { InstrumentistsTable } from "../../features/manager-instrumentists/components/InstrumentistsTable";
import { CreateInstrumentistDialog } from "../../features/manager-instrumentists/components/CreateInstrumentistDialog";
import { InstrumentistDrawer } from "../../features/manager-instrumentists/components/InstrumentistDrawer";

export default function InstrumentistsPage() {
  const [createOpen, setCreateOpen] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [selectedInstrumentistId, setSelectedInstrumentistId] = useState<
    number | null
  >(null);

  const handleOpenInstrumentist = (id: number) => {
    setSelectedInstrumentistId(id);
    setDrawerOpen(true);
  };

  const handleCloseDrawer = () => {
    setDrawerOpen(false);
  };

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
              Module manager en cours d'initialisation.
            </Typography>
          </Stack>

          <Button variant="contained" onClick={() => setCreateOpen(true)}>
            + Instrumentiste
          </Button>
        </Stack>

        <InstrumentistsTable onOpenInstrumentist={handleOpenInstrumentist} />

        <CreateInstrumentistDialog
          open={createOpen}
          onClose={() => setCreateOpen(false)}
        />

        <InstrumentistDrawer
          open={drawerOpen}
          instrumentistId={selectedInstrumentistId}
          onClose={handleCloseDrawer}
        />
      </Stack>
    </Box>
  );
}
