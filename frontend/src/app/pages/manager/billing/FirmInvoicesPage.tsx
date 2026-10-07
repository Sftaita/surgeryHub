import * as React from "react";
import {
  Alert,
  Autocomplete,
  Box,
  Button,
  Checkbox,
  Drawer,
  Chip,
  CircularProgress,
  IconButton,
  MenuItem,
  Paper,
  Select,
  Stack,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tabs,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip,
  Typography,
} from "@mui/material";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import OpenInNewIcon from "@mui/icons-material/OpenInNew";
import HistoryIcon from "@mui/icons-material/History";
import CloseIcon from "@mui/icons-material/Close";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import GridOnIcon from "@mui/icons-material/GridOn";
import ReceiptLongOutlinedIcon from "@mui/icons-material/ReceiptLongOutlined";
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from "@tanstack/react-query";
import { Link as RouterLink, useNavigate } from "react-router-dom";
import { apiClient } from "../../../api/apiClient";
import { PageHeader } from "../../../ui/PageHeader";
import { useToast } from "../../../ui/toast/useToast";
import {
  createFirmInvoiceFromCalculations,
  getFirmInvoicePdfUrl,
  getFirmInvoices,
  markFirmInvoicePaid,
  type FirmInvoice,
  type InvoiceStatus,
} from "../../../features/billing-firm/api/firmInvoice.api";
import {
  exportFirmBillingSelection,
  extractBillingError,
  extractBillingErrorAsync,
  getFirmBillingLineHistory,
  getFirmBillingWorklist,
  invoiceFocusUrl,
  runFirmBillingCalculations,
  type AnomalyActionCode,
  type BillingStatus,
  type InvoiceState,
  type FirmBillingWorklist,
  type SourceType,
  type WorklistAnomaly,
  type WorklistRow,
} from "../../../features/billing-firm/api/firmBillingWorklist.api";
import { approveFinancialCalculation } from "../../../features/financial-calculation/api/financialCalculation.api";

const INVOICE_STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success" | "error"> = {
  DRAFT: "default", GENERATED: "info", SENT: "warning", PAID: "success", CANCELLED: "error",
};
const INVOICE_STATUS_LABELS: Record<InvoiceStatus, string> = {
  DRAFT: "Brouillon", GENERATED: "Générée", SENT: "Envoyée", PAID: "Payée", CANCELLED: "Annulée",
};

/** Présentation seulement : le statut et son libellé viennent du backend. */
const BILLING_STATUS_COLORS: Record<BillingStatus, "success" | "default" | "warning" | "info"> = {
  BILLABLE: "success", NOT_BILLABLE: "default", TO_REVIEW: "warning", INVOICED: "info",
};

/** Présentation de l'état documentaire courant (valeur et libellé fournis par le backend). */
const INVOICE_STATE_COLORS: Record<InvoiceState, "default" | "info" | "warning" | "success" | "secondary"> = {
  FREE: "default", IN_DRAFT: "secondary", GENERATED: "info", SENT: "warning", PAID: "success",
};

/** Où mène chaque action d'anomalie (navigation uniquement). */
const ACTION_ROUTES: Partial<Record<AnomalyActionCode, string>> = {
  CONFIGURE_INTERVENTION_RATE: "/app/m/catalogue/prestations",
  CONFIGURE_MATERIAL_RATE: "/app/m/catalogue/prestations",
  CONFIGURE_INSTRUMENTIST_RATE: "/app/m/instrumentists",
};

type TabKey = "prestations" | "toFix" | "invoices";

/** Mois civil → bornes AAAA-MM-JJ (dates métier, jamais toISOString : voir D-123). */
function monthBounds(year: number, month: number): { from: string; to: string } {
  const pad = (n: number) => String(n).padStart(2, "0");
  const lastDay = new Date(year, month, 0).getDate();
  return { from: `${year}-${pad(month)}-01`, to: `${year}-${pad(month)}-${pad(lastDay)}` };
}

function formatMoney(amount: string | number | null | undefined, currency = "EUR"): string {
  const n = Number(amount ?? 0);
  return `${n.toLocaleString("fr-BE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency === "EUR" ? "€" : currency}`;
}

function formatAmounts(amounts: { currency: string; amount: string }[]): string {
  return amounts.length ? amounts.map((a) => formatMoney(a.amount, a.currency)).join(" + ") : formatMoney(0);
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const [y, m, d] = iso.slice(0, 10).split("-");
  return `${d}/${m}/${y}`;
}

function plural(n: number, word: string): string {
  return `${n} ${word}${n > 1 ? "s" : ""}`;
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/**
 * D-133 — « Facturation firmes » : worklist partant de l'activité validée.
 * Classification (Facturable / Non facturable / À vérifier / Facturé), motifs, montants,
 * tuiles et anomalies viennent tous de GET /api/firm-billing/worklist : aucune décision
 * métier ici. Les exports envoient exactement les clés des lignes cochées.
 */
export default function FirmInvoicesPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();

  const now = new Date();
  const [year, setYear] = React.useState(now.getFullYear());
  const [month, setMonth] = React.useState(now.getMonth() + 1);
  const [firmIds, setFirmIds] = React.useState<number[]>([]);
  const [type, setType] = React.useState<SourceType | "">("");
  const [status, setStatus] = React.useState<BillingStatus | "">("");
  const [tab, setTab] = React.useState<TabKey>("prestations");
  const [lastCreated, setLastCreated] = React.useState<FirmInvoice | null>(null);

  const { from, to } = monthBounds(year, month);
  const periodLabel = new Date(year, month - 1, 1).toLocaleDateString("fr-BE", { month: "long", year: "numeric" });

  function shiftMonth(delta: number) {
    const d = new Date(year, month - 1 + delta, 1);
    setYear(d.getFullYear());
    setMonth(d.getMonth() + 1);
  }

  const firmsQuery = useQuery({
    queryKey: ["firms"],
    queryFn: async () => (await apiClient.get("/api/firms")).data as { id: number; name: string }[],
  });

  const worklistQuery = useQuery({
    queryKey: ["firm-billing-worklist", from, to, firmIds, type, status],
    queryFn: () => getFirmBillingWorklist({ from, to, firmIds, type: type || undefined, status: status || undefined }),
    placeholderData: (prev) => prev,
  });

  async function refreshAll() {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ["firm-billing-worklist"] }),
      qc.invalidateQueries({ queryKey: ["firm-invoices"] }),
    ]);
  }

  const worklist = worklistQuery.data;
  const summary = worklist?.summary;
  const firms = firmsQuery.data ?? [];
  const selectedFirms = firms.filter((f) => firmIds.includes(f.id));

  function showStatus(next: BillingStatus | "") {
    setStatus(next);
    setTab("prestations");
  }

  return (
    <Stack spacing={2.5}>
      <PageHeader
        icon={ReceiptLongOutlinedIcon}
        title="Facturation firmes"
        subtitle="Tout ce qui a été réalisé et validé sur la période : ce qui est facturable, ce qui ne l'est pas et pourquoi, ce qui bloque et ce qui est déjà facturé."
      />

      {/* Filtres */}
      <Stack direction="row" spacing={2} alignItems="center" flexWrap="wrap" useFlexGap>
        <Stack direction="row" alignItems="center" spacing={0.5}>
          <IconButton aria-label="Mois précédent" onClick={() => shiftMonth(-1)} size="small"><ChevronLeftIcon /></IconButton>
          <Typography fontWeight={700} sx={{ minWidth: 150, textAlign: "center", textTransform: "capitalize" }}>{periodLabel}</Typography>
          <IconButton aria-label="Mois suivant" onClick={() => shiftMonth(1)} size="small"><ChevronRightIcon /></IconButton>
        </Stack>
        <Autocomplete
          multiple
          size="small"
          options={firms}
          value={selectedFirms}
          getOptionLabel={(f) => f.name}
          isOptionEqualToValue={(a, b) => a.id === b.id}
          onChange={(_, value) => setFirmIds(value.map((f) => f.id))}
          disableCloseOnSelect
          sx={{ minWidth: 260, maxWidth: 520, flex: 1 }}
          renderInput={(params) => (
            <TextField {...params} label="Firmes" placeholder={firmIds.length ? "" : "Toutes les firmes"} inputProps={{ ...params.inputProps, "aria-label": "Filtrer par firmes" }} />
          )}
        />
        <ToggleButtonGroup size="small" exclusive value={type} onChange={(_, v) => v !== null && setType(v)} aria-label="Type de prestation">
          <ToggleButton value="">Tous les types</ToggleButton>
          <ToggleButton value="INTERVENTION">Interventions</ToggleButton>
          <ToggleButton value="MATERIAL">Matériel</ToggleButton>
        </ToggleButtonGroup>
      </Stack>

      {/* Tuiles — chiffres backend, toujours avec leur unité */}
      <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(5, 1fr)" } }}>
        <Kpi label="Prestations validées" value={summary ? plural(summary.lineCount, "ligne") : "—"} active={tab === "prestations" && status === ""} onClick={() => showStatus("")} />
        <Kpi
          label="Facturables"
          value={summary ? plural(summary.billable.lineCount, "ligne") : "—"}
          hint={summary ? formatAmounts(summary.billable.amounts) : undefined}
          tone="success"
          active={tab === "prestations" && status === "BILLABLE"}
          onClick={() => showStatus("BILLABLE")}
        />
        <Kpi label="Non facturables" value={summary ? plural(summary.notBillable.lineCount, "ligne") : "—"} active={tab === "prestations" && status === "NOT_BILLABLE"} onClick={() => showStatus("NOT_BILLABLE")} />
        <Kpi
          label="À corriger"
          value={summary ? plural(summary.anomalyCount, "anomalie") : "—"}
          hint={summary && summary.toReview.lineCount > 0 ? `${plural(summary.toReview.lineCount, "ligne")} à vérifier` : undefined}
          tone={summary && summary.anomalyCount > 0 ? "warning" : undefined}
          active={tab === "toFix"}
          onClick={() => setTab("toFix")}
        />
        <Kpi
          label="Déjà facturées"
          value={summary ? plural(summary.invoiced.lineCount, "ligne") : "—"}
          hint={summary && summary.invoiced.lineCount > 0 ? formatAmounts(summary.invoiced.amounts) : undefined}
          active={tab === "prestations" && status === "INVOICED"}
          onClick={() => showStatus("INVOICED")}
        />
      </Box>

      {summary && summary.pendingValidationMissionCount > 0 && (
        <Alert severity="info" variant="outlined">
          {plural(summary.pendingValidationMissionCount, "mission")} encodée{summary.pendingValidationMissionCount > 1 ? "s" : ""} sur la période {summary.pendingValidationMissionCount > 1 ? "attendent" : "attend"} encore leur validation : {summary.pendingValidationMissionCount > 1 ? "elles apparaîtront" : "elle apparaîtra"} ici une fois validée{summary.pendingValidationMissionCount > 1 ? "s" : ""}.
          <Button component={RouterLink} to="/app/m/billing/encodings" size="small" sx={{ ml: 1 }}>Suivi des encodages</Button>
        </Alert>
      )}

      {lastCreated && (
        <Alert
          severity="success"
          onClose={() => setLastCreated(null)}
          action={<Button color="inherit" size="small" onClick={() => navigate(`/app/m/billing/firm-invoices/${lastCreated.id}`)}>Ouvrir la facture</Button>}
        >
          Facture {lastCreated.number} générée pour {lastCreated.firm.name} — {formatMoney(lastCreated.totalAmount, lastCreated.currency)}.
        </Alert>
      )}

      <Tabs value={tab} onChange={(_, v) => setTab(v)}>
        <Tab value="prestations" label="Prestations" />
        <Tab value="toFix" label={`À corriger${summary ? ` (${summary.anomalyCount})` : ""}`} />
        <Tab value="invoices" label="Factures" />
      </Tabs>

      {worklistQuery.isError && <Alert severity="error">{extractBillingError(worklistQuery.error)}</Alert>}
      {worklistQuery.isLoading && <CircularProgress size={24} />}

      {worklist && tab === "prestations" && (
        <PrestationsView
          worklist={worklist}
          status={status}
          onStatus={setStatus}
          period={{ from, to }}
          firmIds={firmIds}
          onInvoiceCreated={async (invoice) => {
            toast.success(`Facture ${invoice.number} générée — ${invoice.lineCount ?? invoice.lines?.length ?? 0} ligne(s), ${formatMoney(invoice.totalAmount, invoice.currency)}.`);
            setLastCreated(invoice);
            await refreshAll();
          }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {worklist && tab === "toFix" && (
        <ToFixView
          worklist={worklist}
          onDone={async (msg) => { toast.success(msg); await refreshAll(); }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {tab === "invoices" && (
        <InvoicesView
          from={from}
          to={to}
          firmIds={firmIds}
          counts={summary?.invoices}
          onMarkPaid={async () => { toast.success("Facture marquée payée."); await refreshAll(); }}
          onError={(msg) => toast.error(msg)}
        />
      )}
    </Stack>
  );
}

function Kpi({ label, value, hint, tone, active, onClick }: {
  label: string;
  value: React.ReactNode;
  hint?: string;
  tone?: "success" | "warning";
  active?: boolean;
  onClick?: () => void;
}) {
  return (
    <Paper
      variant="outlined"
      component="button"
      type="button"
      onClick={onClick}
      aria-pressed={active}
      sx={{
        p: 1.5, borderRadius: 2, textAlign: "left", cursor: "pointer", font: "inherit", bgcolor: active ? "action.selected" : "background.paper",
        borderColor: active ? "primary.main" : tone === "warning" ? "warning.main" : undefined, "&:hover": { bgcolor: "action.hover" },
      }}
    >
      <Typography variant="caption" color="text.secondary" fontWeight={700}>{label}</Typography>
      <Typography variant="h6" fontWeight={800} sx={{ fontVariantNumeric: "tabular-nums", color: tone === "warning" ? "warning.dark" : tone === "success" ? "success.dark" : undefined }}>
        {value}
      </Typography>
      {hint && <Typography variant="body2" color="text.secondary" sx={{ fontVariantNumeric: "tabular-nums" }}>{hint}</Typography>}
    </Paper>
  );
}

// ── Prestations ────────────────────────────────────────────────────────────

const STATUS_FILTERS: { value: BillingStatus | ""; label: string }[] = [
  { value: "", label: "Tous" },
  { value: "BILLABLE", label: "Facturables" },
  { value: "NOT_BILLABLE", label: "Non facturables" },
  { value: "TO_REVIEW", label: "À vérifier" },
  { value: "INVOICED", label: "Facturés" },
];

function BillingBadge({ row }: { row: WorklistRow }) {
  return (
    <Tooltip title={<><strong>{row.billingStatusLabel} — {row.reasonLabel}</strong><br />{row.reasonDetail}</>} arrow>
      <Chip
        size="small"
        tabIndex={0}
        color={BILLING_STATUS_COLORS[row.billingStatus]}
        variant={row.billingStatus === "NOT_BILLABLE" ? "outlined" : "filled"}
        label={row.billingStatusLabel}
        aria-label={`${row.billingStatusLabel} — ${row.reasonLabel}. ${row.reasonDetail}`}
      />
    </Tooltip>
  );
}

function PrestationsView({ worklist, status, onStatus, period, firmIds, onInvoiceCreated, onError }: {
  worklist: FirmBillingWorklist;
  status: BillingStatus | "";
  onStatus: (s: BillingStatus | "") => void;
  period: { from: string; to: string };
  firmIds: number[];
  onInvoiceCreated: (invoice: FirmInvoice) => Promise<void>;
  onError: (message: string) => void;
}) {
  const rows = worklist.rows;
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  const [exporting, setExporting] = React.useState<"pdf" | "xlsx" | null>(null);
  const [historyOf, setHistoryOf] = React.useState<WorklistRow | null>(null);

  // La sélection ne porte que sur des lignes affichées : toute clé disparue des données
  // serveur (filtre, période, rechargement) est retirée — jamais exportée ni facturée.
  React.useEffect(() => {
    const visible = new Set(rows.map((r) => r.key));
    setSelected((prev) => {
      const kept = [...prev].filter((k) => visible.has(k));
      return kept.length === prev.size ? prev : new Set(kept);
    });
  }, [rows]);

  const selectedRows = rows.filter((r) => selected.has(r.key));
  const allSelected = rows.length > 0 && selectedRows.length === rows.length;

  function toggle(key: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      return next;
    });
  }

  async function doExport(format: "pdf" | "xlsx") {
    setExporting(format);
    try {
      const { blob, filename } = await exportFirmBillingSelection({ ...period, firmIds, keys: selectedRows.map((r) => r.key), format });
      downloadBlob(blob, filename);
    } catch (err) {
      onError(await extractBillingErrorAsync(err));
    } finally {
      setExporting(null);
    }
  }

  // Génération : uniquement des lignes que le backend déclare facturables (canInvoice),
  // d'une seule firme et d'une seule devise — une facture = une firme (D-123). Le serveur
  // revalide tout sous verrou.
  const invoiceFirm = selectedRows[0]?.firm ?? null;
  const invoiceCurrency = selectedRows[0]?.currency ?? null;
  const invoiceable = selectedRows.length > 0
    && selectedRows.every((r) => r.canInvoice && r.financialLineId !== null && r.firm?.id === invoiceFirm?.id && r.currency === invoiceCurrency);
  const invoiceHint = selectedRows.length === 0 ? "" : !selectedRows.every((r) => r.canInvoice)
    ? "Seules des lignes « Facturable » peuvent être placées sur une facture."
    : "Une facture ne concerne qu'une seule firme (et une seule devise).";

  const generate = useMutation({
    mutationFn: () => createFirmInvoiceFromCalculations({
      firmId: invoiceFirm!.id,
      currency: invoiceCurrency!,
      periodStart: period.from,
      periodEnd: period.to,
      selectedFinancialCalculationLineIds: selectedRows.map((r) => r.financialLineId!),
    }),
    onSuccess: async (invoice) => { setSelected(new Set()); await onInvoiceCreated(invoice); },
    onError: (err) => onError(extractBillingError(err)),
  });

  return (
    <Stack spacing={1.5}>
      <ToggleButtonGroup size="small" exclusive value={status} onChange={(_, v) => v !== null && onStatus(v)} aria-label="Statut de facturation">
        {STATUS_FILTERS.map((f) => <ToggleButton key={f.value || "ALL"} value={f.value}>{f.label}</ToggleButton>)}
      </ToggleButtonGroup>

      {selectedRows.length > 0 && (
        <Paper variant="outlined" sx={{ px: 2, py: 1, borderRadius: 2, bgcolor: "action.selected" }} role="region" aria-label="Actions sur la sélection">
          <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
            <Typography fontWeight={700} sx={{ mr: 1 }}>{plural(selectedRows.length, "ligne")} sélectionnée{selectedRows.length > 1 ? "s" : ""}</Typography>
            <Button size="small" variant="outlined" startIcon={exporting === "pdf" ? <CircularProgress size={14} /> : <PictureAsPdfIcon />} disabled={exporting !== null} onClick={() => doExport("pdf")}>Exporter PDF</Button>
            <Button size="small" variant="outlined" startIcon={exporting === "xlsx" ? <CircularProgress size={14} /> : <GridOnIcon />} disabled={exporting !== null} onClick={() => doExport("xlsx")}>Exporter Excel</Button>
            <Tooltip title={invoiceable ? "" : invoiceHint}>
              <span>
                <Button size="small" variant="contained" disableElevation disabled={!invoiceable || generate.isPending} onClick={() => generate.mutate()}>
                  {generate.isPending ? <CircularProgress size={16} /> : invoiceable ? `Générer la facture ${invoiceFirm?.name ?? ""}` : "Générer la facture"}
                </Button>
              </span>
            </Tooltip>
            <Box sx={{ flex: 1 }} />
            <Button size="small" onClick={() => setSelected(new Set())}>Désélectionner</Button>
          </Stack>
        </Paper>
      )}

      {rows.length === 0 ? (
        <Alert severity="info">
          {status === "" ? "Aucune intervention ni aucun matériel validé sur cette période pour ces filtres." : "Aucune prestation dans cette catégorie pour ces filtres."}
        </Alert>
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflowX: "auto" }}>
          <Table size="small" sx={{ minWidth: 1100 }}>
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell padding="checkbox">
                  <Checkbox
                    checked={allSelected}
                    indeterminate={selectedRows.length > 0 && !allSelected}
                    onChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.key)))}
                    inputProps={{ "aria-label": "Tout sélectionner" }}
                  />
                </TableCell>
                <TableCell>Date</TableCell>
                <TableCell>Site</TableCell>
                <TableCell>Chirurgien</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Prestation</TableCell>
                <TableCell>Référence</TableCell>
                <TableCell>Firme</TableCell>
                <TableCell align="right">Qté</TableCell>
                <TableCell>Facturation</TableCell>
                <TableCell>Motif</TableCell>
                <TableCell align="right">Montant</TableCell>
                <TableCell>Facture</TableCell>
                <TableCell padding="checkbox" />
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.key} hover selected={selected.has(row.key)} data-testid={`row-${row.key}`}>
                  <TableCell padding="checkbox">
                    <Checkbox
                      checked={selected.has(row.key)}
                      onChange={() => toggle(row.key)}
                      inputProps={{ "aria-label": `Sélectionner ${row.label ?? "la ligne"} du ${formatDate(row.mission.date)}` }}
                    />
                  </TableCell>
                  <TableCell sx={{ whiteSpace: "nowrap" }}>{formatDate(row.mission.date)}</TableCell>
                  <TableCell>{row.mission.site ?? "—"}</TableCell>
                  <TableCell>{row.mission.surgeon ?? "—"}</TableCell>
                  <TableCell>{row.sourceType === "MATERIAL" ? "Matériel" : "Intervention"}</TableCell>
                  <TableCell sx={{ fontWeight: 600 }}>{row.label ?? "—"}</TableCell>
                  <TableCell sx={{ color: "text.secondary" }}>{row.reference ?? ""}</TableCell>
                  <TableCell>{row.firm?.name ?? <Typography component="span" variant="body2" color="warning.dark">Non renseignée</Typography>}</TableCell>
                  <TableCell align="right" sx={{ fontVariantNumeric: "tabular-nums" }}>{row.quantity ?? "—"}</TableCell>
                  <TableCell><BillingBadge row={row} /></TableCell>
                  <TableCell>
                    <Tooltip title={row.reasonDetail}>
                      <Typography variant="body2" component="span" color={row.billingStatus === "TO_REVIEW" ? "warning.dark" : "text.secondary"}>{row.reasonLabel}</Typography>
                    </Tooltip>
                  </TableCell>
                  <TableCell align="right" sx={{ fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap", fontWeight: row.billingStatus === "BILLABLE" ? 700 : 400 }}>
                    {row.amount !== null ? formatMoney(row.amount, row.currency ?? "EUR") : "—"}
                  </TableCell>
                  <TableCell sx={{ whiteSpace: "nowrap" }}>
                    <InvoiceStateCell row={row} />
                  </TableCell>
                  <TableCell padding="checkbox" sx={{ whiteSpace: "nowrap" }}>
                    {row.sourceKey && (
                      <Tooltip title="Historique de facturation">
                        <IconButton size="small" onClick={() => setHistoryOf(row)} aria-label={`Historique de ${row.label ?? "la ligne"}`}><HistoryIcon fontSize="small" /></IconButton>
                      </Tooltip>
                    )}
                    <Tooltip title="Ouvrir la mission">
                      <IconButton size="small" component={RouterLink} to={`/app/m/missions/${row.mission.id}`} aria-label="Ouvrir la mission"><OpenInNewIcon fontSize="small" /></IconButton>
                    </Tooltip>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}
      <LineHistoryDrawer row={historyOf} onClose={() => setHistoryOf(null)} />
    </Stack>
  );
}

/** État documentaire COURANT de la ligne ; lien direct vers le document, ligne ciblée. */
function InvoiceStateCell({ row }: { row: WorklistRow }) {
  const inv = row.currentInvoice;
  if (!inv) {
    return (
      <Stack spacing={0.25} alignItems="flex-start">
        <Chip size="small" variant="outlined" label={row.invoiceStateLabel} />
        {row.hasHistory && <Typography variant="caption" color="text.secondary">déjà passée par une facture</Typography>}
      </Stack>
    );
  }
  const text = inv.editable
    ? `Brouillon · ${inv.firmName ?? ""} · #${inv.id}`
    : `${inv.number ?? `#${inv.id}`} · ${row.invoiceStateLabel}`;
  return (
    <Tooltip title="Ouvrir le document sur cette ligne" describeChild>
      <Chip
        size="small"
        clickable
        component={RouterLink}
        to={invoiceFocusUrl(inv.id, row.sourceKey)}
        color={INVOICE_STATE_COLORS[row.invoiceState]}
        label={text}
      />
    </Tooltip>
  );
}

/** Historique append-only d'une ligne (D-134) — jamais reconstitué côté client. */
function LineHistoryDrawer({ row, onClose }: { row: WorklistRow | null; onClose: () => void }) {
  const query = useQuery({
    queryKey: ["firm-billing-line-history", row?.sourceKey],
    queryFn: () => getFirmBillingLineHistory(row!.sourceKey!),
    enabled: !!row?.sourceKey,
  });
  const data = query.data;

  return (
    <Drawer anchor="right" open={row !== null} onClose={onClose} PaperProps={{ sx: { width: { xs: "100%", sm: 440 } } }}>
      {row && (
        <Stack spacing={2} sx={{ p: 2.5 }} role="region" aria-label="Historique de facturation">
          <Stack direction="row" alignItems="flex-start" spacing={1}>
            <Box sx={{ flex: 1 }}>
              <Typography variant="overline" color="text.secondary">Historique de facturation</Typography>
              <Typography variant="h6" fontWeight={800}>{row.label ?? "—"}</Typography>
              <Typography variant="body2" color="text.secondary">
                {formatDate(row.mission.date)} · {row.mission.site ?? "—"} · {row.firm?.name ?? "—"}
              </Typography>
            </Box>
            <IconButton onClick={onClose} aria-label="Fermer l'historique"><CloseIcon /></IconButton>
          </Stack>

          <Paper variant="outlined" sx={{ p: 1.5, borderRadius: 2 }}>
            <Typography variant="caption" color="text.secondary" fontWeight={700}>Aujourd'hui</Typography>
            <Box sx={{ mt: 0.5 }}><InvoiceStateCell row={row} /></Box>
          </Paper>

          {query.isLoading && <CircularProgress size={22} />}
          {query.isError && <Alert severity="error">{extractBillingError(query.error)}</Alert>}
          {data && data.history.length === 0 && (
            <Alert severity="info" variant="outlined">Cette ligne n'a encore jamais figuré sur une facture.</Alert>
          )}
          {data && data.history.length > 0 && (
            <Box component="ol" sx={{ listStyle: "none", m: 0, p: 0, borderLeft: 2, borderColor: "divider", ml: 1 }} aria-label="Chronologie">
              {data.history.map((e) => (
                <Box component="li" key={e.id} sx={{ position: "relative", pl: 2, pb: 2 }}>
                  <Box sx={{ position: "absolute", left: -7, top: 4, width: 12, height: 12, borderRadius: "50%", bgcolor: e.eventType === "INVOICE_CANCELLED" ? "grey.500" : "primary.main" }} />
                  <Typography variant="caption" color="text.secondary" sx={{ fontVariantNumeric: "tabular-nums" }}>
                    {new Date(e.occurredAt).toLocaleString("fr-BE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })}
                  </Typography>
                  <Typography variant="body2" fontWeight={700}>{e.description}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {[e.firmName, e.actorName].filter(Boolean).join(" · ")}
                  </Typography>
                  {e.invoice && (
                    <Box>
                      <Button size="small" component={RouterLink} to={invoiceFocusUrl(e.invoice.id, row.sourceKey)} sx={{ px: 0 }}>
                        Ouvrir {e.invoice.number ?? `#${e.invoice.id}`}
                      </Button>
                    </Box>
                  )}
                </Box>
              ))}
            </Box>
          )}
        </Stack>
      )}
    </Drawer>
  );
}

// ── À corriger ─────────────────────────────────────────────────────────────

function ToFixView({ worklist, onDone, onError }: { worklist: FirmBillingWorklist; onDone: (message: string) => Promise<void>; onError: (message: string) => void }) {
  const navigate = useNavigate();
  const { anomalies, bulkActions } = worklist;

  const calculate = useMutation({
    mutationFn: (missionIds: number[]) => runFirmBillingCalculations(missionIds),
    onSuccess: (r) => {
      const parts = [`${plural(r.calculated, "mission")} calculée${r.calculated > 1 ? "s" : ""}`];
      if (r.failed) parts.push(`${r.failed} encore en échec`);
      if (r.skipped) parts.push(`${r.skipped} ignorée${r.skipped > 1 ? "s" : ""}`);
      return onDone(`${parts.join(", ")}.`);
    },
    onError: (err) => onError(extractBillingError(err)),
  });
  const approve = useMutation({
    mutationFn: (calculationId: number) => approveFinancialCalculation(calculationId),
    onSuccess: () => onDone("Calcul approuvé — les lignes sont maintenant facturables."),
    onError: (err) => onError(extractBillingError(err)),
  });
  const busy = calculate.isPending || approve.isPending;

  function runAction(a: WorklistAnomaly) {
    if (!a.action) return;
    const route = ACTION_ROUTES[a.action.code];
    if (route) return navigate(route);
    if (a.action.code === "OPEN_MISSION") return navigate(`/app/m/missions/${a.mission.id}`);
    if (a.action.code === "APPROVE" && a.calculationId !== null) return approve.mutate(a.calculationId);
    if (a.action.code === "CALCULATE" || a.action.code === "RECALCULATE") return calculate.mutate([a.mission.id]);
  }

  const groups = React.useMemo(() => {
    const map = new Map<number, WorklistAnomaly[]>();
    for (const a of anomalies) map.set(a.mission.id, [...(map.get(a.mission.id) ?? []), a]);
    return [...map.values()];
  }, [anomalies]);

  if (anomalies.length === 0) {
    return <Alert severity="success">Rien à corriger sur cette période : chaque prestation validée est facturable, non facturable pour un motif connu, ou déjà facturée.</Alert>;
  }

  return (
    <Stack spacing={1.5}>
      <Alert severity="info" variant="outlined">
        Corrigez d'abord la cause (tarif, encodage), puis relancez le calcul : relancer sans corriger reproduit la même anomalie.
      </Alert>
      {(bulkActions.recalculateFixed.length > 0 || bulkActions.calculatePending.length > 0) && (
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
          {bulkActions.recalculateFixed.length > 0 && (
            <Button variant="contained" disableElevation disabled={busy} onClick={() => calculate.mutate(bulkActions.recalculateFixed)}>
              Recalculer les éléments corrigés ({plural(bulkActions.recalculateFixed.length, "mission")})
            </Button>
          )}
          {bulkActions.calculatePending.length > 0 && (
            <Button variant="outlined" disabled={busy} onClick={() => calculate.mutate(bulkActions.calculatePending)}>
              Calculer les missions en attente ({bulkActions.calculatePending.length})
            </Button>
          )}
        </Stack>
      )}

      {groups.map((items) => {
        const m = items[0].mission;
        return (
          <Paper key={m.id} variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }} data-testid={`mission-${m.id}`}>
            <Stack direction="row" alignItems="center" spacing={1} sx={{ px: 2, py: 1, bgcolor: "grey.50" }}>
              <Typography fontWeight={800} sx={{ flex: 1 }}>{formatDate(m.date)} · {m.site ?? "—"} · {m.surgeon ?? "—"}</Typography>
              <Typography variant="caption" color="text.secondary">{plural(items.length, "anomalie")}</Typography>
              <Button size="small" component={RouterLink} to={`/app/m/missions/${m.id}`}>Ouvrir la mission</Button>
            </Stack>
            <Stack divider={<Box sx={{ borderTop: 1, borderColor: "divider" }} />}>
              {items.map((a) => (
                <Stack key={a.key} direction="row" spacing={2} alignItems="flex-start" sx={{ px: 2, py: 1.5 }} flexWrap="wrap" useFlexGap>
                  <Box sx={{ flex: 1, minWidth: 260 }}>
                    <Stack direction="row" spacing={1} alignItems="center">
                      <Typography fontWeight={700} color="warning.dark">{a.title}</Typography>
                      {a.resolved && <Chip size="small" color="success" variant="outlined" label="Corrigé — à recalculer" />}
                    </Stack>
                    {(a.element?.label || a.firm) && (
                      <Typography variant="body2" fontWeight={600}>
                        {[a.element?.label, a.element?.reference ? `Réf. ${a.element.reference}` : null, a.firm?.name].filter(Boolean).join(" · ")}
                      </Typography>
                    )}
                    <Typography variant="body2" color="text.secondary">{a.explanation}</Typography>
                  </Box>
                  {a.action && !(a.resolved && a.action.code.startsWith("CONFIGURE")) && (
                    <Button size="small" variant={["CALCULATE", "APPROVE", "RECALCULATE"].includes(a.action.code) ? "contained" : "outlined"} disableElevation disabled={busy} onClick={() => runAction(a)}>
                      {a.action.label}
                    </Button>
                  )}
                </Stack>
              ))}
            </Stack>
          </Paper>
        );
      })}
    </Stack>
  );
}

// ── Factures ───────────────────────────────────────────────────────────────

function InvoicesView({ from, to, firmIds, counts, onMarkPaid, onError }: {
  from: string;
  to: string;
  firmIds: number[];
  counts?: { generated: number; sent: number; paid: number; cancelled: number };
  onMarkPaid: () => Promise<void>;
  onError: (message: string) => void;
}) {
  const navigate = useNavigate();
  const [statusFilter, setStatusFilter] = React.useState<InvoiceStatus | "">("");
  const query: UseQueryResult<FirmInvoice[]> = useQuery({
    queryKey: ["firm-invoices", "period", from, to, firmIds, statusFilter],
    queryFn: () => getFirmInvoices({ from, to, firmIds: firmIds.length ? firmIds : undefined, status: statusFilter || undefined, documentType: "STANDARD" }),
  });
  const markPaid = useMutation({
    mutationFn: markFirmInvoicePaid,
    onSuccess: () => onMarkPaid(),
    onError: (err) => onError(extractBillingError(err)),
  });

  return (
    <Stack spacing={1.5}>
      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
        {counts && (
          <>
            <Chip label={`${plural(counts.generated, "facture")} générée${counts.generated > 1 ? "s" : ""}`} color="info" variant="outlined" />
            <Chip label={`${counts.sent} envoyée${counts.sent > 1 ? "s" : ""}`} color="warning" variant="outlined" />
            <Chip label={`${counts.paid} payée${counts.paid > 1 ? "s" : ""}`} color="success" variant="outlined" />
            {counts.cancelled > 0 && <Chip label={`${counts.cancelled} annulée${counts.cancelled > 1 ? "s" : ""}`} variant="outlined" />}
          </>
        )}
        <Box sx={{ flex: 1 }} />
        <Select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as InvoiceStatus | "")}
          displayEmpty
          size="small"
          sx={{ width: 200 }}
          inputProps={{ "aria-label": "Filtrer par statut" }}
        >
          <MenuItem value="">Tous les statuts</MenuItem>
          {(["GENERATED", "SENT", "PAID", "CANCELLED"] as InvoiceStatus[]).map((s) => <MenuItem key={s} value={s}>{INVOICE_STATUS_LABELS[s]}</MenuItem>)}
        </Select>
      </Stack>

      {query.isLoading ? <CircularProgress size={24} /> : query.isError ? <Alert severity="error">{extractBillingError(query.error)}</Alert> : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflowX: "auto" }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell>N°</TableCell>
                <TableCell>Firme</TableCell>
                <TableCell>Période</TableCell>
                <TableCell align="right">Lignes</TableCell>
                <TableCell align="right">Montant</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell>Générée</TableCell>
                <TableCell>Envoyée</TableCell>
                <TableCell>Payée</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(query.data ?? []).length === 0 && (
                <TableRow><TableCell colSpan={10} align="center" sx={{ py: 4, color: "text.secondary" }}>Aucune facture sur cette période</TableCell></TableRow>
              )}
              {(query.data ?? []).map((inv) => (
                <TableRow key={inv.id} hover>
                  <TableCell>
                    <Typography component={RouterLink} to={`/app/m/billing/firm-invoices/${inv.id}`} variant="body2" fontWeight={700} color="primary" sx={{ textDecoration: "none" }}>
                      {inv.number ?? `#${inv.id}`}
                    </Typography>
                  </TableCell>
                  <TableCell>{inv.firm.name}</TableCell>
                  <TableCell>{formatDate(inv.periodStart)} → {formatDate(inv.periodEnd)}</TableCell>
                  <TableCell align="right">{inv.lineCount ?? "—"}</TableCell>
                  <TableCell align="right"><strong>{formatMoney(inv.totalAmount, inv.currency)}</strong></TableCell>
                  <TableCell><Chip size="small" label={INVOICE_STATUS_LABELS[inv.status]} color={INVOICE_STATUS_COLORS[inv.status]} /></TableCell>
                  <TableCell>{formatDate(inv.generatedAt)}</TableCell>
                  <TableCell>{formatDate(inv.sentAt)}</TableCell>
                  <TableCell>{formatDate(inv.paidAt)}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Button size="small" variant="outlined" startIcon={<PictureAsPdfIcon />} href={getFirmInvoicePdfUrl(inv.id)} target="_blank">PDF</Button>
                      <Button size="small" onClick={() => navigate(`/app/m/billing/firm-invoices/${inv.id}`)}>Détail</Button>
                      {inv.allowedActions?.includes("markPaid") && (
                        <Button size="small" color="success" disabled={markPaid.isPending} onClick={() => markPaid.mutate(inv.id)}>Marquer comme payée</Button>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}
    </Stack>
  );
}
