import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { DataGrid, GridColDef } from "@mui/x-data-grid";
import {
  Alert,
  Box,
  Button,
  Paper,
  Stack,
  TextField,
  Typography,
} from "@mui/material";

import { getSurgeons } from "../api/surgeons.api";
import type { SurgeonListItemDTO } from "../api/surgeons.types";

function buildDisplayName(row: SurgeonListItemDTO): string {
  if (row.displayName && row.displayName.trim() !== "") {
    return row.displayName;
  }

  const fullname = [row.firstname, row.lastname]
    .filter((value): value is string => Boolean(value && value.trim() !== ""))
    .join(" ")
    .trim();

  return fullname !== "" ? fullname : "—";
}

function EmptyState() {
  return (
    <Stack
      alignItems="center"
      justifyContent="center"
      sx={{ py: 6, px: 2, minHeight: 220 }}
      spacing={1}
    >
      <Typography variant="subtitle1">Aucun chirurgien trouvé</Typography>
      <Typography variant="body2" color="text.secondary" textAlign="center">
        Aucun résultat ne correspond aux filtres actuels.
      </Typography>
    </Stack>
  );
}

type SurgeonsTableProps = {
  onOpenSurgeon: (id: number) => void;
};

export function SurgeonsTable({ onOpenSurgeon }: SurgeonsTableProps) {
  const [search, setSearch] = useState("");

  const params = useMemo(() => {
    const trimmedSearch = search.trim();
    return trimmedSearch !== "" ? { q: trimmedSearch } : undefined;
  }, [search]);

  const { data, isLoading, isError } = useQuery<{
    items: SurgeonListItemDTO[];
    total: number;
  }>({
    queryKey: ["surgeons", params],
    queryFn: () => getSurgeons(params),
  });

  const rows = data?.items ?? [];

  const columns = useMemo<GridColDef<SurgeonListItemDTO>[]>(
    () => [
      {
        field: "displayName",
        headerName: "Nom",
        flex: 1.2,
        sortable: false,
        valueGetter: (_value, row) => buildDisplayName(row),
      },
      {
        field: "email",
        headerName: "Email",
        flex: 1.4,
        sortable: false,
      },
      {
        field: "actions",
        headerName: "Actions",
        flex: 0.7,
        sortable: false,
        filterable: false,
        renderCell: ({ row }) => (
          <Button size="small" onClick={() => onOpenSurgeon(row.id)}>
            Ouvrir
          </Button>
        ),
      },
    ],
    [onOpenSurgeon],
  );

  return (
    <Stack spacing={2}>
      <Paper variant="outlined">
        <Stack
          direction={{ xs: "column", md: "row" }}
          spacing={1.5}
          sx={{ p: 2 }}
        >
          <TextField
            label="Rechercher"
            placeholder="Nom ou email"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            fullWidth
            size="small"
          />
        </Stack>
      </Paper>

      {isError ? (
        <Alert severity="error">
          Impossible de charger la liste des chirurgiens.
        </Alert>
      ) : null}

      <Paper variant="outlined">
        <Box sx={{ p: 2, pb: 0 }}>
          <Typography variant="subtitle1">Liste des chirurgiens</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
            {isLoading
              ? "Chargement en cours…"
              : `${data?.total ?? 0} chirurgien(s)`}
          </Typography>
        </Box>

        <Box sx={{ p: 2 }}>
          <DataGrid
            rows={rows}
            columns={columns}
            getRowId={(row) => row.id}
            loading={isLoading}
            autoHeight
            hideFooter
            disableRowSelectionOnClick
            slots={{
              noRowsOverlay: EmptyState,
            }}
          />
        </Box>
      </Paper>
    </Stack>
  );
}
