import { Stack, Typography, Paper, Divider } from "@mui/material";
import type { EncodingFirm } from "../api/encoding.types";
import MaterialLinesSection from "./MaterialLinesSection";

type Props = {
  firms: EncodingFirm[];
};

export default function FirmsSection({ firms }: Props) {
  if (!firms || firms.length === 0) {
    return (
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Typography variant="subtitle2">Firms</Typography>
        <Typography color="text.secondary">Aucune firm</Typography>
      </Paper>
    );
  }

  return (
    <Paper variant="outlined" sx={{ p: 2 }}>
      <Stack spacing={2}>
        <Typography variant="subtitle2">Firms</Typography>

        {firms.map((f, idx) => (
          <Stack key={f.id} spacing={1}>
            <Typography sx={{ fontWeight: 600 }}>{f.firmName}</Typography>

            <MaterialLinesSection
              materialLines={f.materialLines ?? []}
              materialItemRequests={f.materialItemRequests ?? []}
            />

            {idx < firms.length - 1 && <Divider />}
          </Stack>
        ))}
      </Stack>
    </Paper>
  );
}
