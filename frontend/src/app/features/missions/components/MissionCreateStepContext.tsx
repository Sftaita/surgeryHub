import {
  Box,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
} from "@mui/material";
import type { MissionType } from "../api/missions.types";

type Props = {
  sites: Array<{ id: number; name: string }>;
  surgeons: Array<{ id: number; label: string }>;
  value: {
    siteId?: number;
    surgeonUserId?: number;
    type: MissionType;
  };
  onChange: (next: Partial<Props["value"]>) => void;
};

export default function MissionCreateStepContext(props: Props) {
  const { sites, surgeons, value, onChange } = props;

  return (
    <Box>
      <Stack spacing={2}>
        <FormControl fullWidth>
          <InputLabel id="site-label">Site</InputLabel>
          <Select
            labelId="site-label"
            label="Site"
            value={value.siteId ?? ""}
            onChange={(e) => {
              const v = String(e.target.value);
              onChange({ siteId: v === "" ? undefined : Number(v) });
            }}
          >
            <MenuItem value="">—</MenuItem>
            {sites.map((s) => (
              <MenuItem key={s.id} value={s.id}>
                {s.name}
              </MenuItem>
            ))}
          </Select>
        </FormControl>

        <FormControl fullWidth>
          <InputLabel id="surgeon-label">Chirurgien</InputLabel>
          <Select
            labelId="surgeon-label"
            label="Chirurgien"
            value={value.surgeonUserId ?? ""}
            onChange={(e) => {
              const v = String(e.target.value);
              onChange({ surgeonUserId: v === "" ? undefined : Number(v) });
            }}
          >
            <MenuItem value="">—</MenuItem>
            {surgeons.map((u) => (
              <MenuItem key={u.id} value={u.id}>
                {u.label}
              </MenuItem>
            ))}
          </Select>
        </FormControl>

        <FormControl fullWidth>
          <InputLabel id="type-label">Activité</InputLabel>
          <Select
            labelId="type-label"
            label="Type"
            value={value.type}
            onChange={(e) => onChange({ type: e.target.value as MissionType })}
          >
            <MenuItem value="BLOCK">Bloc-opératoire</MenuItem>
            <MenuItem value="CONSULTATION">Consultation</MenuItem>
          </Select>
        </FormControl>
      </Stack>
    </Box>
  );
}
