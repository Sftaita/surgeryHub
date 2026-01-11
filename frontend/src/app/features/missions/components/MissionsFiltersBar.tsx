import { Box, MenuItem, TextField } from "@mui/material";

type Props = {
  status?: string;
  type?: string;
  siteId?: number;
  onChange: (next: { status?: string; type?: string; siteId?: number }) => void;
};

export function MissionsFiltersBar({ status, type, siteId, onChange }: Props) {
  return (
    <Box sx={{ display: "flex", gap: 2, mb: 2 }}>
      <TextField
        select
        label="Statut"
        size="small"
        value={status ?? ""}
        onChange={(e) => onChange({ status: e.target.value || undefined })}
        sx={{ minWidth: 160 }}
      >
        <MenuItem value="">Tous</MenuItem>
        <MenuItem value="DRAFT">DRAFT</MenuItem>
        <MenuItem value="PUBLISHED">PUBLISHED</MenuItem>
      </TextField>

      <TextField
        select
        label="Type"
        size="small"
        value={type ?? ""}
        onChange={(e) => onChange({ type: e.target.value || undefined })}
        sx={{ minWidth: 160 }}
      >
        <MenuItem value="">Tous</MenuItem>
        <MenuItem value="BLOCK">BLOCK</MenuItem>
        <MenuItem value="MISSION">MISSION</MenuItem>
      </TextField>
    </Box>
  );
}
