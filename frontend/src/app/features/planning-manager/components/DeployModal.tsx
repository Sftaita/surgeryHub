import * as React from "react";
import {
  Alert, Box, Button, Checkbox, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, FormControlLabel, Stack, Typography,
} from "@mui/material";
import CheckCircleIcon         from "@mui/icons-material/CheckCircle";
import WarningIcon             from "@mui/icons-material/Warning";
import SendIcon                from "@mui/icons-material/Send";
import AddCircleOutlineIcon    from "@mui/icons-material/AddCircleOutline";
import RemoveCircleOutlineIcon from "@mui/icons-material/RemoveCircleOutline";
import SyncIcon                from "@mui/icons-material/Sync";
import {
  getVersionDiff,
  type PreviewLine,
  type PlanningDiff,
  type MissionDiffEntry,
} from "../api/planning.api";

// ── Local helpers (duplicated from PlanningGeneratePage to keep this component self-contained) ─

function formatDate(dateStr: string): string {
  return new Date(dateStr + "T00:00:00").toLocaleDateString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
  });
}

function getDayName(dateStr: string): string {
  const s = new Date(dateStr + "T00:00:00").toLocaleDateString("fr-BE", { weekday: "long" });
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function getPeriod(startTime: string): string {
  return parseInt(startTime.split(":")[0], 10) < 12 ? "Matin" : "Après-midi";
}

export function lineKey(line: PreviewLine): string {
  return `${line.date}-${line.slotId}`;
}

// ── DiffMissionRow ────────────────────────────────────────────────────────────

function DiffMissionRow({ mission, changes }: {
  mission: MissionDiffEntry;
  changes?: PlanningDiff["modified"][0]["changes"];
}) {
  return (
    <Box sx={{ py: 0.75, borderBottom: "1px solid", borderColor: "divider" }}>
      <Stack direction="row" spacing={1} alignItems="baseline" flexWrap="wrap">
        <Typography variant="body2" fontWeight={600} sx={{ minWidth: 80 }}>
          {formatDate(mission.date)} {mission.period === "AM" ? "Matin" : "Après-midi"}
        </Typography>
        <Typography variant="body2">{mission.surgeonName}</Typography>
        {mission.siteName && (
          <Typography variant="caption" color="text.secondary">· {mission.siteName}</Typography>
        )}
        <Typography variant="caption" color="text.secondary">
          {mission.startAt}–{mission.endAt}
        </Typography>
      </Stack>
      {mission.instrumentistName && (
        <Typography variant="caption" color="text.secondary" sx={{ ml: 10 }}>
          Instr. : {mission.instrumentistName}
        </Typography>
      )}
      {changes && (
        <Stack spacing={0.25} sx={{ ml: 10, mt: 0.25 }}>
          {changes.schedule && (
            <Typography variant="caption" color="warning.dark">
              Horaire : {changes.schedule.from.startAt}–{changes.schedule.from.endAt}
              {" → "}{changes.schedule.to.startAt}–{changes.schedule.to.endAt}
            </Typography>
          )}
          {changes.instrumentist && (
            <Typography variant="caption" color="warning.dark">
              Instr. : {changes.instrumentist.from?.name ?? "Aucun"}
              {" → "}{changes.instrumentist.to?.name ?? "Aucun"}
            </Typography>
          )}
          {changes.surgeon && (
            <Typography variant="caption" color="warning.dark">
              Chirurgien : {changes.surgeon.from.name} → {changes.surgeon.to.name}
            </Typography>
          )}
          {changes.site && (
            <Typography variant="caption" color="warning.dark">
              Site : {changes.site.from ?? "—"} → {changes.site.to ?? "—"}
            </Typography>
          )}
        </Stack>
      )}
    </Box>
  );
}

// ── DeployModal ───────────────────────────────────────────────────────────────

export interface DeployModalProps {
  open:         boolean;
  onClose:      () => void;
  previewLines: PreviewLine[];
  versionId:    number | null;
  from:         string;
  to:           string;
  onDeploy:     (selectedUncoveredMissionIds: number[], sendChangeSummary: boolean) => void;
  isDeploying:  boolean;
}

export function DeployModal({
  open, onClose, previewLines, versionId, from: _from, to: _to, onDeploy, isDeploying,
}: DeployModalProps) {
  const [step,              setStep]              = React.useState<1 | 2>(1);
  const [checkedIds,        setCheckedIds]        = React.useState<Set<number>>(new Set());
  const [sendChangeSummary, setSendChangeSummary] = React.useState(false);
  const [diff,              setDiff]              = React.useState<PlanningDiff | null>(null);
  const [diffLoading,       setDiffLoading]       = React.useState(false);
  const [diffError,         setDiffError]         = React.useState(false);

  const uncoveredLines = previewLines.filter(
    (l) => l.status !== "SKIPPED" && l.instrumentistId === null && l.existingMissionId !== null,
  );

  React.useEffect(() => {
    if (!open) return;
    setStep(1);
    setCheckedIds(new Set(uncoveredLines.map((l) => l.existingMissionId!)));
    setDiff(null);
    setDiffError(false);
    setSendChangeSummary(false);
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  async function loadDiff() {
    if (versionId === null || diff !== null) return;
    setDiffLoading(true);
    setDiffError(false);
    try {
      const d = await getVersionDiff(versionId);
      setDiff(d);
      const hasChanges = d.added.length > 0 || d.removed.length > 0 || d.modified.length > 0;
      setSendChangeSummary(hasChanges);
    } catch {
      setDiffError(true);
    } finally {
      setDiffLoading(false);
    }
  }

  function handleNext() {
    setStep(2);
    loadDiff();
  }

  function handleDeploy() {
    onDeploy(Array.from(checkedIds), sendChangeSummary);
  }

  function toggleAll(check: boolean) {
    setCheckedIds(check ? new Set(uncoveredLines.map((l) => l.existingMissionId!)) : new Set());
  }

  function toggleOne(id: number, checked: boolean) {
    setCheckedIds((prev) => {
      const next = new Set(prev);
      if (checked) next.add(id); else next.delete(id);
      return next;
    });
  }

  const allChecked = uncoveredLines.length > 0 && uncoveredLines.every((l) => checkedIds.has(l.existingMissionId!));
  const hasDiff    = diff !== null && (diff.added.length > 0 || diff.removed.length > 0 || diff.modified.length > 0);

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth scroll="paper">
      {/* Stepper header */}
      <DialogTitle fontWeight={700} sx={{ pb: 1 }}>
        <Stack direction="row" alignItems="center" spacing={1}>
          {[1, 2].map((n) => (
            <React.Fragment key={n}>
              {n === 2 && <Box sx={{ flex: 1, height: 1, bgcolor: "divider", mx: 1 }} />}
              <Box sx={{
                minWidth: 24, height: 24, borderRadius: "50%",
                bgcolor: step === n ? "primary.main" : "grey.300",
                color: step === n ? "white" : "text.primary",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 12, fontWeight: 700,
              }}>{n}</Box>
              <Typography variant="body2" fontWeight={step === n ? 700 : 400}
                color={step === n ? "primary.main" : "text.secondary"}>
                {n === 1 ? "Postes non assignés" : "Récapitulatif"}
              </Typography>
            </React.Fragment>
          ))}
        </Stack>
      </DialogTitle>

      <DialogContent dividers>
        {/* ── Étape 1 ── */}
        {step === 1 && (
          <Stack spacing={2}>
            {uncoveredLines.length === 0 ? (
              <Alert severity="success" icon={<CheckCircleIcon />}>
                Toutes les missions ont un instrumentiste assigné. Aucune publication en pool requise.
              </Alert>
            ) : (
              <>
                <Alert severity="info" icon={<WarningIcon />} sx={{ py: 0.5 }}>
                  {uncoveredLines.length} poste(s) sans instrumentiste. Cochez ceux que vous voulez
                  publier en pool — les instrumentistes du site recevront une notification groupée.
                </Alert>

                <Stack direction="row" spacing={1}>
                  <Button size="small" variant="outlined" onClick={() => toggleAll(true)} disabled={allChecked}>
                    Tout cocher
                  </Button>
                  <Button size="small" variant="outlined" onClick={() => toggleAll(false)} disabled={checkedIds.size === 0}>
                    Tout décocher
                  </Button>
                </Stack>

                <Stack spacing={0.5}>
                  {uncoveredLines.map((line) => {
                    const id = line.existingMissionId!;
                    return (
                      <Box key={lineKey(line)} sx={{
                        display: "flex", alignItems: "center",
                        px: 1.5, py: 0.75,
                        border: "1px solid",
                        borderColor: checkedIds.has(id) ? "primary.light" : "divider",
                        borderRadius: 1.5,
                        bgcolor: checkedIds.has(id) ? "primary.50" : "grey.50",
                      }}>
                        <Checkbox
                          checked={checkedIds.has(id)}
                          onChange={(e) => toggleOne(id, e.target.checked)}
                          size="small" sx={{ p: 0.5, mr: 1 }}
                        />
                        <Box sx={{ flex: 1 }}>
                          <Typography variant="body2" fontWeight={600}>{line.surgeonName}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {getDayName(line.date)} {formatDate(line.date)}
                            {" · "}{getPeriod(line.startTime)}
                            {line.siteName ? ` · ${line.siteName}` : ""}
                          </Typography>
                        </Box>
                      </Box>
                    );
                  })}
                </Stack>

                {checkedIds.size > 0 && (
                  <Typography variant="caption" color="text.secondary">
                    {checkedIds.size} poste(s) sélectionné(s) seront publiés en pool.
                    {uncoveredLines.length - checkedIds.size > 0 &&
                      ` ${uncoveredLines.length - checkedIds.size} resteront en DRAFT.`}
                  </Typography>
                )}
              </>
            )}
          </Stack>
        )}

        {/* ── Étape 2 ── */}
        {step === 2 && (
          <Stack spacing={2}>
            {diffLoading && (
              <Stack alignItems="center" py={3} spacing={1}>
                <CircularProgress size={28} />
                <Typography variant="body2" color="text.secondary">Calcul des modifications…</Typography>
              </Stack>
            )}
            {diffError && (
              <Alert severity="warning">
                Impossible de charger le récapitulatif des modifications. Le déploiement reste possible.
              </Alert>
            )}
            {diff !== null && !hasDiff && (
              <Alert severity="success" icon={<CheckCircleIcon />}>
                Aucune modification par rapport à la version précédente. Premier déploiement ou planning identique.
              </Alert>
            )}
            {diff !== null && hasDiff && (
              <Stack spacing={1.5}>
                {diff.added.length > 0 && (
                  <Box>
                    <Stack direction="row" alignItems="center" spacing={0.75} mb={0.5}>
                      <AddCircleOutlineIcon sx={{ fontSize: 16, color: "success.main" }} />
                      <Typography variant="subtitle2" color="success.main">Ajouts ({diff.added.length})</Typography>
                    </Stack>
                    <Box sx={{ pl: 1 }}>{diff.added.map((m, i) => <DiffMissionRow key={i} mission={m} />)}</Box>
                  </Box>
                )}
                {diff.removed.length > 0 && (
                  <Box>
                    <Stack direction="row" alignItems="center" spacing={0.75} mb={0.5}>
                      <RemoveCircleOutlineIcon sx={{ fontSize: 16, color: "error.main" }} />
                      <Typography variant="subtitle2" color="error.main">Suppressions ({diff.removed.length})</Typography>
                    </Stack>
                    <Box sx={{ pl: 1 }}>{diff.removed.map((m, i) => <DiffMissionRow key={i} mission={m} />)}</Box>
                  </Box>
                )}
                {diff.modified.length > 0 && (
                  <Box>
                    <Stack direction="row" alignItems="center" spacing={0.75} mb={0.5}>
                      <SyncIcon sx={{ fontSize: 16, color: "warning.main" }} />
                      <Typography variant="subtitle2" color="warning.main">Modifications ({diff.modified.length})</Typography>
                    </Stack>
                    <Box sx={{ pl: 1 }}>
                      {diff.modified.map((entry, i) => (
                        <DiffMissionRow key={i} mission={entry.mission} changes={entry.changes} />
                      ))}
                    </Box>
                  </Box>
                )}
              </Stack>
            )}

            <Box sx={{ pt: 1, borderTop: "1px solid", borderColor: "divider", opacity: hasDiff ? 1 : 0.4 }}>
              <FormControlLabel
                control={
                  <Checkbox
                    checked={sendChangeSummary}
                    onChange={(e) => setSendChangeSummary(e.target.checked)}
                    disabled={!hasDiff}
                    size="small"
                  />
                }
                label={
                  <Typography variant="body2">
                    Envoyer le récapitulatif des modifications aux instrumentistes et chirurgiens
                  </Typography>
                }
              />
              {sendChangeSummary && hasDiff && (
                <Typography variant="caption" color="text.secondary" sx={{ pl: 3.5, display: "block" }}>
                  Chaque instrumentiste recevra un email personnalisé groupé par jour.
                  Les chirurgiens recevront la liste des jours non couverts + le PDF global.
                </Typography>
              )}
            </Box>
          </Stack>
        )}
      </DialogContent>

      <DialogActions sx={{ gap: 1, px: 3, pb: 2 }}>
        <Button onClick={onClose} color="inherit" disabled={isDeploying}>Annuler</Button>
        {step === 1 && (
          <Button variant="contained" disableElevation onClick={handleNext}>Suivant →</Button>
        )}
        {step === 2 && (
          <>
            <Button onClick={() => setStep(1)} color="inherit" disabled={isDeploying}>← Retour</Button>
            <Button
              variant="contained" color="success" disableElevation
              startIcon={isDeploying ? <CircularProgress size={14} color="inherit" /> : <SendIcon />}
              onClick={handleDeploy} disabled={isDeploying}
              sx={{ borderRadius: 2 }}
            >
              {isDeploying ? "Déploiement…" : "Déployer et envoyer les PDFs"}
            </Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
}
