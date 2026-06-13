import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
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
  ToggleButton,
  ToggleButtonGroup,
  Typography,
} from "@mui/material";
import AddIcon      from "@mui/icons-material/Add";
import DeleteIcon   from "@mui/icons-material/Delete";
import WarningAmberIcon from "@mui/icons-material/WarningAmber";
import HelpOutlineIcon  from "@mui/icons-material/HelpOutline";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getFirmPricingRules,
  createPricingRule,
  deletePricingRule,
  updatePricingRule,
  updateFirmBillingContact,
  type PricingRule,
} from "../../../features/billing-firm/api/firmBilling.api";
import { useToast } from "../../../ui/toast/useToast";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

// ── Add-rule dialog ───────────────────────────────────────────────────────────
function AddRuleDialog({
  open,
  onClose,
  firmId,
  existingRules,
  onCreated,
}: {
  open:          boolean;
  onClose:       () => void;
  firmId:        number;
  existingRules: PricingRule[];
  onCreated:     () => void;
}) {
  const toast = useToast();
  const [ruleType,          setRuleType]          = React.useState<"INTERVENTION_FEE" | "IMPLANT_FEE">("INTERVENTION_FEE");
  const [interventionCode,  setInterventionCode]  = React.useState("");
  const [materialItemId,    setMaterialItemId]    = React.useState<number | "">("");
  const [unitPrice,         setUnitPrice]         = React.useState("");

  // Reset form on open
  React.useEffect(() => {
    if (open) {
      setRuleType("INTERVENTION_FEE");
      setInterventionCode("");
      setMaterialItemId("");
      setUnitPrice("");
    }
  }, [open]);

  // Material items for this firm
  const itemsQuery = useQuery({
    queryKey: ["material-items-firm", firmId],
    queryFn: async () => {
      const { apiClient } = await import("../../../api/apiClient");
      const res = await apiClient.get("/api/material-items", {
        params: { firmId, implantOnly: true, limit: 200 },
      });
      return res.data.items as { id: number; label: string; referenceCode: string | null }[];
    },
    enabled: open && ruleType === "IMPLANT_FEE",
  });

  // ── Duplicate detection ───────────────────────────────────────────────────
  const isDuplicate = React.useMemo(() => {
    if (ruleType === "INTERVENTION_FEE") {
      if (!interventionCode.trim()) return false;
      return existingRules.some(
        (r) =>
          r.ruleType === "INTERVENTION_FEE" &&
          r.interventionCode?.toUpperCase() === interventionCode.trim().toUpperCase(),
      );
    } else {
      if (!materialItemId) return false;
      return existingRules.some(
        (r) => r.ruleType === "IMPLANT_FEE" && r.materialItem?.id === materialItemId,
      );
    }
  }, [ruleType, interventionCode, materialItemId, existingRules]);

  const canSubmit =
    !!unitPrice &&
    Number(unitPrice) >= 0 &&
    !isDuplicate &&
    (ruleType === "INTERVENTION_FEE"
      ? !!interventionCode.trim()
      : !!materialItemId);

  const qc = useQueryClient();
  const createMut = useMutation({
    mutationFn: () =>
      createPricingRule(firmId, {
        ruleType,
        unitPrice:         Number(unitPrice),
        interventionCode:  ruleType === "INTERVENTION_FEE" ? interventionCode.trim().toUpperCase() : undefined,
        materialItemId:    ruleType === "IMPLANT_FEE" && materialItemId ? materialItemId : undefined,
      }),
    onSuccess: () => {
      toast.success("Règle ajoutée");
      qc.invalidateQueries({ queryKey: ["pricing-rules", firmId] });
      onCreated();
      onClose();
    },
    onError: (err) => toast.error(extractError(err)),
  });

  // Duplicate label for the warning
  const duplicateExisting = isDuplicate
    ? existingRules.find((r) =>
        ruleType === "INTERVENTION_FEE"
          ? r.ruleType === "INTERVENTION_FEE" &&
            r.interventionCode?.toUpperCase() === interventionCode.trim().toUpperCase()
          : r.ruleType === "IMPLANT_FEE" && r.materialItem?.id === materialItemId,
      )
    : null;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700} sx={{ pb: 0.5 }}>
        Ajouter une règle tarifaire
      </DialogTitle>

      <DialogContent>
        <Stack spacing={2.5} sx={{ pt: 1.5 }}>

          {/* ── Step 1: Type ── */}
          <Box>
            <Typography variant="caption" fontWeight={700} color="text.secondary" sx={{ letterSpacing: .5, textTransform: "uppercase", display: "block", mb: 1 }}>
              1 · Type de forfait
            </Typography>
            <ToggleButtonGroup
              value={ruleType}
              exclusive
              onChange={(_, v) => { if (v) { setRuleType(v); setInterventionCode(""); setMaterialItemId(""); } }}
              size="small"
              fullWidth
            >
              <ToggleButton value="INTERVENTION_FEE" sx={{ fontWeight: 600, fontSize: ".8rem", textTransform: "none" }}>
                Par intervention
              </ToggleButton>
              <ToggleButton value="IMPLANT_FEE" sx={{ fontWeight: 600, fontSize: ".8rem", textTransform: "none" }}>
                Par implant
              </ToggleButton>
            </ToggleButtonGroup>
          </Box>

          {/* ── Step 2: Condition ── */}
          <Box>
            <Typography variant="caption" fontWeight={700} color="text.secondary" sx={{ letterSpacing: .5, textTransform: "uppercase", display: "block", mb: 1 }}>
              2 · {ruleType === "INTERVENTION_FEE" ? "Code intervention" : "Implant concerné"}
            </Typography>

            {ruleType === "INTERVENTION_FEE" ? (
              <TextField
                fullWidth
                size="small"
                label="Ex : LCA, PTG, PTE…"
                value={interventionCode}
                onChange={(e) => setInterventionCode(e.target.value.toUpperCase())}
                inputProps={{ style: { fontFamily: "monospace", fontWeight: 700, letterSpacing: 1 } }}
                error={isDuplicate}
              />
            ) : (
              <Select
                fullWidth
                size="small"
                value={materialItemId}
                onChange={(e) => setMaterialItemId(Number(e.target.value))}
                displayEmpty
                error={isDuplicate}
              >
                <MenuItem value="" disabled>
                  {itemsQuery.isLoading ? "Chargement…" : "Sélectionner un implant"}
                </MenuItem>
                {(itemsQuery.data ?? []).map((item) => (
                  <MenuItem key={item.id} value={item.id}>
                    {item.label}
                    {item.referenceCode && (
                      <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1 }}>
                        ({item.referenceCode})
                      </Typography>
                    )}
                  </MenuItem>
                ))}
              </Select>
            )}

            {/* Duplicate warning */}
            {isDuplicate && duplicateExisting && (
              <Alert
                severity="warning"
                icon={<WarningAmberIcon fontSize="small" />}
                sx={{ mt: 1, py: 0.5, fontSize: ".78rem", borderRadius: 1.5 }}
              >
                Cette règle existe déjà pour cette firme ({Number(duplicateExisting.unitPrice).toFixed(2)} €).
                Supprimez-la d'abord si vous souhaitez la modifier.
              </Alert>
            )}
          </Box>

          {/* ── Step 3: Price ── */}
          <Box>
            <Typography variant="caption" fontWeight={700} color="text.secondary" sx={{ letterSpacing: .5, textTransform: "uppercase", display: "block", mb: 1 }}>
              3 · Tarif contractuel
            </Typography>
            <TextField
              fullWidth
              size="small"
              label="Montant (€)"
              type="number"
              value={unitPrice}
              onChange={(e) => setUnitPrice(e.target.value)}
              inputProps={{ min: 0, step: "0.01" }}
              InputProps={{ endAdornment: <Typography color="text.secondary" sx={{ pl: 0.5 }}>€</Typography> }}
            />
          </Box>
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} color="inherit">Annuler</Button>
        <Button
          variant="contained"
          disableElevation
          onClick={() => createMut.mutate()}
          disabled={!canSubmit || createMut.isPending}
          sx={{ borderRadius: 2 }}
        >
          {createMut.isPending ? <CircularProgress size={16} /> : "Ajouter"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function BillingConfigPage() {
  const toast = useToast();
  const qc    = useQueryClient();

  const [tutorialOpen,   setTutorialOpen]   = React.useState(false);
  const [addRuleOpen,    setAddRuleOpen]    = React.useState(false);
  const [selectedFirmId, setSelectedFirmId] = React.useState<number | "">("");

  // Firm list
  const firmsQuery = useQuery({
    queryKey: ["firms"],
    queryFn: async () => {
      const { apiClient } = await import("../../../api/apiClient");
      const res = await apiClient.get("/api/firms");
      return res.data as { id: number; name: string; billingEmail?: string | null }[];
    },
  });

  // Pricing rules for selected firm
  const rulesQuery = useQuery({
    queryKey: ["pricing-rules", selectedFirmId],
    queryFn: () => getFirmPricingRules(selectedFirmId as number),
    enabled: !!selectedFirmId,
  });

  // Billing contact form
  const [billingEmail,   setBillingEmail]   = React.useState("");
  const [billingEmailCc, setBillingEmailCc] = React.useState("");

  React.useEffect(() => {
    if (!selectedFirmId) return;
    const firm = (firmsQuery.data ?? []).find((f) => f.id === selectedFirmId);
    if (firm) setBillingEmail(firm.billingEmail ?? "");
  }, [selectedFirmId, firmsQuery.data]);

  const saveBillingMut = useMutation({
    mutationFn: () =>
      updateFirmBillingContact(selectedFirmId as number, {
        billingEmail:   billingEmail || null,
        billingEmailCc: billingEmailCc.split(",").map((e) => e.trim()).filter(Boolean),
      }),
    onSuccess: () => { toast.success("Contact sauvegardé"); qc.invalidateQueries({ queryKey: ["firms"] }); },
    onError:   (err) => toast.error(extractError(err)),
  });

  const deleteMut = useMutation({
    mutationFn: (ruleId: number) => deletePricingRule(selectedFirmId as number, ruleId),
    onSuccess: () => { toast.success("Règle supprimée"); qc.invalidateQueries({ queryKey: ["pricing-rules", selectedFirmId] }); },
    onError:   (err) => toast.error(extractError(err)),
  });

  const toggleMut = useMutation({
    mutationFn: ({ ruleId, active }: { ruleId: number; active: boolean }) =>
      updatePricingRule(selectedFirmId as number, ruleId, { active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["pricing-rules", selectedFirmId] }),
    onError:   (err) => toast.error(extractError(err)),
  });

  const rules         = rulesQuery.data ?? [];
  const selectedFirm  = (firmsQuery.data ?? []).find((f) => f.id === selectedFirmId);

  return (
    <Stack spacing={3}>

      {/* ── Title ── */}
      <Stack direction="row" alignItems="center" spacing={1}>
        <Typography variant="h6" fontWeight={700}>Configuration facturation</Typography>
        <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
          <HelpOutlineIcon fontSize="small" />
        </IconButton>
      </Stack>

      {/* ── Firm selector ── */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" spacing={2} alignItems="center">
          <Typography variant="subtitle2" fontWeight={700} sx={{ minWidth: 48 }}>Firme</Typography>
          <Select
            value={selectedFirmId}
            onChange={(e) => {
              setSelectedFirmId(Number(e.target.value));
              setBillingEmailCc("");
            }}
            displayEmpty
            size="small"
            sx={{ minWidth: 240 }}
          >
            <MenuItem value="" disabled>Sélectionner une firme…</MenuItem>
            {(firmsQuery.data ?? []).map((f) => (
              <MenuItem key={f.id} value={f.id}>{f.name}</MenuItem>
            ))}
          </Select>
          {selectedFirm && (
            <Typography variant="caption" color="text.secondary">
              {rules.length} règle{rules.length !== 1 ? "s" : ""} tarifaire{rules.length !== 1 ? "s" : ""}
            </Typography>
          )}
        </Stack>
      </Paper>

      {/* ── Empty state ── */}
      {selectedFirmId === "" && (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2 }}>
          <Typography variant="h6" fontWeight={600} color="text.secondary">
            Sélectionnez une firme pour commencer
          </Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 380 }}>
            Configurez le contact de facturation et les tarifs contractuels (par intervention ou par implant)
            propres à chaque firme.
          </Typography>
        </Box>
      )}

      {selectedFirmId !== "" && (
        <>
          {/* ── Billing contact ── */}
          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
            <Typography variant="subtitle1" fontWeight={700} mb={2}>Contact de facturation</Typography>
            <Stack spacing={1.5}>
              <TextField
                label="Email principal"
                value={billingEmail}
                onChange={(e) => setBillingEmail(e.target.value)}
                size="small"
                sx={{ maxWidth: 400 }}
                placeholder="facturation@firme.com"
              />
              <TextField
                label="CC (séparés par virgule)"
                value={billingEmailCc}
                onChange={(e) => setBillingEmailCc(e.target.value)}
                size="small"
                sx={{ maxWidth: 500 }}
                placeholder="cc1@firme.com, cc2@firme.com"
              />
              <Button
                variant="contained"
                disableElevation
                onClick={() => saveBillingMut.mutate()}
                disabled={saveBillingMut.isPending}
                sx={{ alignSelf: "flex-start" }}
              >
                {saveBillingMut.isPending ? <CircularProgress size={16} /> : "Sauvegarder"}
              </Button>
            </Stack>
          </Paper>

          {/* ── Pricing rules ── */}
          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
            <Stack direction="row" alignItems="center" justifyContent="space-between" mb={2}>
              <Typography variant="subtitle1" fontWeight={700}>Règles tarifaires</Typography>
              <Button
                variant="outlined"
                size="small"
                startIcon={<AddIcon />}
                onClick={() => setAddRuleOpen(true)}
                sx={{ borderRadius: 999, fontWeight: 600 }}
              >
                Ajouter une règle
              </Button>
            </Stack>

            <Divider sx={{ mb: 2 }} />

            {rulesQuery.isLoading ? (
              <CircularProgress size={20} />
            ) : rules.length === 0 ? (
              <Typography color="text.secondary" variant="body2">
                Aucune règle configurée pour {selectedFirm?.name}.
              </Typography>
            ) : (
              <Table size="small">
                <TableHead>
                  <TableRow sx={{ bgcolor: "grey.50" }}>
                    <TableCell>Type</TableCell>
                    <TableCell>Condition</TableCell>
                    <TableCell align="right">Tarif</TableCell>
                    <TableCell>Statut</TableCell>
                    <TableCell align="right">Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rules.map((rule: PricingRule) => (
                    <TableRow key={rule.id} hover>
                      <TableCell>
                        <Chip
                          label={rule.ruleType === "INTERVENTION_FEE" ? "Intervention" : "Implant"}
                          size="small"
                          color={rule.ruleType === "INTERVENTION_FEE" ? "primary" : "secondary"}
                          variant="outlined"
                        />
                      </TableCell>
                      <TableCell>
                        {rule.ruleType === "INTERVENTION_FEE" ? (
                          <Typography component="span" sx={{ fontFamily: "monospace", fontWeight: 700, fontSize: ".85rem" }}>
                            {rule.interventionCode}
                          </Typography>
                        ) : (
                          <Stack direction="row" spacing={0.75} alignItems="center">
                            <Typography variant="body2">{rule.materialItem?.label}</Typography>
                            {rule.materialItem?.referenceCode && (
                              <Typography variant="caption" color="text.secondary">
                                ({rule.materialItem.referenceCode})
                              </Typography>
                            )}
                          </Stack>
                        )}
                      </TableCell>
                      <TableCell align="right">
                        <Typography fontWeight={700}>{Number(rule.unitPrice).toFixed(2)} €</Typography>
                      </TableCell>
                      <TableCell>
                        <Chip
                          label={rule.active ? "Actif" : "Inactif"}
                          size="small"
                          color={rule.active ? "success" : "default"}
                          onClick={() => toggleMut.mutate({ ruleId: rule.id, active: !rule.active })}
                          sx={{ cursor: "pointer" }}
                        />
                      </TableCell>
                      <TableCell align="right">
                        <IconButton
                          size="small" color="error"
                          onClick={() => deleteMut.mutate(rule.id)}
                          disabled={deleteMut.isPending}
                        >
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Paper>
        </>
      )}

      {/* ── Add rule dialog ── */}
      {selectedFirmId !== "" && (
        <AddRuleDialog
          open={addRuleOpen}
          onClose={() => setAddRuleOpen(false)}
          firmId={selectedFirmId as number}
          existingRules={rules}
          onCreated={() => setAddRuleOpen(false)}
        />
      )}

      {/* ── Tutorial dialog ── */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle fontWeight={700}>Comment configurer la facturation ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "Sélectionnez une firme", desc: "Choisissez la firme à configurer. Chaque firme a ses propres tarifs contractuels, indépendamment des autres." },
              { n: 2, title: "Configurez le contact de facturation", desc: "Email principal et CC pour l'envoi automatique des factures." },
              { n: 3, title: "Ajoutez des règles tarifaires", desc: "Deux types : par intervention (ex. LCA = 140 € chez Smith) ou par implant spécifique. Une même intervention peut avoir des tarifs différents selon la firme." },
              { n: 4, title: "Doublons bloqués automatiquement", desc: "Il est impossible d'ajouter deux règles pour le même code intervention ou le même implant au sein d'une même firme. Supprimez l'ancienne règle avant d'en créer une nouvelle." },
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
