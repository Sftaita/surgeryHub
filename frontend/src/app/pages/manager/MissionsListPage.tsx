import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { DataGrid, GridColDef } from "@mui/x-data-grid";
import { Box, Button, Chip, Stack, Typography } from "@mui/material";
import AddIcon from "@mui/icons-material/Add";

import { fetchMissions } from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import { MissionsFiltersBar } from "../../features/missions/components/MissionsFiltersBar";
import {
  formatBrusselsRange,
  formatPersonLabel,
  formatMissionStatus,
  formatMissionType,
} from "../../../app/features/missions/utils/missions.format";

type ChipColor = "default" | "primary" | "secondary" | "error" | "info" | "success" | "warning";

function statusChipColor(status: string): ChipColor {
  switch (status) {
    case "DRAFT": return "default";
    case "OPEN": return "info";
    case "ASSIGNED": return "primary";
    case "SUBMITTED": return "warning";
    case "DECLARED": return "warning";
    case "VALIDATED": return "success";
    case "CLOSED": return "default";
    case "REJECTED": return "error";
    default: return "default";
  }
}

export default function MissionsListPage() {
  const navigate = useNavigate();
  const location = useLocation();

  const isToValidateView = location.pathname.endsWith("/to-validate");

  const [page, setPage] = useState(1);
  const limit = 100;

  const [filters, setFilters] = useState<{ status?: string; type?: string; siteId?: number }>({});

  useEffect(() => {
    setPage(1);
    setFilters((prev) => {
      if (isToValidateView) return { ...prev, status: "DECLARED" };
      return prev;
    });
  }, [isToValidateView]);

  const effectiveFilters = useMemo(() => {
    if (!isToValidateView) return filters;
    return { ...filters, status: "DECLARED" };
  }, [filters, isToValidateView]);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["missions", { page, limit, filters: effectiveFilters }],
    queryFn: () => fetchMissions(page, limit, effectiveFilters),
  });

  const rows = data?.items ?? [];
  const total = data?.total ?? 0;

  const columns = useMemo<GridColDef<Mission>[]>(
    () => [
      {
        field: "date",
        headerName: "Date / heure",
        flex: 1.5,
        sortable: false,
        renderCell: ({ row }) => (
          <Typography variant="body2">
            {formatBrusselsRange(row.startAt, row.endAt)}
          </Typography>
        ),
      },
      {
        field: "site",
        headerName: "Site",
        flex: 1,
        sortable: false,
        valueGetter: (_, row) => row.site?.name ?? "—",
      },
      {
        field: "type",
        headerName: "Type",
        flex: 0.9,
        sortable: false,
        renderCell: ({ row }) => (
          <Typography variant="body2" color="text.secondary">
            {formatMissionType(row.type)}
          </Typography>
        ),
      },
      {
        field: "surgeon",
        headerName: "Chirurgien",
        flex: 1,
        sortable: false,
        valueGetter: (_, row) => formatPersonLabel(row.surgeon),
      },
      {
        field: "instrumentist",
        headerName: "Instrumentiste",
        flex: 1,
        sortable: false,
        valueGetter: (_, row) => formatPersonLabel(row.instrumentist),
      },
      {
        field: "status",
        headerName: "Statut",
        flex: 0.9,
        sortable: false,
        renderCell: ({ row }) => (
          <Chip
            label={formatMissionStatus(row.status)}
            color={statusChipColor(String(row.status))}
            size="small"
            variant={row.status === "DRAFT" || row.status === "CLOSED" ? "outlined" : "filled"}
          />
        ),
      },
    ],
    [],
  );

  if (isError) return <Typography color="error" sx={{ p: 3 }}>Erreur de chargement</Typography>;

  return (
    <Box>
      <Stack direction="row" alignItems="center" justifyContent="space-between" mb={2.5}>
        <Typography variant="h6" fontWeight={600}>Missions</Typography>

        {!isToValidateView && (
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            onClick={() => navigate("/app/m/missions/new")}
            disableElevation
          >
            Nouvelle mission
          </Button>
        )}
      </Stack>

      <Stack direction="row" spacing={1} mb={2}>
        <Button
          variant={!isToValidateView ? "contained" : "outlined"}
          size="small"
          disableElevation
          onClick={() => navigate("/app/m/missions")}
        >
          Toutes
        </Button>
        <Button
          variant={isToValidateView ? "contained" : "outlined"}
          size="small"
          disableElevation
          onClick={() => navigate("/app/m/missions/to-validate")}
        >
          À valider
        </Button>
      </Stack>

      {!isToValidateView && (
        <MissionsFiltersBar
          status={effectiveFilters.status}
          type={effectiveFilters.type}
          siteId={effectiveFilters.siteId}
          onChange={(next) => {
            setPage(1);
            setFilters((prev) => ({ ...prev, ...next }));
          }}
        />
      )}

      <DataGrid
        rows={rows}
        columns={columns}
        getRowId={(row) => row.id}
        loading={isLoading}
        paginationMode="server"
        rowCount={total}
        pageSizeOptions={[limit]}
        paginationModel={{ page: page - 1, pageSize: limit }}
        onPaginationModelChange={(m) => setPage(m.page + 1)}
        autoHeight
        disableRowSelectionOnClick
        onRowClick={({ row }) => navigate(`/app/m/missions/${row.id}`)}
        sx={{
          border: "none",
          "& .MuiDataGrid-row": { cursor: "pointer" },
          "& .MuiDataGrid-row:hover": { bgcolor: "action.hover" },
          "& .MuiDataGrid-columnHeaders": { bgcolor: "grey.50" },
          "& .MuiDataGrid-cell": { alignContent: "center" },
        }}
      />
    </Box>
  );
}
