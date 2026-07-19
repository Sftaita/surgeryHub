import * as React from "react";
import {
  Box,
  Button,
  CircularProgress,
  MenuItem,
  Paper,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";

export interface RankingColumn<T> {
  key: string;
  label: string;
  align?: "right" | "left";
  render: (row: T) => React.ReactNode;
}

interface Props<T> {
  columns: RankingColumn<T>[];
  rows: T[];
  total: number;
  page: number;
  limit: number;
  isLoading: boolean;
  sortBy: string;
  sortDirection: "ASC" | "DESC";
  sortOptions: { value: string; label: string }[];
  onPageChange: (page: number) => void;
  onSortChange: (sortBy: string, sortDirection: "ASC" | "DESC") => void;
  emptyLabel?: string;
}

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — table générique réutilisée par les 5
 * endpoints de classement (by-firm/by-instrumentist/by-surgeon/by-intervention/
 * top-materials), qui partagent tous la même forme de réponse paginée
 * { items, total, page, limit }.
 */
export default function RankingTable<T>({
  columns, rows, total, page, limit, isLoading, sortBy, sortDirection, sortOptions, onPageChange, onSortChange, emptyLabel,
}: Props<T>) {
  const totalPages = Math.max(1, Math.ceil(total / limit));

  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }} flexWrap="wrap" useFlexGap>
        <Typography variant="subtitle2" fontWeight={700}>{total} résultat(s)</Typography>
        <Stack direction="row" spacing={1} alignItems="center">
          <Typography variant="caption" color="text.secondary">Trier par</Typography>
          <Select size="small" value={sortBy} onChange={(e) => onSortChange(e.target.value, sortDirection)} sx={{ minWidth: 160 }}>
            {sortOptions.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
          </Select>
          <Select size="small" value={sortDirection} onChange={(e) => onSortChange(sortBy, e.target.value as "ASC" | "DESC")} sx={{ width: 90 }}>
            <MenuItem value="DESC">↓ Desc</MenuItem>
            <MenuItem value="ASC">↑ Asc</MenuItem>
          </Select>
        </Stack>
      </Stack>

      {isLoading ? (
        <Box sx={{ p: 4, textAlign: "center" }}><CircularProgress size={24} /></Box>
      ) : (
        <Table size="small">
          <TableHead>
            <TableRow>
              {columns.map((c) => <TableCell key={c.key} align={c.align ?? "left"}>{c.label}</TableCell>)}
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.length === 0 && (
              <TableRow><TableCell colSpan={columns.length} align="center" sx={{ py: 4, color: "text.secondary" }}>{emptyLabel ?? "Aucune donnée"}</TableCell></TableRow>
            )}
            {rows.map((row, idx) => (
              <TableRow key={idx} hover>
                {columns.map((c) => <TableCell key={c.key} align={c.align ?? "left"}>{c.render(row)}</TableCell>)}
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
