import { Stack, Typography, Paper, Divider } from "@mui/material";
import type {
  EncodingMaterialLine,
  EncodingMaterialItemRequest,
} from "../api/encoding.types";

type Props = {
  materialLines: EncodingMaterialLine[];
  materialItemRequests: EncodingMaterialItemRequest[];
};

export default function MaterialLinesSection({
  materialLines,
  materialItemRequests,
}: Props) {
  const hasLines = materialLines && materialLines.length > 0;
  const hasReqs = materialItemRequests && materialItemRequests.length > 0;

  if (!hasLines && !hasReqs) {
    return (
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Typography variant="subtitle2">Matériel</Typography>
        <Typography color="text.secondary">Aucune ligne matériel</Typography>
      </Paper>
    );
  }

  return (
    <Paper variant="outlined" sx={{ p: 2 }}>
      <Stack spacing={2}>
        <Typography variant="subtitle2">Matériel</Typography>

        {hasLines && (
          <Stack spacing={1}>
            <Typography sx={{ fontWeight: 600 }}>Lignes</Typography>
            {materialLines.map((l, idx) => (
              <Stack key={l.id} spacing={0.5}>
                <Typography>
                  {l.item?.label ?? "—"}{" "}
                  <Typography component="span" color="text.secondary">
                    ({l.item?.manufacturer ?? "—"} /{" "}
                    {l.item?.referenceCode ?? "—"})
                  </Typography>
                </Typography>

                <Typography color="text.secondary">
                  Qté: {String(l.quantity)}{" "}
                  {l.item?.unit ? `(${l.item.unit})` : ""}
                  {l.item?.isImplant ? " — implant" : ""}
                </Typography>

                <Typography color="text.secondary">
                  Commentaire: {String(l.comment ?? "")}
                </Typography>

                {idx < materialLines.length - 1 && <Divider />}
              </Stack>
            ))}
          </Stack>
        )}

        {hasReqs && (
          <Stack spacing={1}>
            <Typography sx={{ fontWeight: 600 }}>
              Demandes (hors catalogue)
            </Typography>
            {materialItemRequests.map((r, idx) => (
              <Stack key={r.id} spacing={0.5}>
                <Typography>
                  {r.label}{" "}
                  <Typography component="span" color="text.secondary">
                    ({r.referenceCode})
                  </Typography>
                </Typography>
                <Typography color="text.secondary">
                  Commentaire: {String(r.comment ?? "")}
                </Typography>

                {idx < materialItemRequests.length - 1 && <Divider />}
              </Stack>
            ))}
          </Stack>
        )}
      </Stack>
    </Paper>
  );
}
