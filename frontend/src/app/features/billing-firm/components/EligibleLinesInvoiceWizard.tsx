import * as React from "react";
import {
  Alert,
  Button,
  CircularProgress,
  Divider,
  MenuItem,
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
import { apiClient } from "../../../api/apiClient";
import {
  createFirmInvoiceFromCalculations,
  getFirmEligibleLines,
  type EligibleCalculationLine,
  type FirmInvoice,
} from "../api/firmInvoice.api";
import { useToast } from "../../../ui/toast/useToast";

const MONTHS = [
  "Janvier","Février","Mars","Avril","Mai","Juin",
  "Juillet","Août","Septembre","Octobre","Novembre","Décembre",
];

const LINE_TYPE_LABELS: Record<string, string> = {
  FIRM_INTERVENTION_FEE: "Intervention",
  FIRM_MATERIAL_FEE: "Matériel",
};

function extractError(err: unknown): string {
  const e = err as any;
  const violations = e?.response?.data?.error?.violations;
  if (Array.isArray(violations) && violations.length > 0) {
    return violations.map((v: any) => v.message ?? JSON.stringify(v)).join(" · ");
  }
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

interface Props {
  onCreated: (invoice: FirmInvoice) => void;
  onCancel: () => void;
}

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — génère une facture firme à partir de
 * FinancialCalculationLine déjà verrouillées, jamais recalculées à la volée. Une ligne
 * n'apparaît ici que si sa mission a un calcul financier APPROVED/LOCKED (voir la carte
 * "Calcul financier" sur la fiche mission) — c'est le flux à privilégier : il garantit
 * que le montant facturé est exactement celui figé au moment du calcul, historisé et
 * jamais recalculable rétroactivement même si un tarif change ensuite.
 */
export default function EligibleLinesInvoiceWizard({ onCreated, onCancel }: Props) {
  const toast = useToast();
  const qc = useQueryClient();

  const [firmId, setFirmId] = React.useState("");
  const [currency, setCurrency] = React.useState("EUR");
  const [periodYear, setPeriodYear] = React.useState(new Date().getFullYear());
  const [periodMonth, setPeriodMonth] = React.useState(new Date().getMonth() + 1);
  const [preview, setPreview] = React.useState<Awaited<ReturnType<typeof getFirmEligibleLines>> | null>(null);
  const [selectedIds, setSelectedIds] = React.useState<number[]>([]);

  const firmsQuery = useQuery({
    queryKey: ["firms"],
    queryFn: async () => (await apiClient.get("/api/firms")).data as { id: number; name: string }[],
  });

  const previewMutation = useMutation({
    mutationFn: () => {
      const start = new Date(periodYear, periodMonth - 1, 1).toISOString();
      const end = new Date(periodYear, periodMonth, 0, 23, 59, 59).toISOString();
      return getFirmEligibleLines({ firmId: Number(firmId), currency, periodStart: start, periodEnd: end });
    },
    onSuccess: (data) => {
      setPreview(data);
      setSelectedIds(data.lines.map((l) => l.id));
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const createMutation = useMutation({
    mutationFn: () => {
      const start = new Date(periodYear, periodMonth - 1, 1).toISOString();
      const end = new Date(periodYear, periodMonth, 0, 23, 59, 59).toISOString();
      return createFirmInvoiceFromCalculations({
        firmId: Number(firmId), currency, periodStart: start, periodEnd: end,
        selectedFinancialCalculationLineIds: selectedIds,
      });
    },
    onSuccess: (invoice) => {
      toast.success("Facture générée depuis les calculs financiers");
      qc.invalidateQueries({ queryKey: ["firm-invoices"] });
      onCreated(invoice);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  function toggle(line: EligibleCalculationLine) {
    setSelectedIds((prev) => (prev.includes(line.id) ? prev.filter((x) => x !== line.id) : [...prev, line.id]));
  }

  return (
    <Stack spacing={2}>
      <Alert severity="info">
        Ce flux ne facture que les lignes issues d'un <strong>calcul financier verrouillé</strong> (voir la fiche de
        chaque mission). Le montant facturé est exactement celui figé au calcul — jamais recalculé, même si un tarif
        change ensuite. Si une mission attendue n'apparaît pas ci-dessous, son calcul n'a probablement pas encore été
        approuvé/verrouillé.
      </Alert>

      <Stack direction="row" spacing={2} alignItems="center" flexWrap="wrap" useFlexGap>
        <Select value={firmId} onChange={(e) => { setFirmId(e.target.value); setPreview(null); }} displayEmpty size="small" sx={{ minWidth: 200 }}>
          <MenuItem value="" disabled>Sélectionner une firme</MenuItem>
          {(firmsQuery.data ?? []).map((f) => <MenuItem key={f.id} value={f.id}>{f.name}</MenuItem>)}
        </Select>
        <Select value={periodMonth} onChange={(e) => { setPeriodMonth(Number(e.target.value)); setPreview(null); }} size="small">
          {MONTHS.map((m, i) => <MenuItem key={i + 1} value={i + 1}>{m}</MenuItem>)}
        </Select>
        <TextField type="number" value={periodYear} onChange={(e) => { setPeriodYear(Number(e.target.value)); setPreview(null); }} size="small" sx={{ width: 100 }} label="Année" />
        <TextField value={currency} onChange={(e) => { setCurrency(e.target.value.toUpperCase()); setPreview(null); }} size="small" sx={{ width: 90 }} label="Devise" />
        <Button variant="outlined" onClick={() => previewMutation.mutate()} disabled={!firmId || previewMutation.isPending}>
          {previewMutation.isPending ? <CircularProgress size={16} /> : "Prévisualiser"}
        </Button>
        <Button onClick={onCancel} color="inherit">Annuler</Button>
      </Stack>

      {preview && (
        <>
          <Divider />
          {preview.lines.length === 0 ? (
            <Typography color="text.secondary">
              Aucune ligne éligible — aucun calcul verrouillé avec lignes libres pour cette firme sur cette période.
            </Typography>
          ) : (
            <>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell padding="checkbox"></TableCell>
                    <TableCell>Mission</TableCell>
                    <TableCell>Type</TableCell>
                    <TableCell>Description</TableCell>
                    <TableCell align="right">Qté</TableCell>
                    <TableCell align="right">P.U.</TableCell>
                    <TableCell align="right">Total</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {preview.lines.map((line) => (
                    <TableRow
                      key={line.id} hover onClick={() => toggle(line)}
                      sx={{ cursor: "pointer", opacity: selectedIds.includes(line.id) ? 1 : 0.4 }}
                    >
                      <TableCell padding="checkbox"><input type="checkbox" checked={selectedIds.includes(line.id)} readOnly /></TableCell>
                      <TableCell>#{line.missionId}</TableCell>
                      <TableCell>{LINE_TYPE_LABELS[line.lineType] ?? line.lineType}</TableCell>
                      <TableCell>{line.descriptionSnapshot}</TableCell>
                      <TableCell align="right">{line.quantity}</TableCell>
                      <TableCell align="right">{Number(line.unitAmount).toFixed(2)} {line.currency}</TableCell>
                      <TableCell align="right"><strong>{Number(line.totalAmount).toFixed(2)} {line.currency}</strong></TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              <Stack direction="row" justifyContent="space-between" alignItems="center">
                <Typography variant="body2" color="text.secondary">{selectedIds.length} ligne(s) sélectionnée(s)</Typography>
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography variant="h6" fontWeight={700}>
                    Total : {preview.lines.filter((l) => selectedIds.includes(l.id)).reduce((acc, l) => acc + Number(l.totalAmount), 0).toFixed(2)} {currency}
                  </Typography>
                  <Button variant="contained" disableElevation onClick={() => createMutation.mutate()} disabled={createMutation.isPending || selectedIds.length === 0}>
                    {createMutation.isPending ? <CircularProgress size={16} /> : "Générer la facture"}
                  </Button>
                </Stack>
              </Stack>
            </>
          )}
        </>
      )}
    </Stack>
  );
}
