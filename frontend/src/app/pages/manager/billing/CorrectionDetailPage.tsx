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
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import CheckCircleIcon from "@mui/icons-material/CheckCircle";
import { useParams, useNavigate } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  CORRECTION_REASON_LABELS,
  getCorrection,
  issueCorrection,
  type DocumentResource,
} from "../../../features/billing-shared/api/documentFinance.api";
import { useToast } from "../../../ui/toast/useToast";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

interface Props {
  resource: DocumentResource;
}

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — détail d'une note de crédit/débit.
 * Une correction n'est jamais adressable sous le préfixe du document racine (elle
 * partage son id avec la table du document) — contrôleur dédié côté backend
 * (/api/{firm-invoice|instrumentist-statement}-corrections/{id}).
 */
export default function CorrectionDetailPage({ resource }: Props) {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();

  const correctionId = Number(id);
  const rootPath = resource === "firm-invoices" ? "/app/m/billing/firm-invoices" : "/app/m/billing/statements";

  const query = useQuery({
    queryKey: [resource, "correction", correctionId],
    queryFn: () => getCorrection(resource, correctionId),
    enabled: !!id,
  });

  const issueMutation = useMutation({
    mutationFn: () => issueCorrection(resource, correctionId),
    onSuccess: () => {
      toast.success("Correction émise");
      qc.invalidateQueries({ queryKey: [resource, "correction", correctionId] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  if (query.isLoading) return <CircularProgress />;
  if (!query.data) return <Typography>Correction introuvable</Typography>;

  const c = query.data;
  const isCredit = c.documentType === "CREDIT_NOTE";
  const beneficiary = c.firm?.name ?? (c.instrumentist ? `Instrumentiste #${c.instrumentist.id}` : "—");

  return (
    <Stack spacing={3}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Button
          startIcon={<ArrowBackIcon />}
          onClick={() => navigate(c.correctsDocument.id ? `${rootPath}/${c.correctsDocument.id}` : rootPath)}
          size="small"
        >
          Retour au document
        </Button>
        <Typography variant="h6" fontWeight={700} sx={{ flex: 1 }}>
          {isCredit ? "Note de crédit" : "Note de débit"} {c.number ?? `#${c.id}`}
        </Typography>
        <Chip label={isCredit ? "Crédit" : "Débit"} color={isCredit ? "error" : "info"} variant="outlined" />
        <Chip label={c.status} color={c.status === "SENT" || c.status === "PAID" ? "success" : "default"} />
      </Stack>

      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" spacing={4} flexWrap="wrap">
          <Box>
            <Typography variant="caption" color="text.secondary">Bénéficiaire</Typography>
            <Typography fontWeight={600}>{beneficiary}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Document d'origine</Typography>
            <Typography fontWeight={600}>{c.correctsDocument.number ?? `#${c.correctsDocument.id}`}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Montant correctif</Typography>
            <Typography variant="h5" fontWeight={700} color={isCredit ? "error.main" : "info.main"}>
              {isCredit ? "-" : "+"}{Number(c.totalAmount).toFixed(2)} {c.currency}
            </Typography>
          </Box>
          {c.sentAt && (
            <Box>
              <Typography variant="caption" color="text.secondary">Émise le</Typography>
              <Typography>{new Date(c.sentAt).toLocaleDateString("fr-BE")}</Typography>
            </Box>
          )}
        </Stack>
      </Paper>

      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
        <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
          <Typography variant="subtitle2" fontWeight={700}>Lignes correctives ({c.lines.length})</Typography>
        </Box>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Description</TableCell>
              <TableCell>Motif</TableCell>
              <TableCell>Ligne d'origine</TableCell>
              <TableCell align="right">Qté</TableCell>
              <TableCell align="right">Montant unitaire</TableCell>
              <TableCell align="right">Total</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {c.lines.map((line) => (
              <TableRow key={line.id}>
                <TableCell>{line.descriptionSnapshot}</TableCell>
                <TableCell>{line.reasonCode ? CORRECTION_REASON_LABELS[line.reasonCode] : "—"}</TableCell>
                <TableCell>{line.originalDocumentLineId ? `#${line.originalDocumentLineId}` : "Ligne oubliée"}</TableCell>
                <TableCell align="right">{line.quantity}</TableCell>
                <TableCell align="right">{Number(line.unitPrice ?? line.rateSnapshot ?? 0).toFixed(2)} {line.currency}</TableCell>
                <TableCell align="right"><strong>{Number(line.totalAmount).toFixed(2)} {line.currency}</strong></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>

      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack spacing={2}>
          <Typography variant="subtitle2" fontWeight={700}>Actions</Typography>
          <Stack direction="row" spacing={1}>
            <Button
              variant="outlined"
              startIcon={<PictureAsPdfIcon />}
              href={`${import.meta.env.VITE_API_BASE_URL}/api/${resource}/${c.id}/pdf`}
              target="_blank"
            >
              Télécharger PDF
            </Button>
            {c.status === "GENERATED" && (
              <Button
                variant="contained"
                disableElevation
                color="primary"
                startIcon={<CheckCircleIcon />}
                onClick={() => issueMutation.mutate()}
                disabled={issueMutation.isPending}
              >
                {issueMutation.isPending ? <CircularProgress size={16} /> : "Émettre la correction"}
              </Button>
            )}
          </Stack>
          {c.status === "GENERATED" && (
            <Typography variant="body2" color="text.secondary">
              Cette correction est encore un brouillon : elle n'influence pas le solde du document d'origine tant qu'elle n'est pas émise.
            </Typography>
          )}
        </Stack>
      </Paper>
    </Stack>
  );
}
