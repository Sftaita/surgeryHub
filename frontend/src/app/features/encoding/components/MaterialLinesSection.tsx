import { Stack, Typography, Paper, Divider } from "@mui/material";
import type { EncodingIntervention } from "../api/encoding.types";

type Props = {
  interventions: EncodingIntervention[];
};

export default function MaterialLinesSection({ interventions }: Props) {
  const sortedInterventions = (interventions ?? [])
    .slice()
    .sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));

  const hasAnyLines = sortedInterventions.some(
    (i) => (i.materialLines ?? []).length > 0,
  );
  const hasAnyReqs = sortedInterventions.some(
    (i) => (i.materialItemRequests ?? []).length > 0,
  );

  if (!hasAnyLines && !hasAnyReqs) {
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

        {sortedInterventions.map((itv, itvIdx) => {
          const lines = itv.materialLines ?? [];
          const reqs = itv.materialItemRequests ?? [];

          if (lines.length === 0 && reqs.length === 0) return null;

          return (
            <Stack key={itv.id} spacing={1}>
              <Typography sx={{ fontWeight: 600 }}>
                {itv.code} — {itv.label}
              </Typography>

              {lines.length > 0 && (
                <Stack spacing={1}>
                  {lines.map((l, idx) => (
                    <Stack key={l.id} spacing={0.5}>
                      <Typography>
                        {l.item?.label ?? "—"}{" "}
                        <Typography component="span" color="text.secondary">
                          ({l.item?.firm?.name ?? "—"} /{" "}
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

                      {idx < lines.length - 1 && <Divider />}
                    </Stack>
                  ))}
                </Stack>
              )}

              {reqs.length > 0 && (
                <Stack spacing={1}>
                  <Typography sx={{ fontWeight: 600 }}>
                    Demandes (hors catalogue)
                  </Typography>
                  {reqs.map((r, idx) => (
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

                      {idx < reqs.length - 1 && <Divider />}
                    </Stack>
                  ))}
                </Stack>
              )}

              {itvIdx < sortedInterventions.length - 1 && <Divider />}
            </Stack>
          );
        })}
      </Stack>
    </Paper>
  );
}
