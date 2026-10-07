import * as React from "react";
import {
  Alert,
  Box,
  Checkbox,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
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
import RemoveCircleOutlineIcon from "@mui/icons-material/RemoveCircleOutline";
import { Link as RouterLink, useParams, useNavigate, useSearchParams } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getFirmInvoice,
  sendFirmInvoice,
  markFirmInvoicePaid,
  getFirmInvoicePdfUrl,
  abandonFirmInvoiceDraft,
  addLinesToFirmInvoiceDraft,
  getFirmInvoiceDraftCandidates,
  generateFirmInvoiceDraft,
  removeFirmInvoiceDraftLine,
  type InvoiceStatus,
} from "../../../features/billing-firm/api/firmInvoice.api";
import { useToast } from "../../../ui/toast/useToast";
import { extractBillingError } from "../../../features/billing-firm/api/firmBillingWorklist.api";
import DocumentFinancePanel from "../../../features/billing-shared/components/DocumentFinancePanel";

const STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success" | "error"> = {
  DRAFT: "default", GENERATED: "info", SENT: "warning", PAID: "success", CANCELLED: "error", ABANDONED: "default",
};

function statusLabel(s: InvoiceStatus) {
  return { DRAFT: "Brouillon", GENERATED: "Générée", SENT: "Envoyée", PAID: "Payée", CANCELLED: "Facture annulée", ABANDONED: "Brouillon abandonné" }[s];
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
      qc.invalidateQueries({ queryKey: ["firm-billing-worklist"] });
      qc.invalidateQueries({ queryKey: ["firm-billing-line-history"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const markPaidMutation = useMutation({
    mutationFn: () => markFirmInvoicePaid(Number(id)),
    onSuccess: () => {
      toast.success("Facture marquée payée");
      qc.invalidateQueries({ queryKey: ["firm-invoice", Number(id)] });
      qc.invalidateQueries({ queryKey: ["firm-invoices"] });
      qc.invalidateQueries({ queryKey: ["firm-billing-worklist"] });
      qc.invalidateQueries({ queryKey: ["firm-billing-line-history"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  // ── D-135 — brouillon : retrait de ligne, génération, abandon (endpoints dédiés) ──
  const [confirmAbandon, setConfirmAbandon] = React.useState(false);
  const [addOpen, setAddOpen] = React.useState(false);
  async function afterDraftChange() {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ["firm-invoice", Number(id)] }),
      qc.invalidateQueries({ queryKey: ["firm-invoices"] }),
      qc.invalidateQueries({ queryKey: ["firm-billing-worklist"] }),
      qc.invalidateQueries({ queryKey: ["firm-invoice-candidates"] }),
      qc.invalidateQueries({ queryKey: ["firm-billing-line-history"] }),
    ]);
  }
  const removeLine = useMutation({
    mutationFn: (invoiceLineId: number) => removeFirmInvoiceDraftLine(Number(id), invoiceLineId),
    onSuccess: async () => { toast.success("Ligne retirée du brouillon — elle est de nouveau libre."); await afterDraftChange(); },
    onError: (err) => toast.error(extractError(err)),
  });
  const generateDraft = useMutation({
    mutationFn: () => generateFirmInvoiceDraft(Number(id)),
    onSuccess: async (inv) => { toast.success(`Facture ${inv.number} générée.`); await afterDraftChange(); },
    onError: (err) => toast.error(extractError(err)),
  });
  const abandonDraft = useMutation({
    mutationFn: () => abandonFirmInvoiceDraft(Number(id)),
    onSuccess: async () => { setConfirmAbandon(false); toast.success("Brouillon abandonné — ses lignes sont de nouveau libres."); await afterDraftChange(); },
    onError: (err) => toast.error(extractError(err)),
  });

  if (invoiceQuery.isLoading) return <CircularProgress />;
  if (!invoiceQuery.data) return <Typography>Facture introuvable</Typography>;

  const inv = invoiceQuery.data;
  const total = Number(inv.totalAmount);
  const allowed = inv.allowedActions ?? [];
  const isDraft = inv.status === "DRAFT";
  // D-137 — un brouillon abandonné n'a jamais été une facture : ni solde, ni PDF, ni action.
  const isAbandoned = inv.status === "ABANDONED";
  const neverIssued = isDraft || isAbandoned;
  const staleCount = (inv.lines ?? []).filter((l) => l.stale).length;
  const draftBusy = removeLine.isPending || generateDraft.isPending || abandonDraft.isPending;

  return (
    <Stack spacing={3}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate("/app/m/billing/firm-invoices")} size="small">
          Retour
        </Button>
        <Typography variant="h6" fontWeight={700} sx={{ flex: 1 }}>
          {isDraft ? `Brouillon ${inv.firm.name} #${inv.id}` : inv.status === "ABANDONED" ? `Brouillon abandonné ${inv.firm.name} #${inv.id}` : `Facture ${inv.number ?? `F-${inv.id}`}`}
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

      {isAbandoned && (
        <Alert severity="info" variant="outlined">
          Brouillon abandonné : il n'a jamais été une facture (aucun numéro, aucun calcul verrouillé). Ses lignes ont été libérées ; leur passage par ce brouillon reste visible dans leur historique.
        </Alert>
      )}

      {isDraft && (
        <Paper variant="outlined" sx={{ p: 2, borderRadius: 2, borderColor: "secondary.main" }} role="region" aria-label="Générateur de facture">
          <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
            <Box sx={{ flex: 1, minWidth: 260 }}>
              <Typography fontWeight={700}>Brouillon — rien n'est encore émis ni numéroté.</Typography>
              <Typography variant="body2" color="text.secondary">
                Ajoutez ou retirez des prestations ici, puis générez : le numéro est attribué et les calculs sont verrouillés à ce moment-là.
              </Typography>
            </Box>
            {allowed.includes("editLines") && (
              <Button variant="outlined" disabled={draftBusy} onClick={() => setAddOpen(true)}>+ Ajouter des prestations</Button>
            )}
            {allowed.includes("generate") && (
              <Button variant="contained" disableElevation disabled={draftBusy || staleCount > 0 || (inv.lines ?? []).length === 0} onClick={() => generateDraft.mutate()}>
                {generateDraft.isPending ? <CircularProgress size={16} /> : "Générer la facture"}
              </Button>
            )}
            {allowed.includes("abandon") && (
              <Button color="error" disabled={draftBusy} onClick={() => setConfirmAbandon(true)}>Abandonner le brouillon</Button>
            )}
          </Stack>
          {staleCount > 0 && (
            <Alert severity="warning" sx={{ mt: 1.5 }}>
              {staleCount} ligne{staleCount > 1 ? "s" : ""} obsolète{staleCount > 1 ? "s" : ""} : le calcul a changé depuis l'ajout. Retirez-la{staleCount > 1 ? "s" : ""}, puis ajoutez la version à jour depuis Facturation firmes.
            </Alert>
          )}
        </Paper>
      )}

      {isDraft && (
        <AddPrestationsDialog
          open={addOpen}
          draftId={inv.id}
          firmName={inv.firm.name}
          onClose={() => setAddOpen(false)}
          onAdded={async (count) => { setAddOpen(false); toast.success(`${count} prestation(s) ajoutée(s) au brouillon.`); await afterDraftChange(); }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      <Dialog open={confirmAbandon} onClose={() => setConfirmAbandon(false)}>
        <DialogTitle>Abandonner ce brouillon ?</DialogTitle>
        <DialogContent>
          <Typography>Ses {(inv.lines ?? []).length} ligne(s) redeviennent libres. Leur passage par ce brouillon reste visible dans leur historique.</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmAbandon(false)}>Garder le brouillon</Button>
          <Button color="error" variant="contained" disableElevation disabled={abandonDraft.isPending} onClick={() => abandonDraft.mutate()}>Abandonner</Button>
        </DialogActions>
      </Dialog>

      {/* Lines */}
      {focusLine && invoiceQuery.data && !focusedLineFound && (
        <Alert severity="info">La ligne recherchée ne figure plus sur ce document — consultez son historique depuis la facturation firmes.</Alert>
      )}

      <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
        <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50" }}>
          <Typography variant="subtitle2" fontWeight={700}>{neverIssued ? "Lignes du brouillon" : "Lignes facturées"} ({inv.lines?.length ?? 0})</Typography>
          <Typography variant="caption" color="text.secondary">
            {isAbandoned
              ? "Aucune ligne : l'abandon les a toutes libérées."
              : isDraft
              ? "Montants issus des calculs approuvés ; ils seront figés à la génération."
              : "Snapshot figé à la génération : quantités, prix et montants ne changent plus, même si un tarif est modifié ensuite."}
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
              {isDraft && <TableCell padding="checkbox" />}
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
                  {line.stale && <Chip size="small" color="error" variant="outlined" label="Obsolète" sx={{ ml: 1 }} />}
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
                {isDraft && (
                  <TableCell padding="checkbox">
                    <Button size="small" color="error" startIcon={<RemoveCircleOutlineIcon />} disabled={draftBusy} onClick={() => removeLine.mutate(line.id)}
                      aria-label={`Retirer ${line.materialLabel ?? line.interventionLabel ?? "la ligne"} du brouillon`}>
                      Retirer
                    </Button>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>

      {/* Solde, paiements, remboursements, notes de crédit/débit (EPIC Exécution & Valorisation, Lots 4-6) */}
      {!neverIssued && <DocumentFinancePanel
        resource="firm-invoices"
        document={inv}
        lines={(inv.lines ?? []).map((l) => ({ id: l.id, descriptionSnapshot: l.descriptionSnapshot, totalAmount: l.totalAmount }))}
        correctionsBasePath="/app/m/billing/firm-invoice-corrections"
        onChanged={() => {
          // Un paiement complet passe la facture PAID : l'état courant des lignes change aussi.
          qc.invalidateQueries({ queryKey: ["firm-invoice", Number(id)] });
          qc.invalidateQueries({ queryKey: ["firm-invoices"] });
          qc.invalidateQueries({ queryKey: ["firm-billing-worklist"] });
          qc.invalidateQueries({ queryKey: ["firm-billing-line-history"] });
        }}
      />}

      {/* Actions */}
      {!neverIssued && <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
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
      </Paper>}
    </Stack>
  );
}

/**
 * D-137 — « + Ajouter des prestations » : la liste vient de GET /candidate-lines (règles de
 * compatibilité appliquées par le backend : même firme, libre, facturable, non obsolète,
 * ni facturée ni dans un autre brouillon). Le frontend ne filtre rien.
 */
function AddPrestationsDialog({ open, draftId, firmName, onClose, onAdded, onError }: {
  open: boolean;
  draftId: number;
  firmName: string;
  onClose: () => void;
  onAdded: (count: number) => Promise<void>;
  onError: (message: string) => void;
}) {
  const [selected, setSelected] = React.useState<Set<number>>(new Set());
  const query = useQuery({
    queryKey: ["firm-invoice-candidates", draftId],
    queryFn: () => getFirmInvoiceDraftCandidates(draftId),
    enabled: open,
  });
  React.useEffect(() => { if (!open) setSelected(new Set()); }, [open]);
  const rows = query.data ?? [];
  const add = useMutation({
    mutationFn: () => addLinesToFirmInvoiceDraft(draftId, [...selected]),
    onSuccess: () => onAdded(selected.size),
    onError: (err) => onError(extractError(err)),
  });
  const toggle = (id: number) => setSelected((prev) => {
    const next = new Set(prev);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });
  const money = (a: string | null, c: string | null) => (a === null ? "—" : `${Number(a).toLocaleString("fr-BE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${c === "EUR" || !c ? "€" : c}`);

  return (
    <Dialog open={open} onClose={onClose} maxWidth="lg" fullWidth aria-labelledby="add-prestations-title">
      <DialogTitle id="add-prestations-title">Ajouter des prestations — {firmName}</DialogTitle>
      <DialogContent dividers>
        {query.isLoading && <CircularProgress size={22} />}
        {query.isError && <Alert severity="error">{extractError(query.error)}</Alert>}
        {query.data && rows.length === 0 && (
          <Alert severity="info" variant="outlined">Aucune autre prestation disponible pour cette firme.</Alert>
        )}
        {rows.length > 0 && (
          <Box sx={{ overflowX: "auto" }}>
            <Table size="small" aria-label="Prestations disponibles">
              <TableHead>
                <TableRow>
                  <TableCell padding="checkbox">
                    <Checkbox
                      checked={selected.size === rows.length}
                      indeterminate={selected.size > 0 && selected.size < rows.length}
                      onChange={() => setSelected(selected.size === rows.length ? new Set() : new Set(rows.map((r) => r.financialLineId!)))}
                      inputProps={{ "aria-label": "Tout sélectionner" }}
                    />
                  </TableCell>
                  <TableCell>Date</TableCell>
                  <TableCell>Site</TableCell>
                  <TableCell>Chirurgien</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell>Prestation</TableCell>
                  <TableCell>Référence</TableCell>
                  <TableCell align="right">Qté</TableCell>
                  <TableCell align="right">Montant</TableCell>
                  <TableCell>Motif</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {rows.map((r) => (
                  <TableRow key={r.key} hover selected={selected.has(r.financialLineId!)} data-testid={`candidate-${r.key}`}>
                    <TableCell padding="checkbox">
                      <Checkbox checked={selected.has(r.financialLineId!)} onChange={() => toggle(r.financialLineId!)} inputProps={{ "aria-label": `Sélectionner ${r.label ?? "la prestation"}` }} />
                    </TableCell>
                    <TableCell>{formatDate(r.mission.date)}</TableCell>
                    <TableCell>{r.mission.site ?? "—"}</TableCell>
                    <TableCell>{r.mission.surgeon ?? "—"}</TableCell>
                    <TableCell>{r.sourceType === "MATERIAL" ? "Matériel" : "Intervention"}</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>{r.label ?? "—"}</TableCell>
                    <TableCell>{r.reference ?? ""}</TableCell>
                    <TableCell align="right">{r.quantity ?? "—"}</TableCell>
                    <TableCell align="right">{money(r.amount, r.currency)}</TableCell>
                    <TableCell sx={{ color: "text.secondary" }}>{r.reasonLabel}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Fermer</Button>
        <Button variant="contained" disableElevation disabled={selected.size === 0 || add.isPending} onClick={() => add.mutate()}>
          {add.isPending ? <CircularProgress size={16} /> : `Ajouter au brouillon${selected.size ? ` (${selected.size})` : ""}`}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
