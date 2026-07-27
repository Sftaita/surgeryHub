import { Box } from "@mui/material";
import { InterventionTypesManager } from "../../features/intervention-types/components/InterventionTypesManager";

export default function InterventionTypesPage() {
  return (
    <Box sx={{ p: 3, maxWidth: 900 }}>
      <InterventionTypesManager />
    </Box>
  );
}
