import * as React from "react";
import {
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  MenuItem,
  Paper,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
  Box,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import {
  getStatements,
  markStatementPaid,
  getStatementPdfUrl,
  type InstrumentistStatement,
} from "../../../features/billing-instrumentist/api/statement.api";
import EligibleLinesStatementWizard from "../../../features/billing-instrumentist/components/EligibleLinesStatementWizard";
import type { InvoiceStatus } from "../../../features/billing-firm/api/firmInvoice.api";
import { useToast } from "../../../ui/toast/useToast";

const STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success" | "error"> = {
  DRAFT: "default", GENERATED: "info", SENT: "warning", PAID: "success", CANCELLED: "error",
};

function statusLabel(s: InvoiceStatus) {
  return { DRAFT: "Brouillon", GENERATED: "Généré", SENT: "Envoyé", PAID: "Payé", CANCELLED: "Annulé" }[s];
}

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

export default function InstrumentistStatementsPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();

  const [tutorialOpen, setTutorialOpen] = React.useState(false);
  const [showWizard, setShowWizard] = React.useState(false);

  const [filterStatus, setFilterStatus] = React.useState<InvoiceStatus | "">("");
  const [filterYear, setFilterYear] = React.useState<number | "">(new Date().getFullYear());

  const statementsQuery = useQuery({
    queryKey: ["instrumentist-statements", filterStatus, filterYear],
    queryFn: () => getStatements({ status: filterStatus || undefined, year: filterYear || undefined }),
  });

  const markPaidMutation = useMutation({
    mutationFn: markStatementPaid,
    onSuccess: () => { toast.success("Décompte marqué payé"); qc.invalidateQueries({ queryKey: ["instrumentist-statements"] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Stack direction="row" alignItems="center" spacing={1}>
          <Typography variant="h6" fontWeight={700}>Décomptes Instrumentistes</Typography>
          <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
            <HelpOutlineIcon fontSize="small" />
          </IconButton>
        </Stack>
        <Button variant="contained" disableElevation startIcon={<AddIcon />} onClick={() => setShowWizard(true)}>
          Nouveau décompte
        </Button>
      </Stack>

      {/* ── Wizard ── */}
      {showWizard && (
        <Paper variant="outlined" sx={{ p: 3, borderRadius: 2 }}>
          <Typography variant="subtitle1" fontWeight={700} mb={1}>Générer un décompte</Typography>

          <EligibleLinesStatementWizard
            onCreated={(statement) => { setShowWizard(false); navigate(`/app/m/billing/statements/${statement.id}`); }}
            onCancel={() => setShowWizard(false)}
          />
        </Paper>
      )}

      {/* ── Filters ── */}
      <Stack direction="row" spacing={2}>
        <Select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value as InvoiceStatus | "")} displayEmpty size="small" sx={{ minWidth: 150 }}>
          <MenuItem value="">Tous les statuts</MenuItem>
          {(["GENERATED", "SENT", "PAID"] as InvoiceStatus[]).map((s) => <MenuItem key={s} value={s}>{statusLabel(s)}</MenuItem>)}
        </Select>
        <TextField type="number" label="Année" value={filterYear} onChange={(e) => setFilterYear(e.target.value ? Number(e.target.value) : "")} size="small" sx={{ width: 100 }} />
      </Stack>

      {/* ── List ── */}
      {statementsQuery.isLoading ? (
        <CircularProgress size={24} />
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell>Instrumentiste</TableCell>
                <TableCell>Période</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right">Montant</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(statementsQuery.data ?? []).length === 0 && (
                <TableRow>
                  <TableCell colSpan={5} align="center" sx={{ py: 4, color: "text.secondary" }}>Aucun décompte</TableCell>
                </TableRow>
              )}
              {(statementsQuery.data ?? []).map((stmt: InstrumentistStatement) => (
                <TableRow key={stmt.id} hover>
                  <TableCell>{stmt.instrumentist.displayName ?? stmt.instrumentist.email}</TableCell>
                  <TableCell>{String(stmt.periodMonth).padStart(2, "0")}/{stmt.periodYear}</TableCell>
                  <TableCell>
                    <Chip label={statusLabel(stmt.status)} size="small" color={STATUS_COLORS[stmt.status]} />
                  </TableCell>
                  <TableCell align="right"><strong>{Number(stmt.totalAmount).toFixed(2)} €</strong></TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Button size="small" variant="outlined" startIcon={<PictureAsPdfIcon />} href={getStatementPdfUrl(stmt.id)} target="_blank">PDF</Button>
                      <Button size="small" onClick={() => navigate(`/app/m/billing/statements/${stmt.id}`)}>Détail</Button>
                      {stmt.status !== "PAID" && (
                        <Button size="small" color="success" onClick={() => markPaidMutation.mutate(stmt.id)} disabled={markPaidMutation.isPending}>Payé</Button>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}

      {/* Tutorial modal */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 700 }}>Comment générer un décompte instrumentiste ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "Lancez le wizard", desc: "Cliquez sur \"+ Nouveau décompte\", sélectionnez l'instrumentiste et le mois concerné." },
              { n: 2, title: "Prévisualisez les prestations", desc: "SurgicalHub liste toutes les missions validées du mois. Les blocs sont facturés en heures (durée arrondie au quart d'heure supérieur × tarif horaire). Les consultations sont facturées à l'unité × tarif consultation." },
              { n: 3, title: "Sélectionnez les missions", desc: "Les missions déjà facturées sont grisées. Décochez manuellement celles que vous souhaitez exclure du décompte." },
              { n: 4, title: "Générez le décompte", desc: "Cliquez sur \"Générer le décompte\". Un PDF est automatiquement produit avec le récapitulatif des prestations et le total." },
              { n: 5, title: "Envoyez à l'instrumentiste", desc: "Depuis le détail du décompte, envoyez le PDF par email à l'instrumentiste une fois la période clôturée." },
            ].map(({ n, title, desc }) => (
              <Stack key={n} direction="row" spacing={2} alignItems="flex-start">
                <Box sx={{
                  minWidth: 32, height: 32, borderRadius: "50%",
                  bgcolor: "primary.main", color: "white",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontWeight: 700, fontSize: 14, flexShrink: 0,
                }}>
                  {n}
                </Box>
                <Box>
                  <Typography variant="subtitle2" fontWeight={700}>{title}</Typography>
                  <Typography variant="body2" color="text.secondary">{desc}</Typography>
                </Box>
              </Stack>
            ))}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTutorialOpen(false)} variant="contained" disableElevation>J'ai compris</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
