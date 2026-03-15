import { useState } from "react";
import { Box, Button, Stack, Typography } from "@mui/material";

import { SurgeonsTable } from "../../features/manager-surgeons/components/SurgeonsTable";
import { CreateSurgeonDialog } from "../../features/manager-surgeons/components/CreateSurgeonDialog";
import { SurgeonDrawer } from "../../features/manager-surgeons/components/SurgeonDrawer";

export default function SurgeonsPage() {
  const [createOpen, setCreateOpen] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [selectedSurgeonId, setSelectedSurgeonId] = useState<number | null>(
    null,
  );

  const handleOpenSurgeon = (id: number) => {
    setSelectedSurgeonId(id);
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
            <Typography variant="h6">Chirurgiens</Typography>
            <Typography variant="body2" color="text.secondary">
              Gérez les chirurgiens de la plateforme.
            </Typography>
          </Stack>

          <Button variant="contained" onClick={() => setCreateOpen(true)}>
            + Ajouter un chirurgien
          </Button>
        </Stack>

        <SurgeonsTable onOpenSurgeon={handleOpenSurgeon} />

        <CreateSurgeonDialog
          open={createOpen}
          onClose={() => setCreateOpen(false)}
        />

        <SurgeonDrawer
          open={drawerOpen}
          surgeonId={selectedSurgeonId}
          onClose={handleCloseDrawer}
        />
      </Stack>
    </Box>
  );
}
