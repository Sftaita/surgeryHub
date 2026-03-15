import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
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

import { addSiteMembership } from "../api/instrumentists.api";
import type { SiteMembershipDTO } from "../api/instrumentists.types";
import { fetchSites, type Site } from "../../sites/api/sites.api";
import { useToast } from "../../../ui/toast/useToast";
import {
  extractErrorMessage,
  isConflictError,
  mergeMembershipsBySiteId,
} from "../utils/instrumentists.utils";

type AddSiteMembershipDialogProps = {
  open: boolean;
  instrumentistId: number | null;
  memberships: SiteMembershipDTO[];
  onClose: () => void;
  onSetMemberships: (nextMemberships: SiteMembershipDTO[]) => void;
  onRefreshRequested: () => Promise<void>;
};

export function AddSiteMembershipDialog({
  open,
  instrumentistId,
  memberships,
  onClose,
  onSetMemberships,
  onRefreshRequested,
}: AddSiteMembershipDialogProps) {
  const toast = useToast();
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

  const addMutation = useMutation({
    mutationFn: async (sitesToAdd: Site[]) => {
      if (instrumentistId === null) {
        throw new Error("Instrumentiste introuvable.");
      }

      const optimisticMemberships: SiteMembershipDTO[] = sitesToAdd.map(
        (site, index) => ({
          id: -(Date.now() + index + 1),
          site: {
            id: site.id,
            name: site.name,
          },
          siteRole: "INSTRUMENTIST",
        }),
      );

      const previousMemberships = memberships;

      onSetMemberships(
        mergeMembershipsBySiteId([...memberships, ...optimisticMemberships]),
      );

      try {
        const createdMemberships: SiteMembershipDTO[] = [];

        for (const site of sitesToAdd) {
          const created = await addSiteMembership(instrumentistId, {
            siteId: site.id,
          });
          createdMemberships.push(created);
        }

        return {
          optimisticMemberships,
          createdMemberships,
          previousMemberships,
        };
      } catch (err) {
        throw {
          originalError: err,
          optimisticIds: optimisticMemberships.map((item) => item.id),
          previousMemberships,
        };
      }
    },
    onSuccess: async ({
      optimisticMemberships,
      createdMemberships,
      previousMemberships,
    }) => {
      const optimisticIds = new Set(
        optimisticMemberships.map((item) => item.id),
      );

      onSetMemberships(
        mergeMembershipsBySiteId([
          ...previousMemberships.filter((item) => !optimisticIds.has(item.id)),
          ...createdMemberships,
        ]),
      );

      const count = createdMemberships.length;
      toast.success(
        count > 1 ? `${count} affiliations ajoutées.` : "Affiliation ajoutée.",
      );

      setSearch("");
      setSelectedSiteIds([]);
      onClose();

      await onRefreshRequested();
    },
    onError: (wrappedErr: any) => {
      const err = wrappedErr?.originalError ?? wrappedErr;
      const previousMemberships: SiteMembershipDTO[] =
        wrappedErr?.previousMemberships ?? memberships;

      onSetMemberships(previousMemberships);

      if (isConflictError(err)) {
        toast.error(
          err?.response?.data?.error?.message ??
            "Ce site est déjà affilié à cet instrumentiste.",
        );
        return;
      }

      toast.error(extractErrorMessage(err));
    },
  });

  useEffect(() => {
    if (!open) {
      setSearch("");
      setSelectedSiteIds([]);
    }
  }, [open]);

  const handleSubmit = async () => {
    if (selectedSiteIds.length === 0 || addMutation.isPending) {
      return;
    }

    const sitesToAdd = availableSites.filter((site) =>
      selectedSiteIds.includes(site.id),
    );

    if (sitesToAdd.length === 0) {
      onClose();
      return;
    }

    await addMutation.mutateAsync(sitesToAdd);
  };

  const noAvailableSite =
    !sitesLoading && !sitesError && availableSites.length === 0;

  return (
    <Dialog
      open={open}
      onClose={addMutation.isPending ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Ajouter un site</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography variant="body2" color="text.secondary">
            Sélectionnez un ou plusieurs sites à rattacher à cet instrumentiste.
          </Typography>

          <TextField
            label="Rechercher un site"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            size="small"
            fullWidth
            disabled={addMutation.isPending || sitesLoading || noAvailableSite}
          />

          {sitesError ? (
            <Alert severity="warning">
              Impossible de charger les sites disponibles.
            </Alert>
          ) : null}

          {noAvailableSite ? (
            <Alert severity="info">
              Aucun autre site n'est disponible pour cet instrumentiste.
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
        <Button onClick={onClose} disabled={addMutation.isPending}>
          Annuler
        </Button>

        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={
            addMutation.isPending ||
            selectedSiteIds.length === 0 ||
            sitesLoading ||
            noAvailableSite
          }
        >
          {addMutation.isPending ? "Ajout..." : "Ajouter"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
