import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { DataGrid, GridColDef } from "@mui/x-data-grid";
import { Box, Button, Chip, Stack } from "@mui/material";

import { fetchMissions } from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import { MissionsFiltersBar } from "../../features/missions/components/MissionsFiltersBar";
import {
  formatBrusselsRange,
  formatPersonLabel,
} from "../../../app/features/missions/utils/missions.format";

export default function MissionsListPage() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const limit = 100;

  const [filters, setFilters] = useState<{
    status?: string;
    type?: string;
    siteId?: number;
  }>({});

  const { data, isLoading, isError } = useQuery({
    queryKey: ["missions", { page, limit, filters }],
    queryFn: () => fetchMissions(page, limit, filters),
  });

  const rows = data?.items ?? [];
  const total = data?.total ?? 0;

  const columns = useMemo<GridColDef<Mission>[]>(
    () => [
      {
        field: "date",
        headerName: "Date / heure",
        flex: 1.4,
        sortable: false,
        renderCell: ({ row }) => (
          <Stack direction="row" spacing={1} alignItems="center">
            <span>{formatBrusselsRange(row.startAt, row.endAt)}</span>
            <Chip size="small" label={row.schedulePrecision} />
          </Stack>
        ),
      },
      {
        field: "site",
        headerName: "Site",
        flex: 1,
        valueGetter: (_, row) => row.site?.name ?? "—",
      },
      { field: "type", headerName: "Type", flex: 0.7 },
      {
        field: "surgeon",
        headerName: "Chirurgien",
        flex: 1,
        valueGetter: (_, row) => formatPersonLabel(row.surgeon),
      },
      {
        field: "instrumentist",
        headerName: "Instrumentiste",
        flex: 1,
        valueGetter: (_, row) => formatPersonLabel(row.instrumentist),
      },
      { field: "status", headerName: "Statut", flex: 0.7 },
      {
        field: "actions",
        headerName: "Actions",
        flex: 0.6,
        sortable: false,
        renderCell: ({ row }) => (
          <Button
            size="small"
            onClick={() => navigate(`/app/m/missions/${row.id}`)}
          >
            Ouvrir
          </Button>
        ),
      },
    ],
    [navigate]
  );

  if (isError) return <div>Erreur de chargement</div>;

  return (
    <Box sx={{ p: 2 }}>
      <Stack
        direction="row"
        justifyContent="space-between"
        alignItems="center"
        mb={1.5}
        gap={2}
      >
        <div />
        <Button
          variant="contained"
          onClick={() => navigate("/app/m/missions/new")}
        >
          Créer une mission
        </Button>
      </Stack>

      <MissionsFiltersBar
        status={filters.status}
        type={filters.type}
        siteId={filters.siteId}
        onChange={(next) => {
          setPage(1);
          setFilters((prev) => ({ ...prev, ...next }));
        }}
      />

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
      />
    </Box>
  );
}
