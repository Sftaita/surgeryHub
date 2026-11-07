import { Box } from "@mui/material";
import { InterventionTypesManager } from "../../features/intervention-types/components/InterventionTypesManager";

export default function InterventionTypesPage() {
  return (
    <Box sx={{ p: { xs: 0, md: 3 }, maxWidth: 900 }}>
      <InterventionTypesManager />
    </Box>
  );
}
