import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
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
  const location = useLocation();

  const isToValidateView = location.pathname.endsWith("/to-validate");

  const [page, setPage] = useState(1);
  const limit = 100;

  const [filters, setFilters] = useState<{
    status?: string;
    type?: string;
    siteId?: number;
  }>({});

  // ✅ Vue “À valider” = status forcé à DECLARED (pas une déduction de droit, juste un filtre de liste)
  useEffect(() => {
    setPage(1);
    setFilters((prev) => {
      if (isToValidateView) {
        // on conserve type/siteId si déjà choisis, mais status fixé
        return { ...prev, status: "DECLARED" };
      }
      // en quittant la vue, on ne force plus le statut
      // (on ne reset pas agressivement: l'utilisateur peut déjà filtrer)
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
    [navigate],
  );

  if (isError) return <div>Erreur de chargement</div>;

  return (
    <Box sx={{ p: 2 }}>
      {/* Switch simple entre vues, sans refactor transversal */}
      <Stack direction="row" spacing={1} alignItems="center" mb={1.5}>
        <Button
          variant={!isToValidateView ? "contained" : "outlined"}
          onClick={() => navigate("/app/m/missions")}
        >
          Toutes
        </Button>

        <Button
          variant={isToValidateView ? "contained" : "outlined"}
          onClick={() => navigate("/app/m/missions/to-validate")}
        >
          À valider
        </Button>

        <Box sx={{ flex: 1 }} />

        {/* Le bouton “Créer” reste sur la liste classique (pas obligatoire côté “À valider”) */}
        {!isToValidateView ? (
          <Button
            variant="contained"
            onClick={() => navigate("/app/m/missions/new")}
          >
            Créer une mission
          </Button>
        ) : null}
      </Stack>

      <MissionsFiltersBar
        status={effectiveFilters.status}
        type={effectiveFilters.type}
        siteId={effectiveFilters.siteId}
        onChange={(next) => {
          setPage(1);

          // Vue “À valider” : on ignore toute tentative de changer le status
          // (on laisse type/siteId fonctionner normalement)
          if (isToValidateView) {
            const { status: _ignored, ...rest } = next as any;
            setFilters((prev) => ({
              ...prev,
              ...rest,
              status: "DECLARED",
            }));
            return;
          }

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
