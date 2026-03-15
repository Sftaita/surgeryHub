import { Box, Paper, Typography } from "@mui/material";

type DrawerSectionProps = {
  title: string;
  children: React.ReactNode;
};

export function DrawerSection({ title, children }: DrawerSectionProps) {
  return (
    <Paper variant="outlined">
      <Box sx={{ p: 2 }}>
        <Typography variant="subtitle1">{title}</Typography>
        <Box sx={{ mt: 1.5 }}>{children}</Box>
      </Box>
    </Paper>
  );
}
