import { Box, MenuItem, TextField } from "@mui/material";

type Props = {
  status?: string;
  type?: string;
  siteId?: number;
  onChange: (next: { status?: string; type?: string; siteId?: number }) => void;
};

export function MissionsFiltersBar({ status, type, onChange }: Props) {
  return (
    <Box sx={{ display: "flex", gap: 2, mb: 2, flexWrap: "wrap" }}>
      <TextField
        select
        label="Statut"
        size="small"
        value={status ?? ""}
        onChange={(e) => onChange({ status: e.target.value || undefined, type })}
        sx={{ minWidth: 160 }}
      >
        <MenuItem value="">Tous les statuts</MenuItem>
        <MenuItem value="DRAFT">Brouillon</MenuItem>
        <MenuItem value="OPEN">Publiée</MenuItem>
        <MenuItem value="ASSIGNED">Assignée</MenuItem>
        <MenuItem value="SUBMITTED">Soumise</MenuItem>
        <MenuItem value="DECLARED">À valider</MenuItem>
        <MenuItem value="VALIDATED">Validée</MenuItem>
        <MenuItem value="CLOSED">Clôturée</MenuItem>
        <MenuItem value="REJECTED">Rejetée</MenuItem>
      </TextField>

      <TextField
        select
        label="Type"
        size="small"
        value={type ?? ""}
        onChange={(e) => onChange({ status, type: e.target.value || undefined })}
        sx={{ minWidth: 160 }}
      >
        <MenuItem value="">Tous les types</MenuItem>
        <MenuItem value="BLOCK">Bloc opératoire</MenuItem>
        <MenuItem value="CONSULTATION">Consultation</MenuItem>
      </TextField>
    </Box>
  );
}
