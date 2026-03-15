import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Paper,
  Stack,
  TextField,
  Typography,
} from "@mui/material";

import type { SurgeonSiteMembershipDTO } from "../api/surgeons.types";
import { fetchSites, type Site } from "../../sites/api/sites.api";

type AddSurgeonSiteMembershipDialogProps = {
  open: boolean;
  memberships: SurgeonSiteMembershipDTO[];
  onClose: () => void;
  onAddSite: (siteId: number, siteName: string) => void;
  isAdding: boolean;
};

export function AddSurgeonSiteMembershipDialog({
  open,
  memberships,
  onClose,
  onAddSite,
  isAdding,
}: AddSurgeonSiteMembershipDialogProps) {
  const [search, setSearch] = useState("");
  const [selectedSiteIds, setSelectedSiteIds] = useState<number[]>([]);

  const {
    data: sites = [],
    isLoading: sitesLoading,
    isError: sitesError,
  } = useQuery<Site[]>({
    queryKey: ["sites"],
    queryFn: fetchSites,
    enabled: open,
  });

  const existingSiteIds = useMemo(
    () => new Set(memberships.map((membership) => membership.site.id)),
    [memberships],
  );

  const availableSites = useMemo(
    () => sites.filter((site) => !existingSiteIds.has(site.id)),
    [sites, existingSiteIds],
  );

  const filteredSites = useMemo(() => {
    const term = search.trim().toLowerCase();

    if (term === "") {
      return availableSites;
    }

    return availableSites.filter((site) =>
      site.name.toLowerCase().includes(term),
    );
  }, [availableSites, search]);

  const toggleSite = (siteId: number) => {
    setSelectedSiteIds((current) =>
      current.includes(siteId)
        ? current.filter((id) => id !== siteId)
        : [...current, siteId],
    );
  };

  useEffect(() => {
    if (!open) {
      setSearch("");
      setSelectedSiteIds([]);
    }
  }, [open]);

  const handleSubmit = async () => {
    if (selectedSiteIds.length === 0 || isAdding) {
      return;
    }

    const sitesToAdd = availableSites.filter((site) =>
      selectedSiteIds.includes(site.id),
    );

    if (sitesToAdd.length === 0) {
      onClose();
      return;
    }

    for (const site of sitesToAdd) {
      onAddSite(site.id, site.name);
    }

    setSearch("");
    setSelectedSiteIds([]);
    onClose();
  };

  const noAvailableSite =
    !sitesLoading && !sitesError && availableSites.length === 0;

  return (
    <Dialog
      open={open}
      onClose={isAdding ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Ajouter un site</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography variant="body2" color="text.secondary">
            Sélectionnez un ou plusieurs sites à rattacher à ce chirurgien.
          </Typography>

          <TextField
            label="Rechercher un site"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            size="small"
            fullWidth
            disabled={isAdding || sitesLoading || noAvailableSite}
          />

          {sitesError ? (
            <Alert severity="warning">
              Impossible de charger les sites disponibles.
            </Alert>
          ) : null}

          {noAvailableSite ? (
            <Alert severity="info">
              Aucun autre site n'est disponible pour ce chirurgien.
            </Alert>
          ) : null}

          {!sitesError && !noAvailableSite ? (
            <Paper
              variant="outlined"
              sx={{
                maxHeight: 280,
                overflowY: "auto",
                p: 1,
              }}
            >
              <Stack spacing={0.5}>
                {sitesLoading ? (
                  <Box sx={{ p: 2 }}>
                    <Typography variant="body2" color="text.secondary">
                      Chargement des sites…
                    </Typography>
                  </Box>
                ) : filteredSites.length === 0 ? (
                  <Box sx={{ p: 2 }}>
                    <Typography variant="body2" color="text.secondary">
                      Aucun site ne correspond à la recherche.
                    </Typography>
                  </Box>
                ) : (
                  filteredSites.map((site) => (
                    <FormControlLabel
                      key={site.id}
                      control={
                        <Checkbox
                          checked={selectedSiteIds.includes(site.id)}
                          onChange={() => toggleSite(site.id)}
                        />
                      }
                      label={site.name}
                      sx={{
                        mx: 0,
                        px: 1,
                        py: 0.5,
                        borderRadius: 1,
                        "&:hover": {
                          bgcolor: "action.hover",
                        },
                      }}
                    />
                  ))
                )}
              </Stack>
            </Paper>
          ) : null}
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={isAdding}>
          Annuler
        </Button>

        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={
            isAdding ||
            selectedSiteIds.length === 0 ||
            sitesLoading ||
            noAvailableSite
          }
        >
          {isAdding ? "Ajout..." : "Ajouter"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
