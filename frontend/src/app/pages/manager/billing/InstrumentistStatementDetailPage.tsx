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
  getStatement,
  sendStatement,
  markStatementPaid,
  getStatementPdfUrl,
} from "../../../features/billing-instrumentist/api/statement.api";
import type { InvoiceStatus } from "../../../features/billing-firm/api/firmInvoice.api";
import { useToast } from "../../../ui/toast/useToast";

const STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success"> = {
  DRAFT: "default", GENERATED: "info", SENT: "warning", PAID: "success",
};
function statusLabel(s: InvoiceStatus) {
  return { DRAFT: "Brouillon", GENERATED: "Généré", SENT: "Envoyé", PAID: "Payé" }[s];
}
function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

export default function InstrumentistStatementDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();

  const [emailTo, setEmailTo] = React.useState("");

  const stmtQuery = useQuery({
    queryKey: ["instrumentist-statement", Number(id)],
    queryFn: () => getStatement(Number(id)),
    enabled: !!id,
  });

  React.useEffect(() => {
    if (stmtQuery.data) {
      setEmailTo(stmtQuery.data.instrumentist.email ?? "");
    }
  }, [stmtQuery.data]);

  const sendMutation = useMutation({
    mutationFn: () => sendStatement(Number(id), { emailTo }),
    onSuccess: () => {
      toast.success("Décompte envoyé");
      qc.invalidateQueries({ queryKey: ["instrumentist-statement", Number(id)] });
      qc.invalidateQueries({ queryKey: ["instrumentist-statements"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const markPaidMutation = useMutation({
    mutationFn: () => markStatementPaid(Number(id)),
    onSuccess: () => {
      toast.success("Décompte marqué payé");
      qc.invalidateQueries({ queryKey: ["instrumentist-statement", Number(id)] });
      qc.invalidateQueries({ queryKey: ["instrumentist-statements"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  if (stmtQuery.isLoading) return <CircularProgress />;
  if (!stmtQuery.data) return <Typography>Décompte introuvable</Typography>;

  const stmt = stmtQuery.data;

  return (
    <Stack spacing={3}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate("/app/m/billing/statements")} size="small">
          Retour
        </Button>
        <Typography variant="h6" fontWeight={700} sx={{ flex: 1 }}>
          Décompte {String(stmt.periodMonth).padStart(2, "0")}/{stmt.periodYear} — {stmt.instrumentist.displayName}
        </Typography>
        <Chip label={statusLabel(stmt.status)} color={STATUS_COLORS[stmt.status]} />
      </Stack>

      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" spacing={4} flexWrap="wrap">
          <Box>
            <Typography variant="caption" color="text.secondary">Instrumentiste</Typography>
            <Typography fontWeight={600}>{stmt.instrumentist.displayName ?? stmt.instrumentist.email}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Période</Typography>
            <Typography fontWeight={600}>{String(stmt.periodMonth).padStart(2, "0")}/{stmt.periodYear}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Total</Typography>
            <Typography variant="h5" fontWeight={700} color="success.main">{Number(stmt.totalAmount).toFixed(2)} €</Typography>
          </Box>
          {stmt.sentAt && (
            <Box>
              <Typography variant="caption" color="text.secondary">Envoyé le</Typography>
              <Typography>{new Date(stmt.sentAt).toLocaleDateString("fr-BE")}</Typography>
            </Box>
          )}
          {stmt.paidAt && (
            <Box>
              <Typography variant="caption" color="text.secondary">Payé le</Typography>
              <Typography>{new Date(stmt.paidAt).toLocaleDateString("fr-BE")}</Typography>
            </Box>
          )}
        </Stack>
      </Paper>

      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
        <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
          <Typography variant="subtitle2" fontWeight={700}>Prestations ({stmt.lines?.length ?? 0})</Typography>
        </Box>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Date</TableCell>
              <TableCell>Type</TableCell>
              <TableCell>Chirurgien</TableCell>
              <TableCell>Site</TableCell>
              <TableCell align="right">Durée/Qté</TableCell>
              <TableCell align="right">Tarif</TableCell>
              <TableCell align="right">Total</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {(stmt.lines ?? []).map((line) => (
              <TableRow key={line.id}>
                <TableCell>{line.missionDate ?? "—"}</TableCell>
                <TableCell>
                  <Chip
                    label={line.lineType === "BLOC" ? "Bloc" : "Consultation"}
                    size="small"
                    color={line.lineType === "BLOC" ? "primary" : "secondary"}
                    variant="outlined"
                  />
                </TableCell>
                <TableCell>{line.surgeonName ?? "—"}</TableCell>
                <TableCell>{line.siteName ?? "—"}</TableCell>
                <TableCell align="right">
                  {line.lineType === "BLOC"
                    ? `${line.durationMinutesRounded}min → ${Number(line.quantity).toFixed(2)}h`
                    : "1 consultation"}
                </TableCell>
                <TableCell align="right">{Number(line.rateSnapshot).toFixed(2)} €</TableCell>
                <TableCell align="right"><strong>{Number(line.totalAmount).toFixed(2)} €</strong></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>

      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack spacing={2}>
          <Typography variant="subtitle2" fontWeight={700}>Actions</Typography>
          <Stack direction="row" spacing={1}>
            <Button variant="outlined" startIcon={<PictureAsPdfIcon />} href={getStatementPdfUrl(stmt.id)} target="_blank">
              Télécharger PDF
            </Button>
            {stmt.status !== "PAID" && (
              <Button variant="outlined" color="success" onClick={() => markPaidMutation.mutate()} disabled={markPaidMutation.isPending}>
                Marquer payé
              </Button>
            )}
          </Stack>

          {stmt.status === "GENERATED" && (
            <>
              <Divider />
              <Typography variant="subtitle2">Envoyer par email</Typography>
              <Stack spacing={1.5}>
                <TextField
                  label="À (email instrumentiste)"
                  value={emailTo}
                  onChange={(e) => setEmailTo(e.target.value)}
                  size="small"
                  sx={{ maxWidth: 400 }}
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
