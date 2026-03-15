import {
  Alert,
  Avatar,
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
  Drawer,
  Paper,
  Stack,
  Typography,
} from "@mui/material";

import { DrawerSection } from "../../manager-instrumentists/components/DrawerSection";
import { AddSurgeonSiteMembershipDialog } from "./AddSurgeonSiteMembershipDialog";
import { SurgeonPlanningSection } from "./SurgeonPlanningSection";
import ConfirmDeleteDialog from "../../encoding/components/ConfirmDeleteDialog";
import { useSurgeonDrawer } from "../hooks/useSurgeonDrawer";
import { buildProfilePictureUrl } from "../../manager-instrumentists/utils/instrumentists.utils";

type SurgeonDrawerProps = {
  open: boolean;
  surgeonId: number | null;
  onClose: () => void;
};

export function SurgeonDrawer({
  open,
  surgeonId,
  onClose,
}: SurgeonDrawerProps) {
  const {
    surgeon,
    isLoading,
    isError,
    refetch,
    headerDisplayName,

    activeSection,
    scrollToSection,

    addSiteOpen,
    setAddSiteOpen,

    membershipToDelete,
    setMembershipToDelete,

    displayedMemberships,

    deleteMembershipMutation,
    addMembershipMutation,

    informationSectionRef,
    sitesSectionRef,
    planningSectionRef,
  } = useSurgeonDrawer(surgeonId, open);

  return (
    <>
      <AddSurgeonSiteMembershipDialog
        open={addSiteOpen}
        memberships={displayedMemberships}
        onClose={() => setAddSiteOpen(false)}
        onAddSite={(siteId, siteName) =>
          addMembershipMutation.mutate({ siteId, siteName })
        }
        isAdding={addMembershipMutation.isPending}
      />

      <ConfirmDeleteDialog
        open={membershipToDelete !== null}
        loading={deleteMembershipMutation.isPending}
        title="Retirer cette affiliation ?"
        message={
          membershipToDelete
            ? `${membershipToDelete.site.name} sera retiré des affiliations de ${headerDisplayName}.`
            : ""
        }
        onClose={() => {
          if (!deleteMembershipMutation.isPending) {
            setMembershipToDelete(null);
          }
        }}
        onConfirm={() => {
          if (!membershipToDelete || deleteMembershipMutation.isPending) {
            return;
          }

          deleteMembershipMutation.mutate(membershipToDelete);
        }}
      />

      <Drawer
        anchor="right"
        open={open}
        onClose={onClose}
        PaperProps={{
          sx: {
            width: { xs: "100%", sm: 520, md: 560 },
          },
        }}
      >
        <Box
          sx={{ height: "100%", display: "flex", flexDirection: "column" }}
        >
          <Stack
            direction="row"
            justifyContent="space-between"
            alignItems="center"
            spacing={2}
            sx={{ p: 2 }}
          >
            <Typography variant="h6">Fiche chirurgien</Typography>

            <Button onClick={onClose}>Fermer</Button>
          </Stack>

          <Divider />

          <Box sx={{ flex: 1, overflowY: "auto", p: 2 }}>
            {isLoading ? (
              <Stack
                alignItems="center"
                justifyContent="center"
                spacing={2}
                sx={{ minHeight: 240 }}
              >
                <CircularProgress size={28} />
                <Typography variant="body2" color="text.secondary">
                  Chargement de la fiche chirurgien…
                </Typography>
              </Stack>
            ) : null}

            {!isLoading && isError ? (
              <Stack spacing={2}>
                <Alert severity="error">
                  Impossible de charger la fiche chirurgien.
                </Alert>

                <Box>
                  <Button variant="outlined" onClick={() => refetch()}>
                    Réessayer
                  </Button>
                </Box>
              </Stack>
            ) : null}

            {!isLoading && !isError && surgeon ? (
              <Stack spacing={2}>
                <Paper variant="outlined">
                  <Box sx={{ p: 2 }}>
                    <Stack spacing={1.5}>
                      <Stack direction="row" spacing={2} alignItems="center">
                        <Avatar
                          src={buildProfilePictureUrl(surgeon.profilePicturePath)}
                          alt={headerDisplayName}
                          sx={{ width: 56, height: 56, flexShrink: 0 }}
                        />
                        <Stack spacing={0.25}>
                          <Typography variant="h6" sx={{ lineHeight: 1.2 }}>
                            {headerDisplayName}
                          </Typography>
                          <Typography variant="body2" color="text.secondary">
                            {surgeon.email}
                          </Typography>
                        </Stack>
                      </Stack>

                      <Stack
                        direction="row"
                        spacing={1}
                        flexWrap="wrap"
                        useFlexGap
                      >
                        <Chip
                          size="small"
                          label={surgeon.active ? "Actif" : "Inactif"}
                          color={surgeon.active ? "success" : "default"}
                          variant={surgeon.active ? "filled" : "outlined"}
                        />
                      </Stack>

                      <Stack
                        direction="row"
                        spacing={1}
                        flexWrap="wrap"
                        useFlexGap
                      >
                        <Button
                          size="small"
                          variant="outlined"
                          onClick={() =>
                            scrollToSection(
                              "planning",
                              planningSectionRef.current,
                            )
                          }
                        >
                          Voir planning
                        </Button>

                        <Button
                          size="small"
                          variant="text"
                          onClick={() =>
                            scrollToSection(
                              "information",
                              informationSectionRef.current,
                            )
                          }
                        >
                          Voir informations
                        </Button>
                      </Stack>
                    </Stack>
                  </Box>
                </Paper>

                <Paper
                  variant="outlined"
                  sx={{
                    position: "sticky",
                    top: -16,
                    zIndex: 1,
                  }}
                >
                  <Stack
                    direction="row"
                    spacing={1}
                    flexWrap="wrap"
                    useFlexGap
                    sx={{ p: 1.5 }}
                  >
                    {(
                      [
                        { key: "information", label: "Informations" },
                        { key: "sites", label: "Sites" },
                        { key: "planning", label: "Planning" },
                      ] as const
                    ).map(({ key, label }) => (
                      <Button
                        key={key}
                        size="small"
                        variant={activeSection === key ? "contained" : "text"}
                        onClick={() =>
                          scrollToSection(
                            key,
                            {
                              information: informationSectionRef,
                              sites: sitesSectionRef,
                              planning: planningSectionRef,
                            }[key].current,
                          )
                        }
                      >
                        {label}
                      </Button>
                    ))}
                  </Stack>
                </Paper>

                <Box ref={informationSectionRef}>
                  <DrawerSection title="Informations générales">
                    <Stack spacing={1}>
                      <Box>
                        <Typography variant="caption" color="text.secondary">
                          Nom complet
                        </Typography>
                        <Typography variant="body2">
                          {headerDisplayName}
                        </Typography>
                      </Box>

                      <Box>
                        <Typography variant="caption" color="text.secondary">
                          Email
                        </Typography>
                        <Typography variant="body2">
                          {surgeon.email}
                        </Typography>
                      </Box>

                      <Box>
                        <Typography variant="caption" color="text.secondary">
                          Statut
                        </Typography>
                        <Typography variant="body2">
                          {surgeon.active ? "Actif" : "Inactif"}
                        </Typography>
                      </Box>
                    </Stack>
                  </DrawerSection>
                </Box>

                <Box ref={sitesSectionRef}>
                  <DrawerSection title="Affiliations sites">
                    <Stack spacing={2}>
                      <Stack
                        direction={{ xs: "column", sm: "row" }}
                        spacing={1}
                        justifyContent="space-between"
                        alignItems={{ xs: "stretch", sm: "center" }}
                      >
                        <Typography variant="body2" color="text.secondary">
                          Gérez les sites rattachés à ce chirurgien.
                        </Typography>

                        <Button
                          size="small"
                          variant="contained"
                          onClick={() => setAddSiteOpen(true)}
                        >
                          Ajouter un site
                        </Button>
                      </Stack>

                      {displayedMemberships.length === 0 ? (
                        <Alert severity="info">Aucune affiliation site.</Alert>
                      ) : (
                        <Stack spacing={1.25}>
                          {displayedMemberships.map((membership) => (
                            <Paper
                              key={membership.site.id}
                              variant="outlined"
                              sx={{
                                p: 1.25,
                                borderColor:
                                  membership.id < 0
                                    ? "primary.light"
                                    : "divider",
                                bgcolor:
                                  membership.id < 0
                                    ? "action.hover"
                                    : "background.paper",
                              }}
                            >
                              <Stack spacing={0.75}>
                                <Typography
                                  variant="body2"
                                  sx={{ fontWeight: 600 }}
                                >
                                  {membership.site.name}
                                </Typography>

                                <Stack
                                  direction="row"
                                  spacing={1}
                                  flexWrap="wrap"
                                  useFlexGap
                                  alignItems="center"
                                >
                                  <Chip
                                    size="small"
                                    color="primary"
                                    variant="outlined"
                                    label={membership.siteRole}
                                  />

                                  {membership.id < 0 ? (
                                    <Chip
                                      size="small"
                                      color="default"
                                      variant="outlined"
                                      label="En cours de synchronisation"
                                    />
                                  ) : null}
                                </Stack>

                                <Box>
                                  <Button
                                    size="small"
                                    color="error"
                                    onClick={() =>
                                      setMembershipToDelete(membership)
                                    }
                                  >
                                    Retirer
                                  </Button>
                                </Box>
                              </Stack>
                            </Paper>
                          ))}
                        </Stack>
                      )}
                    </Stack>
                  </DrawerSection>
                </Box>

                <Box ref={planningSectionRef}>
                  <DrawerSection title="Planning">
                    <SurgeonPlanningSection surgeonId={surgeon.id} />
                  </DrawerSection>
                </Box>
              </Stack>
            ) : null}
          </Box>
        </Box>
      </Drawer>
    </>
  );
}
