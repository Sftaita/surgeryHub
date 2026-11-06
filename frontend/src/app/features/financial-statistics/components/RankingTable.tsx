import * as React from "react";
import {
  Box,
  Button,
  CircularProgress,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableRow,
  TableSortLabel,
  Typography,
} from "@mui/material";

export interface RankingColumn<T> {
  key: string;
  label: string;
  align?: "right" | "left";
  render: (row: T) => React.ReactNode;
  /** Champ `sortBy` de l'API — la colonne devient triable en cliquant son en-tête. */
  sortKey?: string;
  /** D-186 — cellule de la ligne TOTAL GÉNÉRAL (totaux serveur d'une devise). */
  renderTotal?: (totals: { currency: string } & Record<string, string>) => React.ReactNode;
  /** D-186 — colonne secondaire masquée sous ce point de rupture (écrans étroits). */
  hideBelow?: "md" | "lg" | "xl";
  /** Infobulle d'en-tête (définition de l'indicateur). */
  hint?: string;
}

function cellSx<T>(c: RankingColumn<T>, index: number) {
  return {
    ...(c.hideBelow ? { display: { xs: "none", [c.hideBelow]: "table-cell" } } : {}),
    // Première colonne figée : le libellé reste lisible pendant le défilement horizontal.
    ...(index === 0 ? { position: "sticky", left: 0, zIndex: 1, bgcolor: "inherit", minWidth: 140 } : {}),
  };
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
  onPageChange: (page: number) => void;
  onSortChange: (sortBy: string, sortDirection: "ASC" | "DESC") => void;
  /** Totaux par devise sur tout l'ensemble filtré (indépendants de la page). */
  totals?: Array<{ currency: string } & Record<string, string>>;
  actions?: React.ReactNode;
  /** Largeur maximale du tableau (centré) — pour les tableaux à peu de colonnes. */
  maxWidth?: number;
  emptyLabel?: string;
}

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — table générique réutilisée par les 5
 * endpoints de classement (by-firm/by-instrumentist/by-surgeon/by-intervention/
 * top-materials), qui partagent tous la même forme de réponse paginée
 * { items, total, page, limit }. D-186 — tri par en-tête, ligne TOTAL GÉNÉRAL issue des
 * totaux serveur (`totals`, une ligne par devise), montants alignés à droite.
 */
export default function RankingTable<T>({
  columns, rows, total, page, limit, isLoading, sortBy, sortDirection, onPageChange, onSortChange, totals, actions, maxWidth, emptyLabel,
}: Props<T>) {
  const totalPages = Math.max(1, Math.ceil(total / limit));
  const showTotals = !isLoading && rows.length > 0 && (totals?.length ?? 0) > 0 && columns.some((c) => c.renderTotal);

  function toggleSort(key: string) {
    if (key === sortBy) onSortChange(key, sortDirection === "DESC" ? "ASC" : "DESC");
    else onSortChange(key, "DESC");
  }

  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden", width: "100%", maxWidth, mx: "auto" }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ px: 2, py: 1.25, bgcolor: "grey.50" }} flexWrap="wrap" useFlexGap spacing={1}>
        <Typography variant="subtitle2" fontWeight={700}>{total} résultat(s)</Typography>
        {actions}
      </Stack>

      {isLoading ? (
        <Box sx={{ p: 4, textAlign: "center" }}><CircularProgress size={24} /></Box>
      ) : (
        <Box sx={{ overflowX: "auto" }}>
          <Table size="small" sx={{ "& td, & th": { px: 1.25, py: 1 }, "& td": { fontVariantNumeric: "tabular-nums" }, "& tr": { bgcolor: "background.paper" } }}>
            <TableHead>
              <TableRow>
                {columns.map((c, i) => (
                  <TableCell key={c.key} align={c.align ?? "left"} title={c.hint} sx={{ fontWeight: 700, lineHeight: 1.25, verticalAlign: "bottom", ...cellSx(c, i) }} sortDirection={c.sortKey === sortBy ? (sortDirection === "ASC" ? "asc" : "desc") : false}>
                    {c.sortKey ? (
                      <TableSortLabel
                        active={c.sortKey === sortBy}
                        direction={c.sortKey === sortBy ? (sortDirection === "ASC" ? "asc" : "desc") : "desc"}
                        onClick={() => toggleSort(c.sortKey!)}
                      >
                        {c.label}
                      </TableSortLabel>
                    ) : c.label}
                  </TableCell>
                ))}
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.length === 0 && (
                <TableRow><TableCell colSpan={columns.length} align="center" sx={{ py: 4, color: "text.secondary" }}>{emptyLabel ?? "Aucune donnée"}</TableCell></TableRow>
              )}
              {rows.map((row, idx) => (
                <TableRow key={idx}>
                  {columns.map((c, i) => <TableCell key={c.key} align={c.align ?? "left"} sx={cellSx(c, i)}>{c.render(row)}</TableCell>)}
                </TableRow>
              ))}
            </TableBody>
            {showTotals && (
              <TableFooter>
                {totals!.map((t) => (
                  <TableRow key={t.currency} sx={{ "&&": { bgcolor: "grey.50" }, "& td": { fontWeight: 700, color: "text.primary", fontSize: "0.875rem", borderTop: 2, borderTopColor: "divider" } }}>
                    {columns.map((c, i) => (
                      <TableCell key={c.key} align={c.align ?? "left"} sx={cellSx(c, i)}>
                        {i === 0 ? `TOTAL GÉNÉRAL${totals!.length > 1 ? ` (${t.currency})` : ""}` : c.renderTotal?.(t) ?? null}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableFooter>
            )}
          </Table>
        </Box>
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
