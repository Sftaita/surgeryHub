import * as React from "react";
import {
  Box,
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
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import {
  getFirmInvoices,
  markFirmInvoicePaid,
  getFirmInvoicePdfUrl,
  type FirmInvoice,
  type InvoiceStatus,
} from "../../../features/billing-firm/api/firmInvoice.api";
import EligibleLinesInvoiceWizard from "../../../features/billing-firm/components/EligibleLinesInvoiceWizard";
import { useToast } from "../../../ui/toast/useToast";

const STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success" | "error"> = {
  DRAFT: "default",
  GENERATED: "info",
  SENT: "warning",
  PAID: "success",
  CANCELLED: "error",
};

function statusLabel(s: InvoiceStatus) {
  return { DRAFT: "Brouillon", GENERATED: "Générée", SENT: "Envoyée", PAID: "Payée", CANCELLED: "Annulée" }[s];
}

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

export default function FirmInvoicesPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();

  // ── Wizard state ──────────────────────────────────────────────────
  const [tutorialOpen, setTutorialOpen] = React.useState(false);
  const [showWizard, setShowWizard] = React.useState(false);

  // ── Filters ───────────────────────────────────────────────────────
  const [filterStatus, setFilterStatus] = React.useState<InvoiceStatus | "">("");
  const [filterYear, setFilterYear] = React.useState<number | "">(new Date().getFullYear());

  const invoicesQuery = useQuery({
    queryKey: ["firm-invoices", filterStatus, filterYear],
    queryFn: () =>
      getFirmInvoices({
        status: filterStatus || undefined,
        year: filterYear || undefined,
      }),
  });

  const markPaidMutation = useMutation({
    mutationFn: markFirmInvoicePaid,
    onSuccess: () => { toast.success("Facture marquée payée"); qc.invalidateQueries({ queryKey: ["firm-invoices"] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Stack direction="row" alignItems="center" spacing={1}>
          <Typography variant="h6" fontWeight={700}>Factures Firmes</Typography>
          <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
            <HelpOutlineIcon fontSize="small" />
          </IconButton>
        </Stack>
        <Button variant="contained" disableElevation startIcon={<AddIcon />} onClick={() => setShowWizard(true)}>
          Nouvelle facture
        </Button>
      </Stack>

      {/* ── Wizard ── */}
      {showWizard && (
        <Paper variant="outlined" sx={{ p: 3, borderRadius: 2 }}>
          <Typography variant="subtitle1" fontWeight={700} mb={1}>Générer une facture</Typography>

          <EligibleLinesInvoiceWizard
            onCreated={(invoice) => { setShowWizard(false); navigate(`/app/m/billing/firm-invoices/${invoice.id}`); }}
            onCancel={() => setShowWizard(false)}
          />
        </Paper>
      )}

      {/* ── Filters ── */}
      <Stack direction="row" spacing={2} alignItems="center">
        <Select
          value={filterStatus}
          onChange={(e) => setFilterStatus(e.target.value as InvoiceStatus | "")}
          displayEmpty
          size="small"
          sx={{ minWidth: 150 }}
        >
          <MenuItem value="">Tous les statuts</MenuItem>
          {(["GENERATED", "SENT", "PAID"] as InvoiceStatus[]).map((s) => (
            <MenuItem key={s} value={s}>{statusLabel(s)}</MenuItem>
          ))}
        </Select>
        <TextField
          type="number"
          label="Année"
          value={filterYear}
          onChange={(e) => setFilterYear(e.target.value ? Number(e.target.value) : "")}
          size="small"
          sx={{ width: 100 }}
        />
      </Stack>

      {/* ── List ── */}
      {invoicesQuery.isLoading ? (
        <CircularProgress size={24} />
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell>N°</TableCell>
                <TableCell>Firme</TableCell>
                <TableCell>Période</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right">Montant</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(invoicesQuery.data ?? []).length === 0 && (
                <TableRow>
                  <TableCell colSpan={6} align="center" sx={{ py: 4, color: "text.secondary" }}>
                    Aucune facture
                  </TableCell>
                </TableRow>
              )}
              {(invoicesQuery.data ?? []).map((inv: FirmInvoice) => (
                <TableRow key={inv.id} hover>
                  <TableCell>
                    <Typography variant="body2" fontWeight={600} color="primary">
                      {inv.number ?? `F-${inv.id}`}
                    </Typography>
                  </TableCell>
                  <TableCell>{inv.firm.name}</TableCell>
                  <TableCell>
                    {inv.periodStart} → {inv.periodEnd}
                  </TableCell>
                  <TableCell>
                    <Chip
                      label={statusLabel(inv.status)}
                      size="small"
                      color={STATUS_COLORS[inv.status]}
                    />
                  </TableCell>
                  <TableCell align="right">
                    <strong>{Number(inv.totalAmount).toFixed(2)} €</strong>
                  </TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Button
                        size="small"
                        variant="outlined"
                        startIcon={<PictureAsPdfIcon />}
                        href={getFirmInvoicePdfUrl(inv.id)}
                        target="_blank"
                      >
                        PDF
                      </Button>
                      <Button
                        size="small"
                        onClick={() => navigate(`/app/m/billing/firm-invoices/${inv.id}`)}
                      >
                        Détail
                      </Button>
                      {inv.status !== "PAID" && (
                        <Button
                          size="small"
                          color="success"
                          onClick={() => markPaidMutation.mutate(inv.id)}
                          disabled={markPaidMutation.isPending}
                        >
                          Payée
                        </Button>
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
        <DialogTitle sx={{ fontWeight: 700 }}>Comment générer une facture firme ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "Configurez les règles tarifaires", desc: "Avant tout, rendez-vous dans \"Configuration\" pour définir les tarifs par firme : par code d'intervention (ex : LCA) ou par implant spécifique." },
              { n: 2, title: "Créez une nouvelle facture", desc: "Cliquez sur \"+ Nouvelle facture\", sélectionnez la firme et la période (mois/année)." },
              { n: 3, title: "Prévisualisez les prestations", desc: "SurgicalHub scanne toutes les missions validées sur la période et applique les règles actives. Seules les prestations non encore facturées apparaissent." },
              { n: 4, title: "Sélectionnez et générez", desc: "Cochez les lignes à inclure (interventions et/ou implants), puis cliquez sur \"Générer\". La facture est créée avec un numéro unique (FIRM-YYYY-NNN)." },
              { n: 5, title: "Envoyez par email", desc: "Depuis le détail de la facture, téléchargez le PDF ou envoyez-le directement à la firme avec le bouton d'envoi." },
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
