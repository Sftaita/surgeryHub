import { useEffect, useMemo, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Alert,
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

import { InstrumentistsTable } from "../../features/manager-instrumentists/components/InstrumentistsTable";
import { CreateInstrumentistDialog } from "../../features/manager-instrumentists/components/CreateInstrumentistDialog";
import { getInstrumentist } from "../../features/manager-instrumentists/api/instrumentists.api";
import type { InstrumentistDetailDTO } from "../../features/manager-instrumentists/api/instrumentists.types";

function buildDisplayName(instrumentist?: InstrumentistDetailDTO): string {
  if (!instrumentist) {
    return "—";
  }

  if (instrumentist.displayName && instrumentist.displayName.trim() !== "") {
    return instrumentist.displayName;
  }

  const fullname = [instrumentist.firstname, instrumentist.lastname]
    .filter((value): value is string => Boolean(value && value.trim() !== ""))
    .join(" ")
    .trim();

  return fullname !== "" ? fullname : "—";
}

function getEmploymentTypeLabel(
  employmentType: InstrumentistDetailDTO["employmentType"],
): string {
  switch (employmentType) {
    case "EMPLOYEE":
      return "Employé";
    case "FREELANCER":
      return "Freelancer";
    default:
      return employmentType;
  }
}

type DrawerSectionProps = {
  title: string;
  children: React.ReactNode;
};

function DrawerSection({ title, children }: DrawerSectionProps) {
  return (
    <Paper variant="outlined">
      <Box sx={{ p: 2 }}>
        <Typography variant="subtitle1">{title}</Typography>
        <Box sx={{ mt: 1.5 }}>{children}</Box>
      </Box>
    </Paper>
  );
}

type SectionKey = "information" | "sites" | "rates" | "status" | "planning";

export default function InstrumentistsPage() {
  const [createOpen, setCreateOpen] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [selectedInstrumentistId, setSelectedInstrumentistId] = useState<
    number | null
  >(null);
  const [activeSection, setActiveSection] = useState<SectionKey>("information");

  const informationSectionRef = useRef<HTMLDivElement | null>(null);
  const sitesSectionRef = useRef<HTMLDivElement | null>(null);
  const ratesSectionRef = useRef<HTMLDivElement | null>(null);
  const statusSectionRef = useRef<HTMLDivElement | null>(null);
  const planningSectionRef = useRef<HTMLDivElement | null>(null);

  const handleOpenInstrumentist = (id: number) => {
    setSelectedInstrumentistId(id);
    setDrawerOpen(true);
    setActiveSection("information");
  };

  const handleCloseDrawer = () => {
    setDrawerOpen(false);
    setActiveSection("information");
  };

  const scrollToSection = (
    sectionKey: SectionKey,
    section: HTMLDivElement | null,
  ) => {
    if (!section) {
      return;
    }

    setActiveSection(sectionKey);

    section.scrollIntoView({
      behavior: "smooth",
      block: "start",
    });
  };

  const {
    data: instrumentist,
    isLoading,
    isError,
    refetch,
  } = useQuery<InstrumentistDetailDTO>({
    queryKey: ["instrumentist-detail", selectedInstrumentistId],
    queryFn: () => getInstrumentist(selectedInstrumentistId as number),
    enabled: drawerOpen && selectedInstrumentistId !== null,
  });

  useEffect(() => {
    if (!drawerOpen) {
      return;
    }

    setActiveSection("information");
  }, [drawerOpen, selectedInstrumentistId]);

  const headerDisplayName = useMemo(
    () => buildDisplayName(instrumentist),
    [instrumentist],
  );

  return (
    <Box sx={{ p: 2 }}>
      <Stack spacing={2}>
        <Stack
          direction={{ xs: "column", sm: "row" }}
          spacing={1.5}
          justifyContent="space-between"
          alignItems={{ xs: "stretch", sm: "center" }}
        >
          <Stack spacing={0.5}>
            <Typography variant="h6">Instrumentistes</Typography>
            <Typography variant="body2" color="text.secondary">
              Module manager en cours d’initialisation.
            </Typography>
          </Stack>

          <Button variant="contained" onClick={() => setCreateOpen(true)}>
            + Instrumentiste
          </Button>
        </Stack>

        <InstrumentistsTable onOpenInstrumentist={handleOpenInstrumentist} />

        <CreateInstrumentistDialog
          open={createOpen}
          onClose={() => setCreateOpen(false)}
        />

        <Drawer
          anchor="right"
          open={drawerOpen}
          onClose={handleCloseDrawer}
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

              <Button onClick={handleCloseDrawer}>Fermer</Button>
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
                        <Stack spacing={0.5}>
                          <Typography variant="h6">
                            {headerDisplayName}
                          </Typography>
                          <Typography variant="body2" color="text.secondary">
                            {instrumentist.email}
                          </Typography>
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
                      <Button
                        size="small"
                        variant={
                          activeSection === "information" ? "contained" : "text"
                        }
                        onClick={() =>
                          scrollToSection(
                            "information",
                            informationSectionRef.current,
                          )
                        }
                      >
                        Informations
                      </Button>
                      <Button
                        size="small"
                        variant={
                          activeSection === "sites" ? "contained" : "text"
                        }
                        onClick={() =>
                          scrollToSection("sites", sitesSectionRef.current)
                        }
                      >
                        Sites
                      </Button>
                      <Button
                        size="small"
                        variant={
                          activeSection === "rates" ? "contained" : "text"
                        }
                        onClick={() =>
                          scrollToSection("rates", ratesSectionRef.current)
                        }
                      >
                        Tarifs
                      </Button>
                      <Button
                        size="small"
                        variant={
                          activeSection === "status" ? "contained" : "text"
                        }
                        onClick={() =>
                          scrollToSection("status", statusSectionRef.current)
                        }
                      >
                        Statut
                      </Button>
                      <Button
                        size="small"
                        variant={
                          activeSection === "planning" ? "contained" : "text"
                        }
                        onClick={() =>
                          scrollToSection(
                            "planning",
                            planningSectionRef.current,
                          )
                        }
                      >
                        Planning
                      </Button>
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
                            Type d’emploi
                          </Typography>
                          <Typography variant="body2">
                            {getEmploymentTypeLabel(
                              instrumentist.employmentType,
                            )}
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
                      <Typography variant="body2" color="text.secondary">
                        Section préparée pour un prochain lot.
                      </Typography>
                    </DrawerSection>
                  </Box>

                  <Box ref={ratesSectionRef}>
                    <DrawerSection title="Tarifs">
                      <Typography variant="body2" color="text.secondary">
                        Section préparée pour un prochain lot.
                      </Typography>
                    </DrawerSection>
                  </Box>

                  <Box ref={statusSectionRef}>
                    <DrawerSection title="Statut">
                      <Typography variant="body2" color="text.secondary">
                        Section préparée pour un prochain lot.
                      </Typography>
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
      </Stack>
    </Box>
  );
}
