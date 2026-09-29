import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Checkbox,
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
  Tooltip,
  Typography,
} from "@mui/material";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import LockOutlinedIcon from "@mui/icons-material/LockOutlined";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
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
  extractBillingError,
  getFirmBillingCockpit,
  type CockpitLine,
  type InvoicedLine,
  type ToInvoiceGroup,
  type ToVerifyItem,
} from "../../../features/billing-firm/api/firmBillingCockpit.api";
import { approveFinancialCalculation, calculateMission } from "../../../features/financial-calculation/api/financialCalculation.api";

const STATUS_COLORS: Record<InvoiceStatus, "default" | "info" | "warning" | "success" | "error"> = {
  DRAFT: "default", GENERATED: "info", SENT: "warning", PAID: "success", CANCELLED: "error",
};
const STATUS_LABELS: Record<InvoiceStatus, string> = {
  DRAFT: "Brouillon", GENERATED: "Générée", SENT: "Envoyée", PAID: "Payée", CANCELLED: "Annulée",
};

type TabKey = "toInvoice" | "toVerify" | "invoices" | "invoiced";

/** Mois civil courant → bornes AAAA-MM-JJ (dates métier, jamais toISOString : voir D-123). */
function monthBounds(year: number, month: number): { from: string; to: string } {
  const pad = (n: number) => String(n).padStart(2, "0");
  const lastDay = new Date(year, month, 0).getDate();
  return { from: `${year}-${pad(month)}-01`, to: `${year}-${pad(month)}-${pad(lastDay)}` };
}

function formatMoney(amount: string | number | null | undefined, currency = "EUR"): string {
  const n = Number(amount ?? 0);
  return `${n.toLocaleString("fr-BE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency === "EUR" ? "€" : currency}`;
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const [y, m, d] = iso.slice(0, 10).split("-");
  return `${d}/${m}/${y}`;
}

function missionLabel(m: { date: string | null; site: string | null; surgeon: string | null }): string {
  return [formatDate(m.date), m.site ?? "—", m.surgeon ?? "—"].join(" · ");
}

function prestationLabel(line: CockpitLine): string {
  if (line.material) return [line.material.label, line.material.referenceCode].filter(Boolean).join(" · ");
  return line.intervention?.label ?? line.description;
}

/**
 * D-123 — cockpit « Facturation firmes ». Toutes les données (catégories, raisons,
 * montants, actions permises) viennent de GET /api/firm-invoices/cockpit et
 * GET /api/firm-invoices : aucun calcul métier ici. La génération envoie exactement les
 * identifiants de FinancialCalculationLine affichés, et n'attend pas d'update optimiste.
 */
export default function FirmInvoicesPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();

  const now = new Date();
  const [year, setYear] = React.useState(now.getFullYear());
  const [month, setMonth] = React.useState(now.getMonth() + 1);
  const [firmId, setFirmId] = React.useState<number | "">("");
  const [statusFilter, setStatusFilter] = React.useState<InvoiceStatus | "">("");
  const [tab, setTab] = React.useState<TabKey>("toInvoice");
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

  const cockpitQuery = useQuery({
    queryKey: ["firm-billing-cockpit", from, to, firmId],
    queryFn: () => getFirmBillingCockpit({ from, to, firmId: firmId || undefined }),
  });

  const invoicesQuery = useQuery({
    queryKey: ["firm-invoices", "period", from, to, firmId, statusFilter],
    queryFn: () => getFirmInvoices({ from, to, firmId: firmId || undefined, status: statusFilter || undefined, documentType: "STANDARD" }),
  });

  async function refreshAll() {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ["firm-billing-cockpit"] }),
      qc.invalidateQueries({ queryKey: ["firm-invoices"] }),
    ]);
  }

  const cockpit = cockpitQuery.data;
  const kpis = cockpit?.kpis;

  return (
    <Stack spacing={3}>
      <PageHeader
        icon={ReceiptLongOutlinedIcon}
        title="Facturation firmes"
        subtitle="Ce qui est à facturer, ce qui l'est déjà, et ce qui bloque — ligne par ligne, depuis les calculs financiers."
      />

      <Stack direction="row" spacing={2} alignItems="center" flexWrap="wrap" useFlexGap>
        <Stack direction="row" alignItems="center" spacing={0.5}>
          <IconButton aria-label="Mois précédent" onClick={() => shiftMonth(-1)} size="small"><ChevronLeftIcon /></IconButton>
          <Typography fontWeight={700} sx={{ minWidth: 150, textAlign: "center", textTransform: "capitalize" }}>{periodLabel}</Typography>
          <IconButton aria-label="Mois suivant" onClick={() => shiftMonth(1)} size="small"><ChevronRightIcon /></IconButton>
        </Stack>
        <Select
          value={firmId}
          onChange={(e) => { const v = e.target.value as number | ""; setFirmId(v === "" ? "" : Number(v)); }}
          displayEmpty
          size="small"
          sx={{ minWidth: 200 }}
          inputProps={{ "aria-label": "Filtrer par firme" }}
        >
          <MenuItem value="">Toutes les firmes</MenuItem>
          {(firmsQuery.data ?? []).map((f) => <MenuItem key={f.id} value={f.id}>{f.name}</MenuItem>)}
        </Select>
      </Stack>

      {/* KPI — valeurs backend telles quelles */}
      <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(6, 1fr)" } }}>
        <Kpi label="À facturer" value={kpis ? `${kpis.toInvoiceLineCount} ligne${kpis.toInvoiceLineCount > 1 ? "s" : ""}` : "—"} onClick={() => setTab("toInvoice")} />
        <Kpi
          label="Montant à facturer"
          value={kpis ? (kpis.toInvoiceAmounts.length ? kpis.toInvoiceAmounts.map((a) => formatMoney(a.amount, a.currency)).join(" + ") : formatMoney(0)) : "—"}
          onClick={() => setTab("toInvoice")}
        />
        <Kpi label="Factures générées" value={kpis?.invoices.generated ?? "—"} hint="Générées, pas encore envoyées" onClick={() => setTab("invoices")} />
        <Kpi label="Envoyées" value={kpis?.invoices.sent ?? "—"} onClick={() => setTab("invoices")} />
        <Kpi label="Payées" value={kpis?.invoices.paid ?? "—"} onClick={() => setTab("invoices")} />
        <Kpi label="À vérifier" value={kpis?.toVerifyCount ?? "—"} warn={(kpis?.toVerifyCount ?? 0) > 0} onClick={() => setTab("toVerify")} />
      </Box>

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
        <Tab value="toInvoice" label={`À facturer${kpis ? ` (${kpis.toInvoiceLineCount})` : ""}`} />
        <Tab value="toVerify" label={`À vérifier${kpis ? ` (${kpis.toVerifyCount})` : ""}`} />
        <Tab value="invoices" label="Factures" />
        <Tab value="invoiced" label={`Lignes facturées${kpis ? ` (${kpis.invoicedLineCount})` : ""}`} />
      </Tabs>

      {cockpitQuery.isError && <Alert severity="error">{extractBillingError(cockpitQuery.error)}</Alert>}
      {cockpitQuery.isLoading && <CircularProgress size={24} />}

      {cockpit && tab === "toInvoice" && (
        <ToInvoiceView
          groups={cockpit.toInvoice}
          period={{ from, to }}
          onCreated={async (invoice) => {
            toast.success(`Facture ${invoice.number} générée — ${invoice.lineCount ?? invoice.lines?.length ?? 0} ligne(s), ${formatMoney(invoice.totalAmount, invoice.currency)}.`);
            setLastCreated(invoice);
            await refreshAll();
          }}
          onError={(msg) => toast.error(msg)}
          toVerifyCount={cockpit.kpis.toVerifyCount}
          onShowToVerify={() => setTab("toVerify")}
        />
      )}

      {cockpit && tab === "toVerify" && (
        <ToVerifyView
          items={cockpit.toVerify}
          onDone={async (msg) => { toast.success(msg); await refreshAll(); }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {tab === "invoices" && (
        <InvoicesView
          query={invoicesQuery}
          statusFilter={statusFilter}
          onStatusFilter={setStatusFilter}
          onMarkPaid={async () => { toast.success("Facture marquée payée."); await refreshAll(); }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {cockpit && tab === "invoiced" && <InvoicedView lines={cockpit.invoiced} />}
    </Stack>
  );
}

function Kpi({ label, value, hint, warn, onClick }: { label: string; value: React.ReactNode; hint?: string; warn?: boolean; onClick?: () => void }) {
  return (
    <Paper
      variant="outlined"
      component="button"
      type="button"
      onClick={onClick}
      title={hint}
      sx={{
        p: 1.5, borderRadius: 2, textAlign: "left", cursor: onClick ? "pointer" : "default", font: "inherit", background: "#fff",
        borderColor: warn ? "warning.main" : undefined, "&:hover": onClick ? { bgcolor: "grey.50" } : undefined,
      }}
    >
      <Typography variant="caption" color="text.secondary" fontWeight={700}>{label}</Typography>
      <Typography variant="h6" fontWeight={800} sx={{ fontVariantNumeric: "tabular-nums", color: warn ? "warning.dark" : undefined }}>{value}</Typography>
    </Paper>
  );
}

// ── À facturer ─────────────────────────────────────────────────────────────

function ToInvoiceView({ groups, period, onCreated, onError, toVerifyCount, onShowToVerify }: {
  groups: ToInvoiceGroup[];
  period: { from: string; to: string };
  onCreated: (invoice: FirmInvoice) => Promise<void>;
  onError: (message: string) => void;
  toVerifyCount: number;
  onShowToVerify: () => void;
}) {
  // Sélection limitée à UN groupe firme+devise : une facture = une firme (D-123).
  const [selection, setSelection] = React.useState<{ key: string; ids: number[] } | null>(null);
  const groupKey = (g: ToInvoiceGroup) => `${g.firm.id}|${g.currency}`;

  // Toute ligne sélectionnée qui n'est plus dans les données serveur est retirée — jamais
  // envoyée à la génération (la sélection ne peut porter que sur ce qui est affiché).
  React.useEffect(() => {
    if (!selection) return;
    const group = groups.find((g) => groupKey(g) === selection.key);
    const visible = new Set(group?.lines.map((l) => l.id) ?? []);
    const kept = selection.ids.filter((id) => visible.has(id));
    if (kept.length !== selection.ids.length) setSelection(kept.length ? { key: selection.key, ids: kept } : null);
  }, [groups]); // eslint-disable-line react-hooks/exhaustive-deps

  const mutation = useMutation({
    mutationFn: (group: ToInvoiceGroup) =>
      createFirmInvoiceFromCalculations({
        firmId: group.firm.id,
        currency: group.currency,
        periodStart: period.from,
        periodEnd: period.to,
        selectedFinancialCalculationLineIds: selection?.ids ?? [],
      }),
    onSuccess: async (invoice) => { setSelection(null); await onCreated(invoice); },
    onError: (err) => onError(extractBillingError(err)),
  });

  if (groups.length === 0) {
    return (
      <Alert severity="info" action={toVerifyCount > 0 ? <Button color="inherit" size="small" onClick={onShowToVerify}>Voir les {toVerifyCount} élément(s) à vérifier</Button> : undefined}>
        Aucune ligne à facturer sur cette période.
        {toVerifyCount > 0 ? " Des prestations existent mais ne sont pas encore facturables." : ""}
      </Alert>
    );
  }

  return (
    <Stack spacing={2}>
      {groups.map((group) => {
        const key = groupKey(group);
        const selectedHere = selection?.key === key ? selection.ids : [];
        const otherGroupSelected = selection !== null && selection.key !== key;
        const allSelected = selectedHere.length === group.lines.length;
        const selectedTotal = group.lines.filter((l) => selectedHere.includes(l.id)).reduce((s, l) => s + Number(l.totalAmount), 0);

        function toggle(id: number) {
          if (otherGroupSelected) return;
          const next = selectedHere.includes(id) ? selectedHere.filter((x) => x !== id) : [...selectedHere, id];
          setSelection(next.length ? { key, ids: next } : null);
        }

        return (
          <Paper key={key} variant="outlined" sx={{ borderRadius: 2, overflow: "hidden", opacity: otherGroupSelected ? 0.6 : 1 }}>
            <Stack direction="row" alignItems="center" spacing={1.5} sx={{ px: 2, py: 1.5, bgcolor: "grey.50", flexWrap: "wrap" }} useFlexGap>
              <Checkbox
                checked={allSelected && selectedHere.length > 0}
                indeterminate={selectedHere.length > 0 && !allSelected}
                disabled={otherGroupSelected}
                onChange={() => setSelection(allSelected ? null : { key, ids: group.lines.map((l) => l.id) })}
                inputProps={{ "aria-label": `Tout sélectionner pour ${group.firm.name}` }}
              />
              <Box sx={{ flex: 1, minWidth: 180 }}>
                <Typography fontWeight={800}>{group.firm.name}</Typography>
                <Typography variant="body2" color="text.secondary">
                  {group.lineCount} ligne{group.lineCount > 1 ? "s" : ""} · {formatMoney(group.totalAmount, group.currency)} à facturer
                </Typography>
              </Box>
              {otherGroupSelected && <Typography variant="caption" color="text.secondary">Une facture ne concerne qu'une firme : terminez d'abord la sélection en cours.</Typography>}
              <Button
                variant="contained"
                disableElevation
                disabled={selectedHere.length === 0 || mutation.isPending}
                onClick={() => mutation.mutate(group)}
              >
                {mutation.isPending && selection?.key === key
                  ? <CircularProgress size={16} />
                  : `Générer la facture — ${selectedHere.length} ligne${selectedHere.length > 1 ? "s" : ""} — ${formatMoney(selectedTotal, group.currency)}`}
              </Button>
            </Stack>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell padding="checkbox" />
                  <TableCell>Date · site · chirurgien</TableCell>
                  <TableCell>Prestation</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell align="right">Qté</TableCell>
                  <TableCell align="right">P.U.</TableCell>
                  <TableCell align="right">Montant</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {group.lines.map((line) => (
                  <TableRow key={line.id} hover selected={selectedHere.includes(line.id)} onClick={() => toggle(line.id)} sx={{ cursor: otherGroupSelected ? "default" : "pointer" }}>
                    <TableCell padding="checkbox">
                      <Checkbox checked={selectedHere.includes(line.id)} disabled={otherGroupSelected} inputProps={{ "aria-label": `Sélectionner la ligne ${line.id}` }} />
                    </TableCell>
                    <TableCell>
                      <Stack direction="row" spacing={1} alignItems="center">
                        <span>{missionLabel(line.mission)}</span>
                        {line.notice && (
                          <Tooltip title={line.notice}>
                            <Chip size="small" icon={<LockOutlinedIcon />} label="Calcul verrouillé" />
                          </Tooltip>
                        )}
                      </Stack>
                    </TableCell>
                    <TableCell>{prestationLabel(line)}</TableCell>
                    <TableCell>{line.lineTypeLabel}</TableCell>
                    <TableCell align="right">{Number(line.quantity)}</TableCell>
                    <TableCell align="right">{formatMoney(line.unitAmount, line.currency)}</TableCell>
                    <TableCell align="right"><strong>{formatMoney(line.totalAmount, line.currency)}</strong></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Paper>
        );
      })}
    </Stack>
  );
}

// ── À vérifier ─────────────────────────────────────────────────────────────

function ToVerifyView({ items, onDone, onError }: { items: ToVerifyItem[]; onDone: (message: string) => Promise<void>; onError: (message: string) => void }) {
  const calculate = useMutation({
    mutationFn: (missionId: number) => calculateMission(missionId),
    onSuccess: () => onDone("Calcul financier effectué — à approuver."),
    onError: (err) => onError(extractBillingError(err)),
  });
  const approve = useMutation({
    mutationFn: (calculationId: number) => approveFinancialCalculation(calculationId),
    onSuccess: () => onDone("Calcul approuvé — les lignes sont maintenant à facturer."),
    onError: (err) => onError(extractBillingError(err)),
  });

  if (items.length === 0) {
    return <Alert severity="success">Rien à vérifier sur cette période : toutes les prestations sont facturables ou déjà facturées.</Alert>;
  }

  const busy = calculate.isPending || approve.isPending;

  return (
    <Stack spacing={1.5}>
      {items.map((item) => (
        <Paper key={`${item.reason}-${item.mission.id}-${item.calculationId ?? "none"}`} variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
          <Stack direction="row" spacing={2} alignItems="flex-start" flexWrap="wrap" useFlexGap>
            <Box sx={{ flex: 1, minWidth: 240 }}>
              <Typography fontWeight={700}>
                {missionLabel(item.mission)} · {item.firms.map((f) => f.name).join(", ") || "—"}
                {item.totalAmount !== null && ` · ${formatMoney(item.totalAmount, item.currency ?? "EUR")}`}
              </Typography>
              <Typography variant="body2" color="warning.dark" fontWeight={700} sx={{ mt: 0.5 }}>{item.reasonLabel}</Typography>
              {item.anomalies.length > 0 && (
                <Box component="ul" sx={{ m: 0, mt: 0.5, pl: 2.5 }}>
                  {item.anomalies.map((a, i) => (
                    <Typography component="li" variant="body2" key={`${a.code}-${i}`}>{a.message}</Typography>
                  ))}
                </Box>
              )}
              {item.lines.length > 0 && (
                <Typography variant="caption" color="text.secondary">
                  {item.lines.map((l) => `${prestationLabel(l)} (${formatMoney(l.totalAmount, l.currency)})`).join(" · ")}
                </Typography>
              )}
            </Box>
            <Stack direction="row" spacing={1} alignItems="center">
              <Button component={RouterLink} to={`/app/m/missions/${item.mission.id}`} size="small">Voir la mission</Button>
              {item.allowedActions.includes("calculate") && (
                <Button variant="contained" disableElevation size="small" disabled={busy} onClick={() => calculate.mutate(item.mission.id)}>
                  {item.reason === "CALCULATION_FAILED" ? "Relancer le calcul" : "Calculer"}
                </Button>
              )}
              {item.allowedActions.includes("approve") && item.calculationId !== null && (
                <Button variant="contained" disableElevation size="small" color="success" disabled={busy} onClick={() => approve.mutate(item.calculationId!)}>
                  Approuver
                </Button>
              )}
            </Stack>
          </Stack>
        </Paper>
      ))}
    </Stack>
  );
}

// ── Factures ───────────────────────────────────────────────────────────────

function InvoicesView({ query, statusFilter, onStatusFilter, onMarkPaid, onError }: {
  query: UseQueryResult<FirmInvoice[]>;
  statusFilter: InvoiceStatus | "";
  onStatusFilter: (s: InvoiceStatus | "") => void;
  onMarkPaid: () => Promise<void>;
  onError: (message: string) => void;
}) {
  const navigate = useNavigate();
  const markPaid = useMutation({
    mutationFn: markFirmInvoicePaid,
    onSuccess: () => onMarkPaid(),
    onError: (err) => onError(extractBillingError(err)),
  });

  return (
    <Stack spacing={1.5}>
      <Select
        value={statusFilter}
        onChange={(e) => onStatusFilter(e.target.value as InvoiceStatus | "")}
        displayEmpty
        size="small"
        sx={{ width: 200 }}
        inputProps={{ "aria-label": "Filtrer par statut" }}
      >
        <MenuItem value="">Tous les statuts</MenuItem>
        {(["GENERATED", "SENT", "PAID", "CANCELLED"] as InvoiceStatus[]).map((s) => <MenuItem key={s} value={s}>{STATUS_LABELS[s]}</MenuItem>)}
      </Select>

      {query.isLoading ? <CircularProgress size={24} /> : query.isError ? <Alert severity="error">{extractBillingError(query.error)}</Alert> : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
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
                  <TableCell><Chip size="small" label={STATUS_LABELS[inv.status]} color={STATUS_COLORS[inv.status]} /></TableCell>
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

// ── Lignes facturées ───────────────────────────────────────────────────────

function InvoicedView({ lines }: { lines: InvoicedLine[] }) {
  if (lines.length === 0) {
    return <Alert severity="info">Aucune ligne facturée sur cette période.</Alert>;
  }
  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
      <Table size="small">
        <TableHead>
          <TableRow sx={{ bgcolor: "grey.50" }}>
            <TableCell>Date · site · chirurgien</TableCell>
            <TableCell>Firme</TableCell>
            <TableCell>Prestation</TableCell>
            <TableCell align="right">Montant facturé</TableCell>
            <TableCell>Facture</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {lines.map((line) => (
            <TableRow key={line.id}>
              <TableCell>{missionLabel(line.mission)}</TableCell>
              <TableCell>{line.firm.name}</TableCell>
              <TableCell>{prestationLabel(line)}</TableCell>
              <TableCell align="right">{formatMoney(line.invoice.invoicedAmount, line.currency)}</TableCell>
              <TableCell>
                <Typography component={RouterLink} to={`/app/m/billing/firm-invoices/${line.invoice.id}`} variant="body2" fontWeight={700} color="primary" sx={{ textDecoration: "none" }}>
                  {line.invoice.number ?? `#${line.invoice.id}`}
                </Typography>
                <Typography variant="caption" color="text.secondary" display="block">
                  {STATUS_LABELS[line.invoice.status as InvoiceStatus] ?? line.invoice.status} · générée le {formatDate(line.invoice.generatedAt)}
                </Typography>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Paper>
  );
}
