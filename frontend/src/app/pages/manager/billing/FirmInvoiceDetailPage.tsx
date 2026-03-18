import * as React from "react";
import {
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
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getFirmInvoice,
  sendFirmInvoice,
  markFirmInvoicePaid,
  getFirmInvoicePdfUrl,
  type InvoiceStatus,
} from "../../../features/billing-firm/api/firmInvoice.api";
import { useToast } from "../../../ui/toast/useToast";

const STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success"> = {
  DRAFT: "default", GENERATED: "info", SENT: "warning", PAID: "success",
};

function statusLabel(s: InvoiceStatus) {
  return { DRAFT: "Brouillon", GENERATED: "Générée", SENT: "Envoyée", PAID: "Payée" }[s];
}

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

export default function FirmInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();

  const [emailTo, setEmailTo] = React.useState("");
  const [emailCc, setEmailCc] = React.useState("");

  const invoiceQuery = useQuery({
    queryKey: ["firm-invoice", Number(id)],
    queryFn: () => getFirmInvoice(Number(id)),
    enabled: !!id,
  });

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
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const markPaidMutation = useMutation({
    mutationFn: () => markFirmInvoicePaid(Number(id)),
    onSuccess: () => {
      toast.success("Facture marquée payée");
      qc.invalidateQueries({ queryKey: ["firm-invoice", Number(id)] });
      qc.invalidateQueries({ queryKey: ["firm-invoices"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  if (invoiceQuery.isLoading) return <CircularProgress />;
  if (!invoiceQuery.data) return <Typography>Facture introuvable</Typography>;

  const inv = invoiceQuery.data;
  const total = Number(inv.totalAmount);

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
            <Typography fontWeight={600}>{inv.periodStart} → {inv.periodEnd}</Typography>
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
      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
        <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
          <Typography variant="subtitle2" fontWeight={700}>Lignes ({inv.lines?.length ?? 0})</Typography>
        </Box>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Date</TableCell>
              <TableCell>Description</TableCell>
              <TableCell>Type</TableCell>
              <TableCell align="right">Qté</TableCell>
              <TableCell align="right">P.U.</TableCell>
              <TableCell align="right">Total</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {(inv.lines ?? []).map((line) => (
              <TableRow key={line.id}>
                <TableCell>{line.missionDate}</TableCell>
                <TableCell>{line.descriptionSnapshot}</TableCell>
                <TableCell>
                  <Chip
                    label={line.lineType === "INTERVENTION_FEE" ? "Intervention" : "Implant"}
                    size="small"
                    color={line.lineType === "INTERVENTION_FEE" ? "primary" : "secondary"}
                    variant="outlined"
                  />
                </TableCell>
                <TableCell align="right">{line.quantity}</TableCell>
                <TableCell align="right">{Number(line.unitPrice).toFixed(2)} €</TableCell>
                <TableCell align="right"><strong>{Number(line.totalAmount).toFixed(2)} €</strong></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>

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
            {inv.status !== "PAID" && (
              <Button
                variant="outlined"
                color="success"
                onClick={() => markPaidMutation.mutate()}
                disabled={markPaidMutation.isPending}
              >
                Marquer payée
              </Button>
            )}
          </Stack>

          {inv.status === "GENERATED" && (
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
