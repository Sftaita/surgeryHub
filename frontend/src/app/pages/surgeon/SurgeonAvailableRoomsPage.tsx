import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Alert, Box, CircularProgress, IconButton, Stack, Typography } from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";

import { getMyAvailableRooms } from "../../features/planning-v2/api/planningV2.api";
import { DateTile } from "../../ui/mobile/DateTile";
import { StatusPill } from "../../ui/mobile/StatusPill";
import { EmptyStateRow } from "../../features/mobile-planning/planningPrimitives";

const PERIOD_LABELS: Record<string, string> = { MATIN: "Matin", APRES_MIDI: "Après-midi", JOURNEE: "Journée" };

/**
 * « Salles libérées » (Lot D, post D-114) — vue chirurgien, scopée serveur à ses propres
 * affiliations (`GET /api/me/available-rooms`, jamais un `siteId` client de confiance
 * au-delà de cette intersection). Indépendante des emails Room Release et du toggle
 * `notifyColleaguesEnabled` : un créneau BLOCK réellement libéré est visible ici même si les
 * emails collègues sont désactivés pour le site concerné.
 *
 * Liste uniquement (§7 de la demande — priorité à une vue fiable, pas de calendrier dans ce
 * lot initial), tri chronologique croissant, aujourd'hui/futur uniquement par défaut (aucun
 * filtre exposé côté chirurgien — §16 : "inutile de surcharger").
 */
export default function SurgeonAvailableRoomsPage() {
  const navigate = useNavigate();

  const roomsQuery = useQuery({
    queryKey: ["available-rooms", "mine"],
    queryFn: () => getMyAvailableRooms({ limit: 100 }),
  });

  const items = roomsQuery.data?.items ?? [];

  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "20px" }}>
      <Stack direction="row" alignItems="center" spacing={1}>
        <IconButton size="small" onClick={() => navigate("/app/s/planning")} aria-label="Retour au planning">
          <ArrowBackIcon fontSize="small" />
        </IconButton>
        <Typography variant="h6" fontWeight={700}>Salles disponibles</Typography>
      </Stack>

      {roomsQuery.isError && <Alert severity="error">Impossible de charger les salles disponibles.</Alert>}

      {roomsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
          <CircularProgress size={28} />
        </Box>
      ) : items.length === 0 ? (
        <EmptyStateRow text="Aucune salle disponible pour le moment." />
      ) : (
        <Stack spacing={1.375}>
          {items.map((slot) => {
            const date = new Date(`${slot.occurrenceDate}T00:00:00`);
            const periodLabel = PERIOD_LABELS[slot.period] ?? slot.period;
            const timeLabel = slot.startTime && slot.endTime ? `${slot.startTime}–${slot.endTime}` : periodLabel;

            return (
              <Box
                key={slot.id}
                sx={{
                  display: "flex", alignItems: "center", gap: "14px", width: "100%",
                  background: "#fff", border: "1px solid #E7EBEF", borderRadius: "16px", padding: "14px 16px",
                  boxShadow: "0 1px 2px rgba(22,32,43,.05)",
                }}
              >
                <DateTile
                  day={String(date.getDate()).padStart(2, "0")}
                  month={date.toLocaleDateString("fr-BE", { month: "short" }).replace(".", "").toUpperCase()}
                  variant="aVenir"
                  preset="list"
                />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography sx={{ fontSize: 15, fontWeight: 700 }} noWrap>
                    {slot.site?.name ?? "—"} — {timeLabel}
                  </Typography>
                  <Typography sx={{ mt: "3px", fontSize: 13, color: "text.secondary" }} noWrap>
                    Libérée par {slot.surgeon?.name ?? "—"}
                  </Typography>
                </Box>
                <StatusPill variant="aVenir" label="Disponible" />
              </Box>
            );
          })}
        </Stack>
      )}
    </Box>
  );
}
