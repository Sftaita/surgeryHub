import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
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
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import {
  CORRECTION_REASON_LABELS,
  createCreditNote,
  createDebitNote,
  issueDocument,
  listCorrections,
  listPayments,
  recordPayment,
  recordRefund,
  type CorrectionLineInput,
  type CorrectionReasonCode,
  type DocumentResource,
  type PaymentMethod,
} from "../api/documentFinance.api";
import { useToast } from "../../../ui/toast/useToast";

const METHOD_LABELS: Record<PaymentMethod, string> = {
  BANK_TRANSFER: "Virement",
  CASH: "Espèces",
  OTHER: "Autre",
};

const PAYMENT_STATUS_LABELS: Record<string, string> = {
  UNPAID: "Non payé",
  PARTIALLY_PAID: "Partiellement payé",
  PAID: "Payé",
};

const PAYMENT_STATUS_COLORS: Record<string, "default" | "warning" | "success"> = {
  UNPAID: "default",
  PARTIALLY_PAID: "warning",
  PAID: "success",
};

function extractError(err: unknown): string {
  const e = err as any;
  const violations = e?.response?.data?.error?.violations;
  if (Array.isArray(violations) && violations.length > 0) {
    return violations.map((v: any) => v.message ?? JSON.stringify(v)).join(" · ");
  }
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

interface LineRef {
  id: number;
  descriptionSnapshot: string;
  totalAmount: string;
}

export interface DocumentFinanceSummary {
  id: number;
  status: string;
  documentType: "STANDARD" | "CREDIT_NOTE" | "DEBIT_NOTE";
  currency: string;
  originalGrossAmount: string;
  creditNotesAmount: string;
  debitNotesAmount: string;
  netDocumentAmount: string;
  paidAmount: string;
  refundedAmount: string;
  remainingAmount: string;
  overpaidAmount: string;
  paymentStatus: string;
  corrections?: { id: number; documentType: string; status: string; number: string | null; totalAmount: string }[];
}

interface Props {
  resource: DocumentResource;
  document: DocumentFinanceSummary;
  lines: LineRef[];
  correctionsBasePath: string; // e.g. "/app/m/billing/firm-invoice-corrections"
  /** Invalidation de la query parente (forme de queryKey propre à chaque page hôte) — appelé après chaque mutation réussie, en plus de l'invalidation interne du panneau. */
  onChanged: () => void;
}

/**
 * EPIC Exécution & Valorisation, Lots 4-6 — panneau financier partagé entre
 * FirmInvoiceDetailPage et InstrumentistStatementDetailPage : solde net dérivé,
 * paiements/remboursements (append-only), notes de crédit/débit. Fonctionnel avant
 * tout — le backend reste la seule source de vérité, ce composant ne dérive/ne devine
 * jamais un état, il se contente d'afficher ce que l'API retourne et de rafraîchir
 * après chaque mutation.
 */
export default function DocumentFinancePanel({ resource, document, lines, correctionsBasePath, onChanged }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const rootId = document.id;

  const invalidate = React.useCallback(() => {
    onChanged();
  }, [onChanged]);

  const paymentsQuery = useQuery({
    queryKey: [resource, "payments", rootId],
    queryFn: () => listPayments(resource, rootId),
  });

  const correctionsQuery = useQuery({
    queryKey: [resource, "corrections", rootId],
    queryFn: () => listCorrections(resource, rootId),
    enabled: document.documentType === "STANDARD",
  });

  // ── Émission sans email ──────────────────────────────────────────────
  const issueMutation = useMutation({
    mutationFn: () => issueDocument(resource, rootId),
    onSuccess: () => { toast.success("Document émis"); invalidate(); },
    onError: (err) => toast.error(extractError(err)),
  });

  // ── Paiement ──────────────────────────────────────────────────────────
  const [payAmount, setPayAmount] = React.useState("");
  const [payDate, setPayDate] = React.useState(todayIso());
  const [payMethod, setPayMethod] = React.useState<PaymentMethod>("BANK_TRANSFER");
  const [payReference, setPayReference] = React.useState("");

  const recordPaymentMutation = useMutation({
    mutationFn: () =>
      recordPayment(resource, rootId, {
        amount: payAmount,
        currency: document.currency,
        paidAt: payDate,
        method: payMethod,
        reference: payReference || undefined,
      }),
    onSuccess: () => {
      toast.success("Paiement enregistré");
      setPayAmount(""); setPayReference("");
      qc.invalidateQueries({ queryKey: [resource, "payments", rootId] });
      invalidate();
    },
    onError: (err) => toast.error(extractError(err)),
  });

  // ── Remboursement ─────────────────────────────────────────────────────
  const [refundAmount, setRefundAmount] = React.useState("");
  const [refundDate, setRefundDate] = React.useState(todayIso());
  const [refundMethod, setRefundMethod] = React.useState<PaymentMethod>("BANK_TRANSFER");
  const [refundReference, setRefundReference] = React.useState("");

  const recordRefundMutation = useMutation({
    mutationFn: () =>
      recordRefund(resource, rootId, {
        amount: refundAmount,
        currency: document.currency,
        paidAt: refundDate,
        method: refundMethod,
        reference: refundReference || undefined,
      }),
    onSuccess: () => {
      toast.success("Remboursement enregistré");
      setRefundAmount(""); setRefundReference("");
      qc.invalidateQueries({ queryKey: [resource, "payments", rootId] });
      invalidate();
    },
    onError: (err) => toast.error(extractError(err)),
  });

  // ── Notes de crédit / débit ───────────────────────────────────────────
  const [correctionType, setCorrectionType] = React.useState<"CREDIT_NOTE" | "DEBIT_NOTE" | null>(null);
  const [correctionLines, setCorrectionLines] = React.useState<
    { originalDocumentLineId: string; missionId: string; reasonCode: CorrectionReasonCode; description: string; quantity: string; unitAmount: string; comment: string }[]
  >([]);

  function openCorrectionForm(type: "CREDIT_NOTE" | "DEBIT_NOTE") {
    setCorrectionType(type);
    setCorrectionLines([
      { originalDocumentLineId: "", missionId: "", reasonCode: type === "CREDIT_NOTE" ? "WRONG_QUANTITY" : "OMITTED_LINE", description: "", quantity: "1", unitAmount: "", comment: "" },
    ]);
  }

  function addCorrectionLine() {
    setCorrectionLines((prev) => [...prev, { originalDocumentLineId: "", missionId: "", reasonCode: "OTHER", description: "", quantity: "1", unitAmount: "", comment: "" }]);
  }

  function updateCorrectionLine(idx: number, patch: Partial<(typeof correctionLines)[number]>) {
    setCorrectionLines((prev) => prev.map((l, i) => (i === idx ? { ...l, ...patch } : l)));
  }

  function removeCorrectionLine(idx: number) {
    setCorrectionLines((prev) => prev.filter((_, i) => i !== idx));
  }

  const createCorrectionMutation = useMutation({
    mutationFn: () => {
      const payload: CorrectionLineInput[] = correctionLines.map((l) => ({
        originalDocumentLineId: l.originalDocumentLineId ? Number(l.originalDocumentLineId) : null,
        missionId: l.missionId ? Number(l.missionId) : null,
        reasonCode: l.reasonCode,
        description: l.description,
        quantity: l.quantity,
        unitAmount: l.unitAmount,
        comment: l.comment || null,
      }));
      return correctionType === "CREDIT_NOTE"
        ? createCreditNote(resource, rootId, payload)
        : createDebitNote(resource, rootId, payload);
    },
    onSuccess: (created) => {
      toast.success(correctionType === "CREDIT_NOTE" ? "Note de crédit créée (brouillon)" : "Note de débit créée (brouillon)");
      setCorrectionType(null);
      qc.invalidateQueries({ queryKey: [resource, "corrections", rootId] });
      navigate(`${correctionsBasePath}/${created.id}`);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const canCorrect = document.documentType === "STANDARD" && (document.status === "SENT" || document.status === "PAID");
  const canPay = document.status === "SENT" || document.status === "PAID";
  const canRefund = Number(document.overpaidAmount) > 0;

  return (
    <Stack spacing={3}>
      {/* ── Solde net ── */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" justifyContent="space-between" alignItems="center" mb={1.5}>
          <Typography variant="subtitle2" fontWeight={700}>Solde financier ({document.currency})</Typography>
          <Chip
            label={PAYMENT_STATUS_LABELS[document.paymentStatus] ?? document.paymentStatus}
            color={PAYMENT_STATUS_COLORS[document.paymentStatus] ?? "default"}
            size="small"
          />
        </Stack>
        <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(140px, 1fr))", gap: 2 }}>
          <Field label="Brut d'origine" value={document.originalGrossAmount} />
          <Field label="Notes de crédit" value={document.creditNotesAmount} sign="-" />
          <Field label="Notes de débit" value={document.debitNotesAmount} sign="+" />
          <Field label="Net dû" value={document.netDocumentAmount} strong />
          <Field label="Payé" value={document.paidAmount} />
          <Field label="Remboursé" value={document.refundedAmount} />
          <Field label="Restant dû" value={document.remainingAmount} strong color={Number(document.remainingAmount) > 0 ? "warning.main" : "success.main"} />
          <Field label="Trop-perçu" value={document.overpaidAmount} color={Number(document.overpaidAmount) > 0 ? "error.main" : undefined} />
        </Box>
        <Typography variant="caption" color="text.secondary" display="block" mt={1.5}>
          Net dû = brut d'origine − notes de crédit + notes de débit. Restant dû = net dû − payé + remboursé (jamais négatif).
          Un trop-perçu apparaît si le total payé dépasse le net dû — à rembourser ci-dessous.
        </Typography>
        {document.status === "GENERATED" && (
          <Box mt={2}>
            <Button size="small" variant="outlined" onClick={() => issueMutation.mutate()} disabled={issueMutation.isPending}>
              {issueMutation.isPending ? <CircularProgress size={16} /> : "Émettre sans email"}
            </Button>
          </Box>
        )}
      </Paper>

      {/* ── Paiements / remboursements ── */}
      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
        <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
          <Typography variant="subtitle2" fontWeight={700}>Paiements & remboursements ({paymentsQuery.data?.length ?? 0})</Typography>
        </Box>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Date</TableCell>
              <TableCell>Mouvement</TableCell>
              <TableCell>Méthode</TableCell>
              <TableCell>Référence</TableCell>
              <TableCell align="right">Montant</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {(paymentsQuery.data ?? []).length === 0 && (
              <TableRow><TableCell colSpan={5} align="center" sx={{ py: 3, color: "text.secondary" }}>Aucun mouvement</TableCell></TableRow>
            )}
            {(paymentsQuery.data ?? []).map((p) => (
              <TableRow key={p.id}>
                <TableCell>{p.paidAt}</TableCell>
                <TableCell>
                  <Chip
                    size="small"
                    label={p.direction === "INBOUND" ? "Paiement" : "Remboursement"}
                    color={p.direction === "INBOUND" ? "success" : "error"}
                    variant="outlined"
                  />
                </TableCell>
                <TableCell>{METHOD_LABELS[p.method]}</TableCell>
                <TableCell>{p.reference ?? "—"}</TableCell>
                <TableCell align="right">
                  <strong>{p.direction === "OUTBOUND" ? "-" : ""}{Number(p.amount).toFixed(2)} {p.currency}</strong>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>

        <Divider />
        <Box sx={{ p: 2 }}>
          <Stack direction="row" spacing={3} flexWrap="wrap" useFlexGap>
            {canPay && (
              <Stack spacing={1} sx={{ minWidth: 280 }}>
                <Typography variant="caption" fontWeight={700} color="text.secondary">Enregistrer un paiement</Typography>
                <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                  <TextField size="small" label="Montant" value={payAmount} onChange={(e) => setPayAmount(e.target.value)} sx={{ width: 110 }} />
                  <TextField size="small" type="date" label="Date" value={payDate} onChange={(e) => setPayDate(e.target.value)} InputLabelProps={{ shrink: true }} sx={{ width: 150 }} />
                  <Select size="small" value={payMethod} onChange={(e) => setPayMethod(e.target.value as PaymentMethod)} sx={{ width: 140 }}>
                    {(Object.keys(METHOD_LABELS) as PaymentMethod[]).map((m) => <MenuItem key={m} value={m}>{METHOD_LABELS[m]}</MenuItem>)}
                  </Select>
                  <TextField size="small" label="Référence" value={payReference} onChange={(e) => setPayReference(e.target.value)} sx={{ width: 140 }} />
                  <Button
                    variant="contained" disableElevation size="small"
                    onClick={() => recordPaymentMutation.mutate()}
                    disabled={!payAmount || recordPaymentMutation.isPending}
                  >
                    {recordPaymentMutation.isPending ? <CircularProgress size={16} /> : "Enregistrer"}
                  </Button>
                </Stack>
              </Stack>
            )}

            {canRefund && (
              <Stack spacing={1} sx={{ minWidth: 280 }}>
                <Typography variant="caption" fontWeight={700} color="text.secondary">
                  Enregistrer un remboursement (trop-perçu max : {Number(document.overpaidAmount).toFixed(2)} {document.currency})
                </Typography>
                <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                  <TextField size="small" label="Montant" value={refundAmount} onChange={(e) => setRefundAmount(e.target.value)} sx={{ width: 110 }} />
                  <TextField size="small" type="date" label="Date" value={refundDate} onChange={(e) => setRefundDate(e.target.value)} InputLabelProps={{ shrink: true }} sx={{ width: 150 }} />
                  <Select size="small" value={refundMethod} onChange={(e) => setRefundMethod(e.target.value as PaymentMethod)} sx={{ width: 140 }}>
                    {(Object.keys(METHOD_LABELS) as PaymentMethod[]).map((m) => <MenuItem key={m} value={m}>{METHOD_LABELS[m]}</MenuItem>)}
                  </Select>
                  <TextField size="small" label="Référence" value={refundReference} onChange={(e) => setRefundReference(e.target.value)} sx={{ width: 140 }} />
                  <Button
                    variant="outlined" color="error" size="small"
                    onClick={() => recordRefundMutation.mutate()}
                    disabled={!refundAmount || recordRefundMutation.isPending}
                  >
                    {recordRefundMutation.isPending ? <CircularProgress size={16} /> : "Rembourser"}
                  </Button>
                </Stack>
              </Stack>
            )}

            {!canPay && !canRefund && (
              <Typography variant="body2" color="text.secondary">
                Le document doit être émis pour enregistrer un paiement.
              </Typography>
            )}
          </Stack>
        </Box>
      </Paper>

      {/* ── Corrections ── */}
      {document.documentType === "STANDARD" && (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
          <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
            <Typography variant="subtitle2" fontWeight={700}>Notes de crédit / débit ({correctionsQuery.data?.length ?? 0})</Typography>
            {canCorrect && (
              <Stack direction="row" spacing={1}>
                <Button size="small" variant="outlined" onClick={() => openCorrectionForm("CREDIT_NOTE")}>+ Note de crédit</Button>
                <Button size="small" variant="outlined" onClick={() => openCorrectionForm("DEBIT_NOTE")}>+ Note de débit</Button>
              </Stack>
            )}
          </Stack>
          <Box sx={{ px: 2, pt: 1 }}>
            <Typography variant="caption" color="text.secondary">
              Une note de crédit diminue le net dû (erreur de facturation, ligne à retirer) ; une note de débit
              l'augmente (ligne omise à ajouter). Chaque note reste un document distinct, historisé et jamais
              modifiable une fois émise — seule une nouvelle correction peut en compenser une autre.
            </Typography>
          </Box>

          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Numéro</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right">Montant</TableCell>
                <TableCell align="right"></TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(correctionsQuery.data ?? []).length === 0 && (
                <TableRow><TableCell colSpan={5} align="center" sx={{ py: 3, color: "text.secondary" }}>Aucune correction</TableCell></TableRow>
              )}
              {(correctionsQuery.data ?? []).map((c) => (
                <TableRow key={c.id} hover>
                  <TableCell>{c.number ?? `#${c.id}`}</TableCell>
                  <TableCell>
                    <Chip size="small" label={c.documentType === "CREDIT_NOTE" ? "Crédit" : "Débit"} color={c.documentType === "CREDIT_NOTE" ? "error" : "info"} variant="outlined" />
                  </TableCell>
                  <TableCell>{c.status}</TableCell>
                  <TableCell align="right">{Number(c.totalAmount).toFixed(2)} {document.currency}</TableCell>
                  <TableCell align="right">
                    <Button size="small" onClick={() => navigate(`${correctionsBasePath}/${c.id}`)}>Détail</Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {!canCorrect && document.status === "GENERATED" && (
            <Box sx={{ p: 2 }}>
              <Alert severity="info">Un document non émis se corrige en l'annulant, jamais via une note de crédit/débit.</Alert>
            </Box>
          )}

          {correctionType && (
            <Box sx={{ p: 2 }}>
              <Divider sx={{ mb: 2 }} />
              <Typography variant="subtitle2" fontWeight={700} mb={1}>
                Nouvelle {correctionType === "CREDIT_NOTE" ? "note de crédit" : "note de débit"}
              </Typography>
              <Stack spacing={2}>
                {correctionLines.map((line, idx) => (
                  <Paper key={idx} variant="outlined" sx={{ p: 1.5 }}>
                    <Stack spacing={1}>
                      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="center">
                        <Select
                          size="small"
                          value={line.originalDocumentLineId}
                          onChange={(e) => updateCorrectionLine(idx, { originalDocumentLineId: e.target.value })}
                          displayEmpty
                          sx={{ minWidth: 220 }}
                        >
                          <MenuItem value="">— Ligne oubliée (préciser mission ci-dessous) —</MenuItem>
                          {lines.map((l) => (
                            <MenuItem key={l.id} value={String(l.id)}>
                              #{l.id} — {l.descriptionSnapshot} ({Number(l.totalAmount).toFixed(2)} {document.currency})
                            </MenuItem>
                          ))}
                        </Select>
                        {!line.originalDocumentLineId && (
                          <TextField
                            size="small" label="Mission ID" value={line.missionId}
                            onChange={(e) => updateCorrectionLine(idx, { missionId: e.target.value })}
                            sx={{ width: 110 }}
                          />
                        )}
                        <Select
                          size="small"
                          value={line.reasonCode}
                          onChange={(e) => updateCorrectionLine(idx, { reasonCode: e.target.value as CorrectionReasonCode })}
                          sx={{ minWidth: 180 }}
                        >
                          {(Object.keys(CORRECTION_REASON_LABELS) as CorrectionReasonCode[]).map((r) => (
                            <MenuItem key={r} value={r}>{CORRECTION_REASON_LABELS[r]}</MenuItem>
                          ))}
                        </Select>
                        <Button size="small" color="error" onClick={() => removeCorrectionLine(idx)} disabled={correctionLines.length === 1}>
                          Retirer
                        </Button>
                      </Stack>
                      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                        <TextField size="small" label="Description" value={line.description} onChange={(e) => updateCorrectionLine(idx, { description: e.target.value })} sx={{ flex: 1, minWidth: 200 }} />
                        <TextField size="small" label="Quantité" value={line.quantity} onChange={(e) => updateCorrectionLine(idx, { quantity: e.target.value })} sx={{ width: 90 }} />
                        <TextField size="small" label="Montant unitaire" value={line.unitAmount} onChange={(e) => updateCorrectionLine(idx, { unitAmount: e.target.value })} sx={{ width: 130 }} />
                        {line.reasonCode === "OTHER" && (
                          <TextField size="small" label="Commentaire (requis)" value={line.comment} onChange={(e) => updateCorrectionLine(idx, { comment: e.target.value })} sx={{ flex: 1, minWidth: 200 }} />
                        )}
                      </Stack>
                    </Stack>
                  </Paper>
                ))}
                <Stack direction="row" spacing={1}>
                  <Button size="small" onClick={addCorrectionLine}>+ Ajouter une ligne</Button>
                  <Box sx={{ flex: 1 }} />
                  <Button size="small" onClick={() => setCorrectionType(null)} color="inherit">Annuler</Button>
                  <Button
                    size="small" variant="contained" disableElevation
                    onClick={() => createCorrectionMutation.mutate()}
                    disabled={createCorrectionMutation.isPending || correctionLines.some((l) => !l.description || !l.unitAmount)}
                  >
                    {createCorrectionMutation.isPending ? <CircularProgress size={16} /> : "Créer (brouillon)"}
                  </Button>
                </Stack>
              </Stack>
            </Box>
          )}
        </Paper>
      )}
    </Stack>
  );
}

function Field({ label, value, strong, sign, color }: { label: string; value: string; strong?: boolean; sign?: string; color?: string }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Typography variant={strong ? "h6" : "body1"} fontWeight={strong ? 700 : 500} color={color}>
        {sign}{Number(value).toFixed(2)}
      </Typography>
    </Box>
  );
}
