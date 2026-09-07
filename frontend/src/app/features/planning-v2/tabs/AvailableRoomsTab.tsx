import * as React from "react";
import {
  Alert, Box, CircularProgress, MenuItem, Select, Stack, Table, TableBody,
  TableCell, TableHead, TableRow, TablePagination, Typography,
} from "@mui/material";

import { getAvailableRoomsForManager, getAbsenceCommunicationSettings } from "../api/planningV2.api";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import type { SurgeonListItemDTO } from "../../manager-surgeons/api/surgeons.types";
import { useQuery } from "@tanstack/react-query";
import { planningV2Colors } from "../theme/tokens";

const PERIOD_LABELS: Record<string, string> = { MATIN: "Matin", APRES_MIDI: "Après-midi", JOURNEE: "Journée" };

/**
 * « Salles libérées » (Lot D, post D-114) — vue manager, tous les sites (aucun scoping par
 * site n'existe pour ce rôle dans ce projet, même règle que partout ailleurs). Indépendante
 * des emails Room Release et du toggle `notifyColleaguesEnabled` : un créneau BLOCK réellement
 * libéré est listé ici même si les emails collègues sont désactivés pour le site concerné.
 */
export function AvailableRoomsTab() {
  const [siteId, setSiteId] = React.useState<number | "">("");
  const [surgeonId, setSurgeonId] = React.useState<number | "">("");
  const [page, setPage] = React.useState(0);
  const [limit, setLimit] = React.useState(25);

  React.useEffect(() => { setPage(0); }, [siteId, surgeonId]);

  const query = useQuery({
    queryKey: ["planning-v2", "available-rooms", { siteId, surgeonId, page, limit }],
    queryFn: () => getAvailableRoomsForManager({
      siteId: siteId === "" ? undefined : siteId,
      surgeonId: surgeonId === "" ? undefined : surgeonId,
      page: page + 1,
      limit,
    }),
  });

  const sitesQuery = useQuery({
    queryKey: ["planning-v2", "absence-communication-settings"],
    queryFn: () => getAbsenceCommunicationSettings(),
  });

  const surgeonsQuery = useQuery({
    queryKey: ["manager-surgeons", "active"],
    queryFn: () => getSurgeons({ active: true }),
  });

  const items = query.data?.items ?? [];

  return (
    <Box>
      <Typography sx={{ fontSize: 14, color: planningV2Colors.textMuted, maxWidth: 560, mb: 2 }}>
        Créneaux opératoires BLOCK réellement libérés par les chirurgiens — visibles ici
        indépendamment des emails « Libération de salle » (envoyés ou non).
      </Typography>

      <Stack direction="row" spacing={1.25} flexWrap="wrap" useFlexGap sx={{ mb: 2 }}>
        <Select
          size="small" displayEmpty value={siteId} onChange={(e) => setSiteId(e.target.value as number | "")}
          sx={{ minWidth: 160, fontSize: 13.5 }}
        >
          <MenuItem value="">Tous les sites</MenuItem>
          {(sitesQuery.data?.items ?? []).map((s) => (
            <MenuItem key={s.site.id} value={s.site.id}>{s.site.name}</MenuItem>
          ))}
        </Select>
        <Select
          size="small" displayEmpty value={surgeonId} onChange={(e) => setSurgeonId(e.target.value as number | "")}
          sx={{ minWidth: 200, fontSize: 13.5 }}
        >
          <MenuItem value="">Tous les chirurgiens</MenuItem>
          {(surgeonsQuery.data?.items ?? []).map((s: SurgeonListItemDTO) => (
            <MenuItem key={s.id} value={s.id}>{s.displayName}</MenuItem>
          ))}
        </Select>
      </Stack>

      {query.isError && <Alert severity="error" sx={{ mb: 2 }}>Impossible de charger les salles disponibles.</Alert>}

      {query.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
          <CircularProgress size={28} />
        </Box>
      ) : items.length === 0 ? (
        <Alert severity="info">Aucune salle disponible ne correspond à ces filtres.</Alert>
      ) : (
        <>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Date</TableCell>
                <TableCell>Établissement</TableCell>
                <TableCell>Période / horaires</TableCell>
                <TableCell>Libérée par</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell>Publiée le</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {items.map((slot) => (
                <TableRow key={slot.id} hover>
                  <TableCell>{new Date(`${slot.occurrenceDate}T00:00:00`).toLocaleDateString("fr-BE")}</TableCell>
                  <TableCell>{slot.site?.name ?? "—"}</TableCell>
                  <TableCell>
                    {slot.startTime && slot.endTime ? `${slot.startTime}–${slot.endTime}` : (PERIOD_LABELS[slot.period] ?? slot.period)}
                  </TableCell>
                  <TableCell>{slot.surgeon?.name ?? "—"}</TableCell>
                  <TableCell>Disponible</TableCell>
                  <TableCell>{new Date(slot.createdAt).toLocaleString("fr-BE")}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <TablePagination
            component="div"
            count={query.data?.total ?? 0}
            page={page}
            onPageChange={(_, p) => setPage(p)}
            rowsPerPage={limit}
            onRowsPerPageChange={(e) => { setLimit(parseInt(e.target.value, 10)); setPage(0); }}
            rowsPerPageOptions={[10, 25, 50]}
          />
        </>
      )}
    </Box>
  );
}
