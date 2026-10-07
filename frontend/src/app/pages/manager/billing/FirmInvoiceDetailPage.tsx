import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import SendIcon from "@mui/icons-material/Send";
import { Link as RouterLink, useParams, useNavigate, useSearchParams } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getFirmInvoice,
  sendFirmInvoice,
  markFirmInvoicePaid,
  getFirmInvoicePdfUrl,
  type InvoiceStatus,
} from "../../../features/billing-firm/api/firmInvoice.api";
import { useToast } from "../../../ui/toast/useToast";
import { extractBillingError } from "../../../features/billing-firm/api/firmBillingWorklist.api";
import DocumentFinancePanel from "../../../features/billing-shared/components/DocumentFinancePanel";

const STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success" | "error"> = {
  DRAFT: "default", GENERATED: "info", SENT: "warning", PAID: "success", CANCELLED: "error",
};

function statusLabel(s: InvoiceStatus) {
  return { DRAFT: "Brouillon", GENERATED: "Générée", SENT: "Envoyée", PAID: "Payée", CANCELLED: "Annulée" }[s];
}

// Message métier du backend tel quel (409 de transition, 422 de sélection…).
const extractError = extractBillingError;

function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const [y, m, d] = iso.slice(0, 10).split("-");
  return `${d}/${m}/${y}`;
}

export default function FirmInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  // D-134 — deep-link depuis la worklist : ?focusLine=MATERIAL:456 (survit au refresh).
  const [searchParams] = useSearchParams();
  const focusLine = searchParams.get("focusLine");
  const [highlighted, setHighlighted] = React.useState<string | null>(null);
  const lineRefs = React.useRef<Record<string, HTMLTableRowElement | null>>({});
  const toast = useToast();
  const qc = useQueryClient();

  const [emailTo, setEmailTo] = React.useState("");
  const [emailCc, setEmailCc] = React.useState("");

  const invoiceQuery = useQuery({
    queryKey: ["firm-invoice", Number(id)],
    queryFn: () => getFirmInvoice(Number(id)),
    enabled: !!id,
  });

  const focusedLineFound = !!focusLine && (invoiceQuery.data?.lines ?? []).some((l) => l.sourceKey === focusLine);
  React.useEffect(() => {
    if (!focusLine || !focusedLineFound) return;
    lineRefs.current[focusLine]?.scrollIntoView?.({ block: "center", behavior: "smooth" });
    setHighlighted(focusLine);
    const t = window.setTimeout(() => setHighlighted(null), 4000);
    return () => window.clearTimeout(t);
  }, [focusLine, focusedLineFound]);

  React.useEffect(() => {
    if (invoiceQuery.data) {
      setEmailTo(invoiceQuery.data.billingEmailTo ?? "");
      setEmailCc((invoiceQuery.data.billingEmailCc ?? []).join(", "));
    }
  }, [invoiceQuery.data]);

  const sendMutation = useMutation({
    mutationFn: () =>
      sendFirmInvoice(Number(id), {
        emailTo,
        emailCc: emailCc.split(",").map((e) => e.trim()).filter(Boolean),
      }),
    onSuccess: () => {
      toast.success("Facture envoyée");
      qc.invalidateQueries({ queryKey: ["firm-invoice", Number(id)] });
      qc.invalidateQueries({ queryKey: ["firm-invoices"] });
      qc.invalidateQueries({ queryKey: ["firm-billing-cockpit"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const markPaidMutation = useMutation({
    mutationFn: () => markFirmInvoicePaid(Number(id)),
    onSuccess: () => {
      toast.success("Facture marquée payée");
      qc.invalidateQueries({ queryKey: ["firm-invoice", Number(id)] });
      qc.invalidateQueries({ queryKey: ["firm-invoices"] });
      qc.invalidateQueries({ queryKey: ["firm-billing-cockpit"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  if (invoiceQuery.isLoading) return <CircularProgress />;
  if (!invoiceQuery.data) return <Typography>Facture introuvable</Typography>;

  const inv = invoiceQuery.data;
  const total = Number(inv.totalAmount);
  const allowed = inv.allowedActions ?? [];

  return (
    <Stack spacing={3}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate("/app/m/billing/firm-invoices")} size="small">
          Retour
        </Button>
        <Typography variant="h6" fontWeight={700} sx={{ flex: 1 }}>
          Facture {inv.number ?? `F-${inv.id}`}
        </Typography>
        <Chip label={statusLabel(inv.status)} color={STATUS_COLORS[inv.status]} />
      </Stack>

      {/* Meta */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" spacing={4} flexWrap="wrap">
          <Box>
            <Typography variant="caption" color="text.secondary">Firme</Typography>
            <Typography fontWeight={600}>{inv.firm.name}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Période</Typography>
            <Typography fontWeight={600}>{formatDate(inv.periodStart)} → {formatDate(inv.periodEnd)}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Total HTVA</Typography>
            <Typography variant="h5" fontWeight={700} color="primary">{total.toFixed(2)} €</Typography>
          </Box>
          {inv.generatedAt && (
            <Box>
              <Typography variant="caption" color="text.secondary">Générée le</Typography>
              <Typography>{new Date(inv.generatedAt).toLocaleDateString("fr-BE")}</Typography>
            </Box>
          )}
          {inv.sentAt && (
            <Box>
              <Typography variant="caption" color="text.secondary">Envoyée le</Typography>
              <Typography>{new Date(inv.sentAt).toLocaleDateString("fr-BE")}</Typography>
            </Box>
          )}
          {inv.paidAt && (
            <Box>
              <Typography variant="caption" color="text.secondary">Payée le</Typography>
              <Typography>{new Date(inv.paidAt).toLocaleDateString("fr-BE")}</Typography>
            </Box>
          )}
        </Stack>
      </Paper>

      {/* Lines */}
      {focusLine && invoiceQuery.data && !focusedLineFound && (
        <Alert severity="info">La ligne recherchée ne figure plus sur cette facture — consultez son historique depuis la facturation firmes.</Alert>
      )}

      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
        <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
          <Typography variant="subtitle2" fontWeight={700}>Lignes facturées ({inv.lines?.length ?? 0})</Typography>
          <Typography variant="caption" color="text.secondary">
            Snapshot figé à la génération : quantités, prix et montants ne changent plus, même si un tarif est modifié ensuite.
          </Typography>
        </Box>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Date</TableCell>
              <TableCell>Site · chirurgien</TableCell>
              <TableCell>Prestation</TableCell>
              <TableCell>Type</TableCell>
              <TableCell align="right">Qté</TableCell>
              <TableCell align="right">P.U.</TableCell>
              <TableCell align="right">Total</TableCell>
              <TableCell>Source</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {(inv.lines ?? []).map((line) => (
              <TableRow
                key={line.id}
                ref={(el) => { if (line.sourceKey) lineRefs.current[line.sourceKey] = el; }}
                data-testid={line.sourceKey ? `invoice-line-${line.sourceKey}` : undefined}
                aria-current={line.sourceKey === focusLine ? "true" : undefined}
                sx={{
                  transition: "background-color 600ms ease",
                  bgcolor: highlighted !== null && line.sourceKey === highlighted ? "warning.light" : undefined,
                  outline: line.sourceKey === focusLine ? "2px solid" : undefined,
                  outlineColor: "warning.main",
                }}
              >
                <TableCell>
                  {formatDate(line.missionDate)}
                  {line.sourceKey === focusLine && <Chip size="small" color="warning" label="Ligne recherchée" sx={{ ml: 1 }} />}
                </TableCell>
                <TableCell>{[line.siteName, line.surgeonName].filter(Boolean).join(" · ") || "—"}</TableCell>
                <TableCell>
                  {line.materialLabel
                    ? [line.materialLabel, line.materialReferenceCode].filter(Boolean).join(" · ")
                    : line.interventionLabel ?? line.descriptionSnapshot}
                  {(line.materialLabel || line.interventionLabel) && (
                    <Typography variant="caption" color="text.secondary" display="block">{line.descriptionSnapshot}</Typography>
                  )}
                </TableCell>
                <TableCell>
                  <Chip
                    label={line.lineType === "INTERVENTION_FEE" ? "Intervention" : "Matériel"}
                    size="small"
                    color={line.lineType === "INTERVENTION_FEE" ? "primary" : "secondary"}
                    variant="outlined"
                  />
                </TableCell>
                <TableCell align="right">{line.quantity}</TableCell>
                <TableCell align="right">{Number(line.unitPrice).toFixed(2)} €</TableCell>
                <TableCell align="right"><strong>{Number(line.totalAmount).toFixed(2)} €</strong></TableCell>
                <TableCell>
                  <Button component={RouterLink} to={`/app/m/missions/${line.missionId}`} size="small">Mission #{line.missionId}</Button>
                  {line.financialCalculationLineId != null && (
                    <Typography variant="caption" color="text.secondary" display="block">
                      Ligne financière #{line.financialCalculationLineId}{line.financialCalculationId != null ? ` · calcul #${line.financialCalculationId}` : ""}
                    </Typography>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>

      {/* Solde, paiements, remboursements, notes de crédit/débit (EPIC Exécution & Valorisation, Lots 4-6) */}
      <DocumentFinancePanel
        resource="firm-invoices"
        document={inv}
        lines={(inv.lines ?? []).map((l) => ({ id: l.id, descriptionSnapshot: l.descriptionSnapshot, totalAmount: l.totalAmount }))}
        correctionsBasePath="/app/m/billing/firm-invoice-corrections"
        onChanged={() => qc.invalidateQueries({ queryKey: ["firm-invoice", Number(id)] })}
      />

      {/* Actions */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack spacing={2}>
          <Typography variant="subtitle2" fontWeight={700}>Actions</Typography>

          <Stack direction="row" spacing={1}>
            <Button
              variant="outlined"
              startIcon={<PictureAsPdfIcon />}
              href={getFirmInvoicePdfUrl(inv.id)}
              target="_blank"
            >
              Télécharger PDF
            </Button>
            {allowed.includes("markPaid") && (
              <Button
                variant="outlined"
                color="success"
                onClick={() => markPaidMutation.mutate()}
                disabled={markPaidMutation.isPending}
              >
                Marquer comme payée
              </Button>
            )}
          </Stack>

          {allowed.includes("send") && (
            <>
              <Divider />
              <Typography variant="subtitle2">Envoyer par email</Typography>
              <Stack spacing={1.5}>
                <TextField
                  label="À (email principal)"
                  value={emailTo}
                  onChange={(e) => setEmailTo(e.target.value)}
                  size="small"
                  fullWidth
                />
                <TextField
                  label="CC (séparés par virgule)"
                  value={emailCc}
                  onChange={(e) => setEmailCc(e.target.value)}
                  size="small"
                  fullWidth
                  placeholder="cc1@example.com, cc2@example.com"
                />
                <Button
                  variant="contained"
                  disableElevation
                  startIcon={<SendIcon />}
                  onClick={() => sendMutation.mutate()}
                  disabled={!emailTo || sendMutation.isPending}
                  sx={{ alignSelf: "flex-start" }}
                >
                  {sendMutation.isPending ? <CircularProgress size={16} /> : "Envoyer"}
                </Button>
              </Stack>
            </>
          )}
        </Stack>
      </Paper>
    </Stack>
  );
}
