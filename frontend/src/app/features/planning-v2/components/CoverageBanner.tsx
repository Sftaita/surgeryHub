import { Box, LinearProgress, Skeleton, Stack, Tooltip, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { fetchCoverageSummary } from "../api/planningV2.api";

interface CoverageBannerProps {
  versionId: number;
}

function bannerColor(pct: number): "success" | "warning" | "error" {
  if (pct >= 90) return "success";
  if (pct >= 70) return "warning";
  return "error";
}

export function CoverageBanner({ versionId }: CoverageBannerProps) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["coverage-summary", versionId],
    queryFn: () => fetchCoverageSummary(versionId),
    staleTime: 30_000,
  });

  if (isLoading) {
    return <Skeleton variant="rounded" height={48} sx={{ borderRadius: 2 }} />;
  }

  if (isError || !data) {
    return null;
  }

  const pct       = data.coveragePercent ?? 0;
  const color     = data.total === 0 ? "success" : bannerColor(pct);
  const pctLabel  = data.total === 0 ? "—" : `${pct}%`;

  return (
    <Box
      data-testid="coverage-banner"
      sx={{
        px: 2.5,
        py: 1.5,
        borderRadius: 2,
        border: "1px solid",
        borderColor: `${color}.light`,
        bgcolor: `${color}.50`,
      }}
    >
      <Stack direction="row" alignItems="center" spacing={2}>
        <Box sx={{ flex: 1 }}>
          <Stack direction="row" justifyContent="space-between" mb={0.5}>
            <Typography variant="body2" fontWeight={600} color={`${color}.dark`}>
              {data.covered}/{data.total} couverts · {data.open} au pool
              {data.cancelled > 0 && ` · ${data.cancelled} annulé${data.cancelled > 1 ? "s" : ""}`}
            </Typography>
            <Tooltip title="Taux de couverture (missions assignées / missions totales)">
              <Typography variant="body2" fontWeight={700} color={`${color}.dark`}>
                {pctLabel}
              </Typography>
            </Tooltip>
          </Stack>
          <LinearProgress
            variant="determinate"
            value={Math.min(pct, 100)}
            color={color}
            sx={{ height: 6, borderRadius: 3 }}
          />
        </Box>
      </Stack>
    </Box>
  );
}
