import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Collapse,
  Divider,
  IconButton,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import CalculateIcon from "@mui/icons-material/Calculate";
import KeyboardArrowDownIcon from "@mui/icons-material/KeyboardArrowDown";
import KeyboardArrowUpIcon from "@mui/icons-material/KeyboardArrowUp";
import WarningAmberIcon from "@mui/icons-material/WarningAmber";
import type { FinancialCalculationLineDetail } from "../api/financialCalculation.api";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  approveFinancialCalculation,
  calculateMission,
  lockFinancialCalculation,
  listMissionCalculations,
  recalculateFinancialCalculation,
  type FinancialCalculationStatus,
} from "../api/financialCalculation.api";
import { useToast } from "../../../ui/toast/useToast";

const STATUS_LABELS: Record<FinancialCalculationStatus, string> = {
  CALCULATED: "Calculé (en attente d'approbation)",
  APPROVED: "Approuvé",
  LOCKED: "Verrouillé",
  SUPERSEDED: "Remplacé par une version plus récente",
  CANCELLED: "Annulé",
};

const STATUS_COLORS: Record<FinancialCalculationStatus, "default" | "info" | "success" | "warning" | "error"> = {
  CALCULATED: "info",
  APPROVED: "warning",
  LOCKED: "success",
  SUPERSEDED: "default",
  CANCELLED: "error",
};

const LINE_TYPE_LABELS: Record<string, string> = {
  FIRM_INTERVENTION_FEE: "Intervention (firme)",
  FIRM_MATERIAL_FEE: "Matériel (firme)",
  INSTRUMENTIST_HOURLY: "Horaire (instrumentiste)",
  INSTRUMENTIST_CONSULTATION_FEE: "Consultation (instrumentiste)",
};

/**
 * Refonte Catalogue/Prestations (D-092) — détail du calcul par ligne, dépliable.
 * Distingue toujours brut/ajustement/final, jamais un total opaque, et affiche la
 * raison exacte de l'ajustement quand une politique délégué s'est appliquée.
 */
function LineDetailRow({ line }: { line: FinancialCalculationLineDetail }) {
  const snapshot = line.snapshot;
  const hasAdjustment = Number(line.adjustmentAmount) !== 0;
  const representativePresent = snapshot.representativePresentSnapshot;
  const adjustmentReason = snapshot.adjustmentReasonSnapshot;

  return (
    <Box sx={{ py: 1.5, px: 2, bgcolor: "grey.50", borderRadius: 1 }}>
      <Stack spacing={0.5}>
        <Stack direction="row" justifyContent="space-between">
          <Typography variant="caption" color="text.secondary">Montant brut</Typography>
          <Typography variant="caption" fontWeight={600}>{Number(line.grossAmount).toFixed(2)} {line.currency}</Typography>
        </Stack>
        {representativePresent !== undefined && representativePresent !== null && (
          <Stack direction="row" justifyContent="space-between">
            <Typography variant="caption" color="text.secondary">Délégué présent</Typography>
            <Typography variant="caption">{representativePresent ? "Oui" : "Non"}</Typography>
          </Stack>
        )}
        {hasAdjustment && (
          <>
            <Stack direction="row" justifyContent="space-between">
              <Typography variant="caption" color="text.secondary">Règle appliquée</Typography>
              <Typography variant="caption" sx={{ maxWidth: "60%", textAlign: "right" }}>{adjustmentReason ?? "—"}</Typography>
            </Stack>
            <Stack direction="row" justifyContent="space-between">
              <Typography variant="caption" color="text.secondary">Ajustement</Typography>
              <Typography variant="caption" color="error.main" fontWeight={600}>{Number(line.adjustmentAmount).toFixed(2)} {line.currency}</Typography>
            </Stack>
          </>
        )}
        <Divider sx={{ my: 0.5 }} />
        <Stack direction="row" justifyContent="space-between">
          <Typography variant="caption" fontWeight={700}>Montant final</Typography>
          <Typography variant="caption" fontWeight={700}>{Number(line.totalAmount).toFixed(2)} {line.currency}</Typography>
        </Stack>
        {line.warnings.length > 0 && (
          <Stack spacing={0.5} sx={{ mt: 1 }}>
            {line.warnings.map((w, i) => (
              <Alert key={i} severity="warning" variant="outlined" icon={<WarningAmberIcon fontSize="small" />} sx={{ py: 0, fontSize: ".75rem" }}>
                {w.message}
              </Alert>
            ))}
          </Stack>
        )}
      </Stack>
    </Box>
  );
}

function extractError(err: unknown): string {
  const e = err as any;
  const violations = e?.response?.data?.error?.violations;
  if (Array.isArray(violations) && violations.length > 0) {
    return violations.map((v: any) => v.message ?? JSON.stringify(v)).join(" · ");
  }
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

interface Props {
  missionId: number;
}

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — le calcul financier est le maillon
 * central de tout l'EPIC : Mission/MissionExecution ⟶ [CE CALCUL] ⟶ FirmInvoice/
 * InstrumentistStatement ⟶ Payment ⟶ Statistiques. Tant qu'aucun calcul n'est
 * APPROVED/LOCKED pour une mission, aucune de ses lignes n'apparaît dans le nouveau
 * flux de facturation (Lot 4) ni dans les statistiques de valeur générée (Lot 7).
 */
export default function FinancialCalculationCard({ missionId }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [showHistory, setShowHistory] = React.useState(false);
  const [expandedLineIds, setExpandedLineIds] = React.useState<Set<number>>(new Set());

  function toggleLine(id: number) {
    setExpandedLineIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  const query = useQuery({
    queryKey: ["mission-financial-calculations", missionId],
    queryFn: () => listMissionCalculations(missionId),
    enabled: Number.isFinite(missionId),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ["mission-financial-calculations", missionId] });

  const calculateMutation = useMutation({
    mutationFn: () => calculateMission(missionId),
    onSuccess: () => { toast.success("Calcul financier créé"); invalidate(); },
    onError: (err) => toast.error(extractError(err)),
  });

  const recalculateMutation = useMutation({
    mutationFn: (id: number) => recalculateFinancialCalculation(id),
    onSuccess: () => { toast.success("Nouvelle version calculée"); invalidate(); },
    onError: (err) => toast.error(extractError(err)),
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => approveFinancialCalculation(id),
    onSuccess: () => { toast.success("Calcul approuvé"); invalidate(); },
    onError: (err) => toast.error(extractError(err)),
  });

  const lockMutation = useMutation({
    mutationFn: (id: number) => lockFinancialCalculation(id),
    onSuccess: () => { toast.success("Calcul verrouillé — ses lignes sont maintenant disponibles pour la facturation"); invalidate(); },
    onError: (err) => toast.error(extractError(err)),
  });

  if (query.isLoading) return <CircularProgress size={20} />;

  const calculations = query.data ?? [];
  const active = calculations.find((c) => c.status === "CALCULATED" || c.status === "APPROVED" || c.status === "LOCKED");
  const history = calculations.filter((c) => c.id !== active?.id).sort((a, b) => b.version - a.version);

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
      <Stack direction="row" spacing={1} alignItems="center" mb={1}>
        <CalculateIcon fontSize="small" color="action" />
        <Typography variant="subtitle2" fontWeight={600} sx={{ flex: 1 }}>Calcul financier (valorisation)</Typography>
        {active && <Chip size="small" label={STATUS_LABELS[active.status]} color={STATUS_COLORS[active.status]} />}
      </Stack>
      <Divider sx={{ mb: 1.5 }} />

      <Alert severity="info" variant="outlined" sx={{ mb: 1.5 }}>
        Ce calcul fige les tarifs applicables à la mission (firme et instrumentiste) à la date d'exécution.
        Il doit être <strong>approuvé</strong> puis <strong>verrouillé</strong> pour que ses lignes deviennent sélectionnables
        lors de la génération d'une facture firme ou d'un décompte instrumentiste, et pour qu'elles comptent dans les
        statistiques de chiffre d'affaires généré.
      </Alert>

      {!active && (
        <Stack spacing={1}>
          <Typography variant="body2" color="text.secondary">
            Aucun calcul actif pour cette mission. Le calcul nécessite une mission <strong>validée</strong> avec un
            instrumentiste assigné.
          </Typography>
          <Button
            variant="contained" disableElevation size="small" sx={{ alignSelf: "flex-start" }}
            onClick={() => calculateMutation.mutate()}
            disabled={calculateMutation.isPending}
          >
            {calculateMutation.isPending ? <CircularProgress size={16} /> : "Calculer la valorisation"}
          </Button>
        </Stack>
      )}

      {active && (
        <Stack spacing={1.5}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell />
                <TableCell>Bénéficiaire</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Description</TableCell>
                <TableCell align="right">Qté</TableCell>
                <TableCell align="right">P.U.</TableCell>
                <TableCell align="right">Total</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {active.lines.map((l) => {
                const expanded = expandedLineIds.has(l.id);
                const hasAdjustment = Number(l.adjustmentAmount) !== 0;
                return (
                  <React.Fragment key={l.id}>
                    <TableRow hover sx={{ cursor: "pointer" }} onClick={() => toggleLine(l.id)}>
                      <TableCell sx={{ width: 32, p: 0.5 }}>
                        <IconButton size="small">
                          {expanded ? <KeyboardArrowUpIcon fontSize="small" /> : <KeyboardArrowDownIcon fontSize="small" />}
                        </IconButton>
                      </TableCell>
                      <TableCell>{l.beneficiaryType === "FIRM" ? "Firme" : "Instrumentiste"}</TableCell>
                      <TableCell>{LINE_TYPE_LABELS[l.lineType] ?? l.lineType}</TableCell>
                      <TableCell>
                        {l.descriptionSnapshot}
                        {hasAdjustment && <Chip size="small" label="Ajustée" color="info" variant="outlined" sx={{ ml: 1, height: 18, fontSize: ".65rem" }} />}
                        {l.warnings.length > 0 && <WarningAmberIcon fontSize="small" color="warning" sx={{ ml: 0.5, verticalAlign: "middle" }} />}
                      </TableCell>
                      <TableCell align="right">{l.quantity}</TableCell>
                      <TableCell align="right">{Number(l.unitAmount).toFixed(2)} {l.currency}</TableCell>
                      <TableCell align="right"><strong>{Number(l.totalAmount).toFixed(2)} {l.currency}</strong></TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell colSpan={7} sx={{ py: 0, border: expanded ? undefined : "none" }}>
                        <Collapse in={expanded} unmountOnExit>
                          <Box sx={{ my: 1 }}>
                            <LineDetailRow line={l} />
                          </Box>
                        </Collapse>
                      </TableCell>
                    </TableRow>
                  </React.Fragment>
                );
              })}
            </TableBody>
          </Table>

          <Box>
            {Object.entries(active.totalsByCurrency).map(([currency, byBeneficiary]) => (
              <Typography key={currency} variant="body2">
                <strong>{currency}</strong> — Firme : {Number(byBeneficiary.FIRM ?? 0).toFixed(2)} · Instrumentiste : {Number(byBeneficiary.INSTRUMENTIST ?? 0).toFixed(2)}
              </Typography>
            ))}
          </Box>

          <Stack direction="row" spacing={1} flexWrap="wrap">
            {active.status === "CALCULATED" && (
              <Button size="small" variant="contained" disableElevation onClick={() => approveMutation.mutate(active.id)} disabled={approveMutation.isPending}>
                {approveMutation.isPending ? <CircularProgress size={16} /> : "Approuver"}
              </Button>
            )}
            {active.status === "APPROVED" && (
              <Button size="small" variant="contained" disableElevation color="success" onClick={() => lockMutation.mutate(active.id)} disabled={lockMutation.isPending}>
                {lockMutation.isPending ? <CircularProgress size={16} /> : "Verrouiller"}
              </Button>
            )}
            {(active.status === "CALCULATED" || active.status === "APPROVED") && (
              <Button size="small" variant="outlined" onClick={() => recalculateMutation.mutate(active.id)} disabled={recalculateMutation.isPending}>
                {recalculateMutation.isPending ? <CircularProgress size={16} /> : "Recalculer (nouvelle version)"}
              </Button>
            )}
          </Stack>

          {active.status === "LOCKED" && (
            <Typography variant="caption" color="success.main">
              Verrouillé — ce calcul ne peut plus être recalculé. Ses lignes libres sont disponibles pour la facturation.
            </Typography>
          )}
        </Stack>
      )}

      {history.length > 0 && (
        <Box mt={1.5}>
          <Button size="small" onClick={() => setShowHistory((s) => !s)}>
            {showHistory ? "Masquer" : "Voir"} l'historique ({history.length} version{history.length > 1 ? "s" : ""} précédente{history.length > 1 ? "s" : ""})
          </Button>
          {showHistory && (
            <Stack spacing={0.5} mt={1}>
              {history.map((c) => (
                <Stack key={c.id} direction="row" spacing={1} alignItems="center">
                  <Chip size="small" label={`v${c.version}`} variant="outlined" />
                  <Chip size="small" label={STATUS_LABELS[c.status]} color={STATUS_COLORS[c.status]} variant="outlined" />
                  <Typography variant="caption" color="text.secondary">
                    {c.effectiveAt} — {c.lines.length} ligne(s)
                  </Typography>
                </Stack>
              ))}
            </Stack>
          )}
        </Box>
      )}
    </Paper>
  );
}
