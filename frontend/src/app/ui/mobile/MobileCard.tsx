import { Paper } from "@mui/material";
import type { PaperProps } from "@mui/material";

export function MobileCard({ children, sx, ...props }: PaperProps) {
  return (
    <Paper
      elevation={0}
      sx={{
        borderRadius: 3,
        bgcolor: "background.paper",
        boxShadow: "0 2px 12px rgba(0,0,0,0.07)",
        overflow: "hidden",
        ...sx,
      }}
      {...props}
    >
      {children}
    </Paper>
  );
}
