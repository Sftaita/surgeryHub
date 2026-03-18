import * as React from "react";
import {
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
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
import AddIcon from "@mui/icons-material/Add";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import {
  getStatements,
  previewStatement,
  generateStatement,
  markStatementPaid,
  getStatementPdfUrl,
  type InstrumentistStatement,
  type StatementPreviewLine,
} from "../../../features/billing-instrumentist/api/statement.api";
import type { InvoiceStatus } from "../../../features/billing-firm/api/firmInvoice.api";
import { useToast } from "../../../ui/toast/useToast";

const MONTHS = [
  "Janvier","Février","Mars","Avril","Mai","Juin",
  "Juillet","Août","Septembre","Octobre","Novembre","Décembre",
];

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

export default function InstrumentistStatementsPage() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();

  const [tutorialOpen, setTutorialOpen] = React.useState(false);
  const [showWizard, setShowWizard] = React.useState(false);
  const [instrumentistId, setInstrumentistId] = React.useState("");
  const [periodYear, setPeriodYear] = React.useState(new Date().getFullYear());
  const [periodMonth, setPeriodMonth] = React.useState(new Date().getMonth() + 1);
  const [preview, setPreview] = React.useState<Awaited<ReturnType<typeof previewStatement>> | null>(null);
  const [selectedMissionIds, setSelectedMissionIds] = React.useState<number[]>([]);

  const [filterStatus, setFilterStatus] = React.useState<InvoiceStatus | "">("");
  const [filterYear, setFilterYear] = React.useState<number | "">(new Date().getFullYear());

  const statementsQuery = useQuery({
    queryKey: ["instrumentist-statements", filterStatus, filterYear],
    queryFn: () => getStatements({ status: filterStatus || undefined, year: filterYear || undefined }),
  });

  const instrumentistsQuery = useQuery({
    queryKey: ["instrumentists-list"],
    queryFn: async () => {
      const { apiClient } = await import("../../../api/apiClient");
      const res = await apiClient.get("/api/instrumentists");
      return res.data.items as { id: number; displayName: string; email: string }[];
    },
  });

  const previewMutation = useMutation({
    mutationFn: () =>
      previewStatement({ instrumentistId: Number(instrumentistId), year: periodYear, month: periodMonth }),
    onSuccess: (data) => {
      setPreview(data);
      setSelectedMissionIds(data.lines.map((l) => l.missionId));
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const generateMutation = useMutation({
    mutationFn: () =>
      generateStatement({
        instrumentistId: Number(instrumentistId),
        year: periodYear,
        month: periodMonth,
        selectedMissionIds,
      }),
    onSuccess: () => {
      toast.success("Décompte généré");
      qc.invalidateQueries({ queryKey: ["instrumentist-statements"] });
      setShowWizard(false);
      setPreview(null);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const markPaidMutation = useMutation({
    mutationFn: markStatementPaid,
    onSuccess: () => { toast.success("Décompte marqué payé"); qc.invalidateQueries({ queryKey: ["instrumentist-statements"] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  function toggleMission(missionId: number) {
    setSelectedMissionIds((prev) =>
      prev.includes(missionId) ? prev.filter((x) => x !== missionId) : [...prev, missionId]
    );
  }

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Stack direction="row" alignItems="center" spacing={1}>
          <Typography variant="h6" fontWeight={700}>Décomptes Instrumentistes</Typography>
          <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
            <HelpOutlineIcon fontSize="small" />
          </IconButton>
        </Stack>
        <Button variant="contained" disableElevation startIcon={<AddIcon />} onClick={() => setShowWizard(true)}>
          Nouveau décompte
        </Button>
      </Stack>

      {/* ── Wizard ── */}
      {showWizard && (
        <Paper variant="outlined" sx={{ p: 3, borderRadius: 2 }}>
          <Typography variant="subtitle1" fontWeight={700} mb={2}>Générer un décompte</Typography>

          <Stack spacing={2}>
            <Stack direction="row" spacing={2} alignItems="center">
              <Select
                value={instrumentistId}
                onChange={(e) => { setInstrumentistId(e.target.value); setPreview(null); }}
                displayEmpty
                size="small"
                sx={{ minWidth: 220 }}
              >
                <MenuItem value="" disabled>Sélectionner un instrumentiste</MenuItem>
                {(instrumentistsQuery.data ?? []).map((i) => (
                  <MenuItem key={i.id} value={i.id}>{i.displayName || i.email}</MenuItem>
                ))}
              </Select>

              <Select value={periodMonth} onChange={(e) => { setPeriodMonth(Number(e.target.value)); setPreview(null); }} size="small">
                {MONTHS.map((m, i) => <MenuItem key={i + 1} value={i + 1}>{m}</MenuItem>)}
              </Select>

              <TextField
                type="number"
                value={periodYear}
                onChange={(e) => { setPeriodYear(Number(e.target.value)); setPreview(null); }}
                size="small"
                sx={{ width: 100 }}
                label="Année"
              />

              <Button
                variant="outlined"
                onClick={() => previewMutation.mutate()}
                disabled={!instrumentistId || previewMutation.isPending}
              >
                {previewMutation.isPending ? <CircularProgress size={16} /> : "Prévisualiser"}
              </Button>

              <Button onClick={() => { setShowWizard(false); setPreview(null); }} color="inherit">Annuler</Button>
            </Stack>

            {preview && (
              <>
                <Box sx={{ bgcolor: "grey.50", p: 1.5, borderRadius: 1 }}>
                  <Typography variant="body2">
                    <strong>{preview.instrumentist.displayName}</strong> —
                    Tarif horaire : {preview.instrumentist.hourlyRate ?? "—"} € —
                    Consultation : {preview.instrumentist.consultationFee ?? "—"} €
                  </Typography>
                </Box>

                {preview.lines.length === 0 ? (
                  <Typography color="text.secondary">Aucune prestation facturable pour ce mois.</Typography>
                ) : (
                  <>
                    <Table size="small">
                      <TableHead>
                        <TableRow>
                          <TableCell padding="checkbox"></TableCell>
                          <TableCell>Date</TableCell>
                          <TableCell>Type</TableCell>
                          <TableCell>Chirurgien</TableCell>
                          <TableCell>Site</TableCell>
                          <TableCell align="right">Durée</TableCell>
                          <TableCell align="right">Tarif</TableCell>
                          <TableCell align="right">Total</TableCell>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {preview.lines.map((line: StatementPreviewLine, idx) => {
                          const selected = selectedMissionIds.includes(line.missionId);
                          const alreadyBilled = preview.alreadyBilledMissionIds.includes(line.missionId);
                          return (
                            <TableRow
                              key={idx}
                              hover={!alreadyBilled}
                              onClick={() => !alreadyBilled && toggleMission(line.missionId)}
                              sx={{ cursor: alreadyBilled ? "default" : "pointer", opacity: selected ? 1 : 0.4 }}
                            >
                              <TableCell padding="checkbox">
                                <input type="checkbox" checked={selected} readOnly disabled={alreadyBilled} />
                              </TableCell>
                              <TableCell>{line.missionDate}</TableCell>
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
                                  : "1 consult."}
                              </TableCell>
                              <TableCell align="right">{Number(line.rateSnapshot).toFixed(2)} €</TableCell>
                              <TableCell align="right"><strong>{Number(line.totalAmount).toFixed(2)} €</strong></TableCell>
                            </TableRow>
                          );
                        })}
                      </TableBody>
                    </Table>

                    <Stack direction="row" justifyContent="space-between" alignItems="center">
                      <Typography variant="body2" color="text.secondary">
                        {selectedMissionIds.length} mission(s) sélectionnée(s)
                      </Typography>
                      <Stack direction="row" spacing={1} alignItems="center">
                        <Typography variant="h6" fontWeight={700}>
                          Total : {preview.lines
                            .filter((l) => selectedMissionIds.includes(l.missionId))
                            .reduce((acc, l) => acc + l.totalAmount, 0)
                            .toFixed(2)} €
                        </Typography>
                        <Button
                          variant="contained"
                          disableElevation
                          onClick={() => generateMutation.mutate()}
                          disabled={generateMutation.isPending || selectedMissionIds.length === 0}
                        >
                          {generateMutation.isPending ? <CircularProgress size={16} /> : "Générer le décompte"}
                        </Button>
                      </Stack>
                    </Stack>
                  </>
                )}
              </>
            )}
          </Stack>
        </Paper>
      )}

      {/* ── Filters ── */}
      <Stack direction="row" spacing={2}>
        <Select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value as InvoiceStatus | "")} displayEmpty size="small" sx={{ minWidth: 150 }}>
          <MenuItem value="">Tous les statuts</MenuItem>
          {(["GENERATED", "SENT", "PAID"] as InvoiceStatus[]).map((s) => <MenuItem key={s} value={s}>{statusLabel(s)}</MenuItem>)}
        </Select>
        <TextField type="number" label="Année" value={filterYear} onChange={(e) => setFilterYear(e.target.value ? Number(e.target.value) : "")} size="small" sx={{ width: 100 }} />
      </Stack>

      {/* ── List ── */}
      {statementsQuery.isLoading ? (
        <CircularProgress size={24} />
      ) : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell>Instrumentiste</TableCell>
                <TableCell>Période</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right">Montant</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(statementsQuery.data ?? []).length === 0 && (
                <TableRow>
                  <TableCell colSpan={5} align="center" sx={{ py: 4, color: "text.secondary" }}>Aucun décompte</TableCell>
                </TableRow>
              )}
              {(statementsQuery.data ?? []).map((stmt: InstrumentistStatement) => (
                <TableRow key={stmt.id} hover>
                  <TableCell>{stmt.instrumentist.displayName ?? stmt.instrumentist.email}</TableCell>
                  <TableCell>{String(stmt.periodMonth).padStart(2, "0")}/{stmt.periodYear}</TableCell>
                  <TableCell>
                    <Chip label={statusLabel(stmt.status)} size="small" color={STATUS_COLORS[stmt.status]} />
                  </TableCell>
                  <TableCell align="right"><strong>{Number(stmt.totalAmount).toFixed(2)} €</strong></TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                      <Button size="small" variant="outlined" startIcon={<PictureAsPdfIcon />} href={getStatementPdfUrl(stmt.id)} target="_blank">PDF</Button>
                      <Button size="small" onClick={() => navigate(`/app/m/billing/statements/${stmt.id}`)}>Détail</Button>
                      {stmt.status !== "PAID" && (
                        <Button size="small" color="success" onClick={() => markPaidMutation.mutate(stmt.id)} disabled={markPaidMutation.isPending}>Payé</Button>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}

      {/* Tutorial modal */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 700 }}>Comment générer un décompte instrumentiste ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "Lancez le wizard", desc: "Cliquez sur \"+ Nouveau décompte\", sélectionnez l'instrumentiste et le mois concerné." },
              { n: 2, title: "Prévisualisez les prestations", desc: "SurgicalHub liste toutes les missions validées du mois. Les blocs sont facturés en heures (durée arrondie au quart d'heure supérieur × tarif horaire). Les consultations sont facturées à l'unité × tarif consultation." },
              { n: 3, title: "Sélectionnez les missions", desc: "Les missions déjà facturées sont grisées. Décochez manuellement celles que vous souhaitez exclure du décompte." },
              { n: 4, title: "Générez le décompte", desc: "Cliquez sur \"Générer le décompte\". Un PDF est automatiquement produit avec le récapitulatif des prestations et le total." },
              { n: 5, title: "Envoyez à l'instrumentiste", desc: "Depuis le détail du décompte, envoyez le PDF par email à l'instrumentiste une fois la période clôturée." },
            ].map(({ n, title, desc }) => (
              <Stack key={n} direction="row" spacing={2} alignItems="flex-start">
                <Box sx={{
                  minWidth: 32, height: 32, borderRadius: "50%",
                  bgcolor: "primary.main", color: "white",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontWeight: 700, fontSize: 14, flexShrink: 0,
                }}>
                  {n}
                </Box>
                <Box>
                  <Typography variant="subtitle2" fontWeight={700}>{title}</Typography>
                  <Typography variant="body2" color="text.secondary">{desc}</Typography>
                </Box>
              </Stack>
            ))}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTutorialOpen(false)} variant="contained" disableElevation>J'ai compris</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
