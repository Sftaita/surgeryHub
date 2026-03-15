import {
  Alert,
  Avatar,
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
  Drawer,
  FormControlLabel,
  Paper,
  Stack,
  Switch,
  TextField,
  Typography,
} from "@mui/material";

import { DrawerSection } from "./DrawerSection";
import { AddSiteMembershipDialog } from "./AddSiteMembershipDialog";
import ConfirmDeleteDialog from "../../encoding/components/ConfirmDeleteDialog";
import { useInstrumentistDrawer } from "../hooks/useInstrumentistDrawer";
import {
  buildProfilePictureUrl,
  getEmploymentTypeLabel,
  normalizeRateValue,
} from "../utils/instrumentists.utils";

type InstrumentistDrawerProps = {
  open: boolean;
  instrumentistId: number | null;
  onClose: () => void;
};

export function InstrumentistDrawer({
  open,
  instrumentistId,
  onClose,
}: InstrumentistDrawerProps) {
  const {
    instrumentist,
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
    handleSetDisplayedMemberships,

    hourlyRateInput,
    setHourlyRateInput,
    consultationFeeInput,
    setConsultationFeeInput,
    ratesFeedback,
    setRatesFeedback,
    ratesMutation,
    handleSaveRates,

    deleteMembershipMutation,
    refreshInstrumentistDetail,

    statusMutation,

    informationSectionRef,
    sitesSectionRef,
    ratesSectionRef,
    statusSectionRef,
    planningSectionRef,
  } = useInstrumentistDrawer(instrumentistId, open);

  const hasRatesFeedback =
    ratesFeedback.type !== "idle" && ratesFeedback.message;

  return (
    <>
      <AddSiteMembershipDialog
        open={addSiteOpen}
        instrumentistId={instrumentistId}
        memberships={displayedMemberships}
        onClose={() => setAddSiteOpen(false)}
        onSetMemberships={handleSetDisplayedMemberships}
        onRefreshRequested={refreshInstrumentistDetail}
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
            <Typography variant="h6">Fiche instrumentiste</Typography>

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
                  Chargement de la fiche instrumentiste…
                </Typography>
              </Stack>
            ) : null}

            {!isLoading && isError ? (
              <Stack spacing={2}>
                <Alert severity="error">
                  Impossible de charger la fiche instrumentiste.
                </Alert>

                <Box>
                  <Button variant="outlined" onClick={() => refetch()}>
                    Réessayer
                  </Button>
                </Box>
              </Stack>
            ) : null}

            {!isLoading && !isError && instrumentist ? (
              <Stack spacing={2}>
                <Paper variant="outlined">
                  <Box sx={{ p: 2 }}>
                    <Stack spacing={1.5}>
                      <Stack direction="row" spacing={2} alignItems="center">
                        <Avatar
                          src={buildProfilePictureUrl(instrumentist.profilePicturePath)}
                          alt={headerDisplayName}
                          sx={{ width: 56, height: 56, flexShrink: 0 }}
                        />
                        <Stack spacing={0.25}>
                          <Typography variant="h6" sx={{ lineHeight: 1.2 }}>
                            {headerDisplayName}
                          </Typography>
                          <Typography variant="body2" color="text.secondary">
                            {instrumentist.email}
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
                          label={instrumentist.active ? "Actif" : "Suspendu"}
                          color={instrumentist.active ? "success" : "default"}
                          variant={
                            instrumentist.active ? "filled" : "outlined"
                          }
                        />
                        <Chip
                          size="small"
                          label={getEmploymentTypeLabel(
                            instrumentist.employmentType,
                          )}
                          variant="outlined"
                        />
                        <Chip
                          size="small"
                          label={`Devise : ${instrumentist.defaultCurrency}`}
                          variant="outlined"
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
                        { key: "rates", label: "Tarifs" },
                        { key: "status", label: "Statut" },
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
                              rates: ratesSectionRef,
                              status: statusSectionRef,
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
                          {instrumentist.email}
                        </Typography>
                      </Box>

                      <Box>
                        <Typography variant="caption" color="text.secondary">
                          Statut
                        </Typography>
                        <Typography variant="body2">
                          {instrumentist.active ? "Actif" : "Suspendu"}
                        </Typography>
                      </Box>

                      <Box>
                        <Typography variant="caption" color="text.secondary">
                          Type d'emploi
                        </Typography>
                        <Typography variant="body2">
                          {getEmploymentTypeLabel(instrumentist.employmentType)}
                        </Typography>
                      </Box>

                      <Box>
                        <Typography variant="caption" color="text.secondary">
                          Devise par défaut
                        </Typography>
                        <Typography variant="body2">
                          {instrumentist.defaultCurrency}
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
                          Gérez les sites rattachés à cet instrumentiste.
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

                <Box ref={ratesSectionRef}>
                  <DrawerSection title="Tarifs">
                    <Stack spacing={2}>
                      <Typography variant="body2" color="text.secondary">
                        Modifiez les tarifs puis enregistrez uniquement les
                        changements.
                      </Typography>

                      <Stack spacing={1.5}>
                        <TextField
                          label="Tarif bloc"
                          value={hourlyRateInput}
                          onChange={(event) => {
                            setHourlyRateInput(event.target.value);
                            if (ratesFeedback.type !== "idle") {
                              setRatesFeedback({
                                type: "idle",
                                message: "",
                              });
                            }
                          }}
                          size="small"
                          fullWidth
                          disabled={ratesMutation.isPending}
                          inputProps={{
                            inputMode: "decimal",
                          }}
                        />

                        <TextField
                          label="Tarif consultation"
                          value={consultationFeeInput}
                          onChange={(event) => {
                            setConsultationFeeInput(event.target.value);
                            if (ratesFeedback.type !== "idle") {
                              setRatesFeedback({
                                type: "idle",
                                message: "",
                              });
                            }
                          }}
                          size="small"
                          fullWidth
                          disabled={ratesMutation.isPending}
                          inputProps={{
                            inputMode: "decimal",
                          }}
                        />
                      </Stack>

                      {hasRatesFeedback ? (
                        <Alert
                          severity={
                            ratesFeedback.type === "error"
                              ? "error"
                              : ratesFeedback.type === "success"
                                ? "success"
                                : "info"
                          }
                        >
                          {ratesFeedback.message}
                        </Alert>
                      ) : null}

                      <Stack
                        direction="row"
                        spacing={1}
                        justifyContent="flex-end"
                      >
                        <Button
                          variant="outlined"
                          onClick={() => {
                            setHourlyRateInput(
                              normalizeRateValue(instrumentist.hourlyRate),
                            );
                            setConsultationFeeInput(
                              normalizeRateValue(
                                instrumentist.consultationFee,
                              ),
                            );
                            setRatesFeedback({
                              type: "idle",
                              message: "",
                            });
                          }}
                          disabled={ratesMutation.isPending}
                        >
                          Réinitialiser
                        </Button>

                        <Button
                          variant="contained"
                          onClick={handleSaveRates}
                          disabled={ratesMutation.isPending}
                        >
                          {ratesMutation.isPending
                            ? "Enregistrement..."
                            : "Enregistrer"}
                        </Button>
                      </Stack>
                    </Stack>
                  </DrawerSection>
                </Box>

                <Box ref={statusSectionRef}>
                  <DrawerSection title="Statut">
                    <Stack spacing={1.5}>
                      <FormControlLabel
                        control={
                          <Switch
                            checked={instrumentist.active}
                            onChange={() =>
                              statusMutation.mutate(instrumentist.active)
                            }
                            disabled={statusMutation.isPending}
                            color="success"
                          />
                        }
                        label={
                          <Stack direction="row" spacing={1} alignItems="center">
                            <Chip
                              size="small"
                              label={instrumentist.active ? "Actif" : "Suspendu"}
                              color={instrumentist.active ? "success" : "default"}
                              variant={instrumentist.active ? "filled" : "outlined"}
                            />
                            {statusMutation.isPending && (
                              <CircularProgress size={14} />
                            )}
                          </Stack>
                        }
                      />
                      <Typography variant="body2" color="text.secondary">
                        {instrumentist.active
                          ? "L'instrumentiste peut se connecter et accéder aux missions."
                          : "L'instrumentiste est suspendu et ne peut pas se connecter."}
                      </Typography>
                    </Stack>
                  </DrawerSection>
                </Box>

                <Box ref={planningSectionRef}>
                  <DrawerSection title="Planning">
                    <Typography variant="body2" color="text.secondary">
                      Section préparée pour un prochain lot.
                    </Typography>
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
