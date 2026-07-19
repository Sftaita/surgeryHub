import {
  Box,
  Button,
  Chip,
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
import type { DrilldownItem } from "../api/financialStatistics.api";

interface Props {
  rows: DrilldownItem[];
  total: number;
  page: number;
  limit: number;
  isLoading: boolean;
  onPageChange: (page: number) => void;
}

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §18 du lot : forme minimale partagée par
 * missions/calculations/documents (id/date/beneficiary/currency/amount/status/
 * sourceType/sourceId). Le manager passe d'un chiffre agrégé à sa liste source.
 */
export default function DrilldownTable({ rows, total, page, limit, isLoading, onPageChange }: Props) {
  const totalPages = Math.max(1, Math.ceil(total / limit));

  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
      <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
        <Typography variant="subtitle2" fontWeight={700}>{total} résultat(s)</Typography>
      </Box>

      {isLoading ? (
        <Box sx={{ p: 4, textAlign: "center" }}><CircularProgress size={24} /></Box>
      ) : (
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Date</TableCell>
              <TableCell>Bénéficiaire</TableCell>
              <TableCell>Type</TableCell>
              <TableCell>Statut</TableCell>
              <TableCell align="right">Montant</TableCell>
              <TableCell align="right">ID source</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.length === 0 && (
              <TableRow><TableCell colSpan={6} align="center" sx={{ py: 4, color: "text.secondary" }}>Aucune donnée</TableCell></TableRow>
            )}
            {rows.map((row) => (
              <TableRow key={`${row.sourceType}-${row.sourceId}`} hover>
                <TableCell>{new Date(row.date).toLocaleString("fr-BE")}</TableCell>
                <TableCell>{row.beneficiary}</TableCell>
                <TableCell><Chip size="small" label={row.sourceType} variant="outlined" /></TableCell>
                <TableCell>{row.status}</TableCell>
                <TableCell align="right">{row.amount !== null ? `${Number(row.amount).toFixed(2)} ${row.currency ?? ""}` : "—"}</TableCell>
                <TableCell align="right">#{row.sourceId}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

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
