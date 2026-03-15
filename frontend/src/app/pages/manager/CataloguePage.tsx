import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  InputAdornment,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import SearchIcon from "@mui/icons-material/Search";

import {
  createMaterialItem,
  getMaterialItems,
  updateMaterialItem,
} from "../../features/manager-catalogue/api/catalogue.api";
import type { MaterialItemDTO } from "../../features/manager-catalogue/api/catalogue.types";
import {
  MaterialItemFormDialog,
  type MaterialItemFormValues,
} from "../../features/manager-catalogue/components/MaterialItemFormDialog";
import { useToast } from "../../ui/toast/useToast";

export default function CataloguePage() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [search, setSearch] = React.useState("");
  const [debouncedSearch, setDebouncedSearch] = React.useState("");

  React.useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  const [createOpen, setCreateOpen] = React.useState(false);
  const [editItem, setEditItem] = React.useState<MaterialItemDTO | null>(null);
  const [formError, setFormError] = React.useState<string | null>(null);

  // Pre-fill data when opening create dialog from a request context
  const [createInitial, setCreateInitial] =
    React.useState<Partial<MaterialItemFormValues> | undefined>(undefined);

  const listQuery = useQuery({
    queryKey: ["material-items", debouncedSearch],
    queryFn: () =>
      getMaterialItems({ search: debouncedSearch || undefined, limit: 100 }),
  });

  const items = listQuery.data?.items ?? [];

  const createMutation = useMutation({
    mutationFn: createMaterialItem,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["material-items"] });
      setCreateOpen(false);
      setFormError(null);
      toast.success("Matériel créé.");
    },
    onError: (err: any) => {
      setFormError(
        err?.response?.data?.message ?? "Erreur lors de la création.",
      );
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: MaterialItemFormValues }) =>
      updateMaterialItem(id, {
        firmId: body.firmId ?? undefined,
        label: body.label,
        unit: body.unit,
        referenceCode: body.referenceCode,
        isImplant: body.isImplant,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["material-items"] });
      setEditItem(null);
      setFormError(null);
      toast.success("Matériel mis à jour.");
    },
    onError: (err: any) => {
      setFormError(
        err?.response?.data?.message ?? "Erreur lors de la mise à jour.",
      );
    },
  });

  function handleOpenCreate(initial?: Partial<MaterialItemFormValues>) {
    setCreateInitial(initial);
    setFormError(null);
    setCreateOpen(true);
  }

  function handleOpenEdit(item: MaterialItemDTO) {
    setFormError(null);
    setEditItem(item);
  }

  return (
    <Stack spacing={2}>
      <Stack
        direction="row"
        justifyContent="space-between"
        alignItems="center"
      >
        <Typography variant="h6">Catalogue matériel</Typography>
        <Button
          variant="contained"
          size="small"
          onClick={() => handleOpenCreate()}
        >
          + Nouveau matériel
        </Button>
      </Stack>

      <TextField
        size="small"
        placeholder="Rechercher matériel ou firme…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        InputProps={{
          startAdornment: (
            <InputAdornment position="start">
              <SearchIcon fontSize="small" />
            </InputAdornment>
          ),
        }}
        sx={{ maxWidth: 400 }}
      />

      {listQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}>
          <CircularProgress size={28} />
        </Box>
      ) : listQuery.isError ? (
        <Alert severity="error">Impossible de charger le catalogue.</Alert>
      ) : (
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Nom</TableCell>
                <TableCell>Firme</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Référence</TableCell>
                <TableCell>Unité</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} align="center">
                    <Typography
                      variant="body2"
                      color="text.secondary"
                      sx={{ py: 2 }}
                    >
                      Aucun matériel trouvé.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                items.map((item) => (
                  <TableRow key={item.id} hover>
                    <TableCell>{item.label}</TableCell>
                    <TableCell>{item.firm?.name ?? "—"}</TableCell>
                    <TableCell>
                      {item.isImplant ? (
                        <Chip label="Implant" size="small" color="primary" variant="outlined" />
                      ) : (
                        <Chip label="Autre" size="small" variant="outlined" />
                      )}
                    </TableCell>
                    <TableCell>{item.referenceCode || "—"}</TableCell>
                    <TableCell>{item.unit}</TableCell>
                    <TableCell align="right">
                      <Button
                        size="small"
                        variant="text"
                        onClick={() => handleOpenEdit(item)}
                      >
                        Modifier
                      </Button>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {/* Dialog création */}
      <MaterialItemFormDialog
        open={createOpen}
        title="Créer matériel"
        initial={createInitial}
        submitLabel="Créer"
        loading={createMutation.isPending}
        error={formError}
        onClose={() => {
          setCreateOpen(false);
          setFormError(null);
        }}
        onSubmit={(values) => {
          if (!values.firmId) return;
          createMutation.mutate({
            firmId: values.firmId,
            label: values.label,
            unit: values.unit,
            referenceCode: values.referenceCode || undefined,
            isImplant: values.isImplant,
          });
        }}
      />

      {/* Dialog édition */}
      {editItem ? (
        <MaterialItemFormDialog
          open
          title="Modifier matériel"
          initial={{
            firmId: editItem.firm?.id ?? null,
            label: editItem.label,
            unit: editItem.unit,
            referenceCode: editItem.referenceCode,
            isImplant: editItem.isImplant,
          }}
          submitLabel="Enregistrer"
          loading={updateMutation.isPending}
          error={formError}
          onClose={() => {
            setEditItem(null);
            setFormError(null);
          }}
          onSubmit={(values) => {
            updateMutation.mutate({ id: editItem.id, body: values });
          }}
        />
      ) : null}
    </Stack>
  );
}
