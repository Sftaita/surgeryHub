import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { DataGrid, GridColDef } from "@mui/x-data-grid";
import {
  Alert,
  Box,
  Button,
  Chip,
  MenuItem,
  Paper,
  Stack,
  TextField,
  Typography,
} from "@mui/material";

import { getInstrumentists } from "../api/instrumentists.api";
import type {
  InstrumentistListItemDTO,
  InstrumentistsListResponseDTO,
} from "../api/instrumentists.types";
import type { InstrumentistsListQuery } from "../api/instrumentists.requests";
import { PersonAvatar } from "../../../ui/avatar/PersonAvatar";
import { buildProfilePictureUrl } from "../utils/instrumentists.utils";

function buildDisplayName(row: InstrumentistListItemDTO): string {
  if (row.displayName && row.displayName.trim() !== "") {
    return row.displayName;
  }

  const fullname = [row.firstname, row.lastname]
    .filter((value): value is string => Boolean(value && value.trim() !== ""))
    .join(" ")
    .trim();

  return fullname !== "" ? fullname : "—";
}

function getEmploymentTypeLabel(
  employmentType: InstrumentistListItemDTO["employmentType"],
): string {
  switch (employmentType) {
    case "EMPLOYEE":
      return "Employé";
    case "FREELANCER":
      return "Freelancer";
    default:
      return "—";
  }
}

function EmptyState() {
  return (
    <Stack
      alignItems="center"
      justifyContent="center"
      sx={{ py: 6, px: 2, minHeight: 220 }}
      spacing={1}
    >
      <Typography variant="subtitle1">Aucun instrumentiste trouvé</Typography>
      <Typography variant="body2" color="text.secondary" textAlign="center">
        Aucun résultat ne correspond aux filtres actuels.
      </Typography>
    </Stack>
  );
}

type InstrumentistsTableProps = {
  onOpenInstrumentist: (id: number) => void;
};

export function InstrumentistsTable({
  onOpenInstrumentist,
}: InstrumentistsTableProps) {
  const [search, setSearch] = useState("");
  const [activeFilter, setActiveFilter] = useState<
    "all" | "active" | "inactive"
  >("all");

  const query = useMemo<InstrumentistsListQuery>(() => {
    const trimmedSearch = search.trim();

    return {
      search: trimmedSearch !== "" ? trimmedSearch : undefined,
      active:
        activeFilter === "all"
          ? undefined
          : activeFilter === "active"
            ? true
            : false,
    };
  }, [search, activeFilter]);

  const { data, isLoading, isError } = useQuery<InstrumentistsListResponseDTO>({
    queryKey: ["instrumentists", query],
    queryFn: () => getInstrumentists(query),
  });

  const rows = data?.items ?? [];

  const columns = useMemo<GridColDef<InstrumentistListItemDTO>[]>(
    () => [
      {
        field: "displayName",
        headerName: "Nom",
        flex: 1.6,
        sortable: false,
        valueGetter: (_value, row) => buildDisplayName(row),
        renderCell: ({ row }) => {
          const name = buildDisplayName(row);
          return (
            <Stack direction="row" spacing={1.25} alignItems="center" sx={{ py: 0.5 }}>
              <PersonAvatar
                name={name}
                photoUrl={buildProfilePictureUrl(row.profilePicturePath)}
                size="sm"
              />
              <Stack spacing={0} sx={{ minWidth: 0 }}>
                <Typography variant="body2" noWrap>
                  {name}
                </Typography>
                <Typography variant="caption" color="text.secondary" noWrap>
                  {row.email}
                </Typography>
              </Stack>
            </Stack>
          );
        },
      },
      {
        field: "active",
        headerName: "Statut",
        flex: 0.8,
        sortable: false,
        renderCell: ({ row }) => (
          <Chip
            size="small"
            label={row.active ? "Actif" : "Suspendu"}
            color={row.active ? "success" : "default"}
            variant={row.active ? "filled" : "outlined"}
          />
        ),
      },
      {
        field: "employmentType",
        headerName: "Type",
        flex: 0.9,
        sortable: false,
        valueGetter: (value) =>
          getEmploymentTypeLabel(
            value as InstrumentistListItemDTO["employmentType"],
          ),
      },
      {
        field: "actions",
        headerName: "Actions",
        flex: 0.7,
        sortable: false,
        filterable: false,
        renderCell: ({ row }) => (
          <Button size="small" onClick={() => onOpenInstrumentist(row.id)}>
            Ouvrir
          </Button>
        ),
      },
    ],
    [onOpenInstrumentist],
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

          <TextField
            select
            label="Statut"
            value={activeFilter}
            onChange={(event) =>
              setActiveFilter(
                event.target.value as "all" | "active" | "inactive",
              )
            }
            size="small"
            sx={{ minWidth: { xs: "100%", md: 180 } }}
          >
            <MenuItem value="all">Tous</MenuItem>
            <MenuItem value="active">Actifs</MenuItem>
            <MenuItem value="inactive">Suspendus</MenuItem>
          </TextField>
        </Stack>
      </Paper>

      {isError ? (
        <Alert severity="error">
          Impossible de charger la liste des instrumentistes.
        </Alert>
      ) : null}

      <Paper variant="outlined">
        <Box sx={{ p: 2, pb: 0 }}>
          <Typography variant="subtitle1">Liste des instrumentistes</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
            {isLoading
              ? "Chargement en cours…"
              : `${data?.total ?? 0} instrumentiste(s)`}
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
