import {
  Box,
  Button,
  CircularProgress,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import { useNavigate } from "react-router-dom";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";
import { EncodingStateChip, FinancialStateChip } from "./EncodingStateChip";
import { HoursCell } from "./HoursCell";
import { MISSION_TYPE_LABEL } from "../encodingStateMeta";
import { EmptyState } from "../../../ui/EmptyState";

interface Props {
  items: EncodingTrackingItem[];
  total: number;
  page: number;
  limit: number;
  isLoading: boolean;
  isError: boolean;
  onPageChange: (page: number) => void;
  emptyTitle: string;
  emptyDescription?: string;
}

function formatTime(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleTimeString("fr-BE", { hour: "2-digit", minute: "2-digit" });
}

/**
 * Suivi des encodages (D-118) — table principale. Une ligne = un item déjà entièrement
 * résolu par le backend ; ce composant n'affiche que ce qui est fourni, ne recalcule
 * rien. "Ouvrir" réutilise le détail Mission existant (/app/m/missions/:id) — aucune
 * deuxième UI d'encodage manager n'est créée ici.
 */
export function EncodingTrackingTable({ items, total, page, limit, isLoading, isError, onPageChange, emptyTitle, emptyDescription }: Props) {
  const navigate = useNavigate();
  const totalPages = Math.max(1, Math.ceil(total / limit));

  if (isLoading) {
    return (
      <Box sx={{ p: 4, textAlign: "center" }}>
        <CircularProgress size={24} />
      </Box>
    );
  }

  if (isError) {
    return (
      <EmptyState
        title="Impossible de charger le suivi des encodages"
        description="Une erreur est survenue lors de la récupération des données. Réessayez dans un instant."
      />
    );
  }

  if (items.length === 0) {
    return <EmptyState title={emptyTitle} description={emptyDescription} />;
  }

  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
      <Box sx={{ overflowX: "auto" }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Heure</TableCell>
              <TableCell>Instrumentiste</TableCell>
              <TableCell>Chirurgien</TableCell>
              <TableCell>Site</TableCell>
              <TableCell>Mission</TableCell>
              <TableCell>Encodage</TableCell>
              <TableCell>Heures</TableCell>
              <TableCell>Finance</TableCell>
              <TableCell align="right">Action</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {items.map((item) => (
              <TableRow
                key={item.missionId}
                hover
                sx={{ cursor: "pointer" }}
                onClick={() => navigate(`/app/m/missions/${item.missionId}`)}
              >
                <TableCell>{formatTime(item.startAt)}</TableCell>
                <TableCell>{item.instrumentist?.name ?? "—"}</TableCell>
                <TableCell>{item.surgeon?.name ?? "—"}</TableCell>
                <TableCell>{item.site?.name ?? "—"}</TableCell>
                <TableCell>{item.missionType ? MISSION_TYPE_LABEL[item.missionType] : "—"}</TableCell>
                <TableCell>
                  <Stack direction="row" spacing={0.5} alignItems="center">
                    <EncodingStateChip state={item.encodingState} />
                    {item.encoding.isStale && (
                      <Typography variant="caption" color="warning.main">en retard</Typography>
                    )}
                  </Stack>
                </TableCell>
                <TableCell><HoursCell hours={item.hours} /></TableCell>
                <TableCell><FinancialStateChip state={item.financial.state} /></TableCell>
                <TableCell align="right">
                  <Button
                    size="small"
                    onClick={(e) => {
                      e.stopPropagation();
                      navigate(`/app/m/missions/${item.missionId}`);
                    }}
                  >
                    Ouvrir
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Box>

      {totalPages > 1 && (
        <Stack direction="row" justifyContent="center" alignItems="center" spacing={2} sx={{ p: 1.5 }}>
          <Button size="small" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>Précédent</Button>
          <Typography variant="body2">Page {page} / {totalPages}</Typography>
          <Button size="small" disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>Suivant</Button>
        </Stack>
      )}
    </Paper>
  );
}

export default EncodingTrackingTable;
