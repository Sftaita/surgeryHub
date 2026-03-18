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
  Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
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

export default function BillingConfigPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const [tutorialOpen, setTutorialOpen] = React.useState(false);
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
  const [billingEmail, setBillingEmail] = React.useState("");
  const [billingEmailCc, setBillingEmailCc] = React.useState("");

  // New rule form
  const [newRuleType, setNewRuleType] = React.useState<"INTERVENTION_FEE" | "IMPLANT_FEE">("INTERVENTION_FEE");
  const [newInterventionCode, setNewInterventionCode] = React.useState("");
  const [newUnitPrice, setNewUnitPrice] = React.useState("");

  // Material items for IMPLANT_FEE
  const [newMaterialItemId, setNewMaterialItemId] = React.useState<number | "">("");
  const itemsQuery = useQuery({
    queryKey: ["material-items-firm", selectedFirmId],
    queryFn: async () => {
      const { apiClient } = await import("../../../api/apiClient");
      const res = await apiClient.get("/api/material-items", { params: { firmId: selectedFirmId, implantOnly: true, limit: 200 } });
      return res.data.items as { id: number; label: string; referenceCode: string | null }[];
    },
    enabled: !!selectedFirmId && newRuleType === "IMPLANT_FEE",
  });

  const saveBillingContactMutation = useMutation({
    mutationFn: () =>
      updateFirmBillingContact(selectedFirmId as number, {
        billingEmail: billingEmail || null,
        billingEmailCc: billingEmailCc.split(",").map((e) => e.trim()).filter(Boolean),
      }),
    onSuccess: () => { toast.success("Contact de facturation sauvegardé"); qc.invalidateQueries({ queryKey: ["firms"] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  const createRuleMutation = useMutation({
    mutationFn: () =>
      createPricingRule(selectedFirmId as number, {
        ruleType: newRuleType,
        unitPrice: Number(newUnitPrice),
        interventionCode: newRuleType === "INTERVENTION_FEE" ? newInterventionCode : undefined,
        materialItemId: newRuleType === "IMPLANT_FEE" && newMaterialItemId ? newMaterialItemId : undefined,
      }),
    onSuccess: () => {
      toast.success("Règle ajoutée");
      qc.invalidateQueries({ queryKey: ["pricing-rules", selectedFirmId] });
      setNewInterventionCode("");
      setNewUnitPrice("");
      setNewMaterialItemId("");
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deleteRuleMutation = useMutation({
    mutationFn: (ruleId: number) => deletePricingRule(selectedFirmId as number, ruleId),
    onSuccess: () => { toast.success("Règle supprimée"); qc.invalidateQueries({ queryKey: ["pricing-rules", selectedFirmId] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  const toggleActiveMutation = useMutation({
    mutationFn: ({ ruleId, active }: { ruleId: number; active: boolean }) =>
      updatePricingRule(selectedFirmId as number, ruleId, { active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["pricing-rules", selectedFirmId] }),
    onError: (err) => toast.error(extractError(err)),
  });

  React.useEffect(() => {
    if (!selectedFirmId) return;
    const firm = (firmsQuery.data ?? []).find((f) => f.id === selectedFirmId);
    if (firm) {
      setBillingEmail(firm.billingEmail ?? "");
    }
  }, [selectedFirmId, firmsQuery.data]);

  const canAddRule =
    !!selectedFirmId &&
    !!newUnitPrice &&
    Number(newUnitPrice) >= 0 &&
    (newRuleType === "INTERVENTION_FEE" ? !!newInterventionCode.trim() : !!newMaterialItemId);

  return (
    <Stack spacing={3}>
      <Stack direction="row" alignItems="center" spacing={1}>
        <Typography variant="h6" fontWeight={700}>Configuration facturation</Typography>
        <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
          <HelpOutlineIcon fontSize="small" />
        </IconButton>
      </Stack>

      {/* Firm selector */}
      <Stack direction="row" spacing={2} alignItems="center">
        <Typography variant="body2" color="text.secondary">Firme :</Typography>
        <Select
          value={selectedFirmId}
          onChange={(e) => setSelectedFirmId(Number(e.target.value))}
          displayEmpty
          size="small"
          sx={{ minWidth: 220 }}
        >
          <MenuItem value="" disabled>Sélectionner une firme</MenuItem>
          {(firmsQuery.data ?? []).map((f) => <MenuItem key={f.id} value={f.id}>{f.name}</MenuItem>)}
        </Select>
      </Stack>

      {selectedFirmId === "" && (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2.5 }}>
          <img
            src="https://cdn.undraw.co/illustration/settings_alfp.svg"
            alt=""
            style={{ width: 260, maxWidth: "100%", opacity: 0.88 }}
          />
          <Typography variant="h6" fontWeight={600} color="text.secondary">
            Sélectionnez une firme pour commencer
          </Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 420 }}>
            Choisissez une firme ci-dessus pour configurer ses coordonnées de facturation
            et définir ses règles tarifaires (interventions et implants).
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
                onClick={() => saveBillingContactMutation.mutate()}
                disabled={saveBillingContactMutation.isPending}
                sx={{ alignSelf: "flex-start" }}
              >
                {saveBillingContactMutation.isPending ? <CircularProgress size={16} /> : "Sauvegarder"}
              </Button>
            </Stack>
          </Paper>

          {/* ── Pricing rules ── */}
          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
            <Typography variant="subtitle1" fontWeight={700} mb={2}>Règles tarifaires</Typography>

            {/* New rule form */}
            <Stack direction="row" spacing={1.5} alignItems="flex-end" mb={2} flexWrap="wrap">
              <Select value={newRuleType} onChange={(e) => setNewRuleType(e.target.value as any)} size="small" sx={{ minWidth: 180 }}>
                <MenuItem value="INTERVENTION_FEE">Intervention (par code)</MenuItem>
                <MenuItem value="IMPLANT_FEE">Implant (par article)</MenuItem>
              </Select>

              {newRuleType === "INTERVENTION_FEE" ? (
                <TextField
                  label="Code intervention (ex: LCA)"
                  value={newInterventionCode}
                  onChange={(e) => setNewInterventionCode(e.target.value.toUpperCase())}
                  size="small"
                  sx={{ width: 200 }}
                />
              ) : (
                <Select
                  value={newMaterialItemId}
                  onChange={(e) => setNewMaterialItemId(Number(e.target.value))}
                  displayEmpty
                  size="small"
                  sx={{ minWidth: 260 }}
                >
                  <MenuItem value="" disabled>Sélectionner un implant</MenuItem>
                  {(itemsQuery.data ?? []).map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.label} {item.referenceCode ? `(${item.referenceCode})` : ""}
                    </MenuItem>
                  ))}
                </Select>
              )}

              <TextField
                label="Tarif (€)"
                type="number"
                value={newUnitPrice}
                onChange={(e) => setNewUnitPrice(e.target.value)}
                size="small"
                sx={{ width: 120 }}
                inputProps={{ min: 0, step: "0.01" }}
              />

              <Button
                variant="outlined"
                startIcon={<AddIcon />}
                onClick={() => createRuleMutation.mutate()}
                disabled={!canAddRule || createRuleMutation.isPending}
              >
                {createRuleMutation.isPending ? <CircularProgress size={16} /> : "Ajouter"}
              </Button>
            </Stack>

            <Divider sx={{ mb: 2 }} />

            {/* Rules list */}
            {rulesQuery.isLoading ? (
              <CircularProgress size={20} />
            ) : (rulesQuery.data ?? []).length === 0 ? (
              <Typography color="text.secondary" variant="body2">Aucune règle configurée</Typography>
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
                  {(rulesQuery.data ?? []).map((rule: PricingRule) => (
                    <TableRow key={rule.id}>
                      <TableCell>
                        <Chip
                          label={rule.ruleType === "INTERVENTION_FEE" ? "Intervention" : "Implant"}
                          size="small"
                          color={rule.ruleType === "INTERVENTION_FEE" ? "primary" : "secondary"}
                          variant="outlined"
                        />
                      </TableCell>
                      <TableCell>
                        {rule.ruleType === "INTERVENTION_FEE"
                          ? <code style={{ fontSize: 12 }}>{rule.interventionCode}</code>
                          : <>{rule.materialItem?.label} <Typography component="span" variant="caption" color="text.secondary">({rule.materialItem?.referenceCode ?? "—"})</Typography></>
                        }
                      </TableCell>
                      <TableCell align="right"><strong>{Number(rule.unitPrice).toFixed(2)} €</strong></TableCell>
                      <TableCell>
                        <Chip
                          label={rule.active ? "Actif" : "Inactif"}
                          size="small"
                          color={rule.active ? "success" : "default"}
                          onClick={() => toggleActiveMutation.mutate({ ruleId: rule.id, active: !rule.active })}
                          sx={{ cursor: "pointer" }}
                        />
                      </TableCell>
                      <TableCell align="right">
                        <IconButton
                          size="small"
                          color="error"
                          onClick={() => deleteRuleMutation.mutate(rule.id)}
                          disabled={deleteRuleMutation.isPending}
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
      {/* Tutorial modal */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 700 }}>
          Comment configurer la facturation ?
        </DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "Sélectionnez une firme", desc: "Choisissez la firme à configurer dans le menu déroulant en haut de la page." },
              { n: 2, title: "Configurez le contact de facturation", desc: "Renseignez l'email principal de facturation ainsi que les éventuelles adresses en CC (séparées par virgule). Ces adresses seront utilisées lors de l'envoi des factures." },
              { n: 3, title: "Ajoutez des règles tarifaires", desc: "Deux types de règles : par code d'intervention (ex : LCA → 350 €) ou par implant spécifique. Chaque règle applique un tarif fixe par occurrence sur les missions validées." },
              { n: 4, title: "Activez ou désactivez les règles", desc: "Cliquez sur le badge de statut pour activer/désactiver une règle sans la supprimer, selon l'évolution des contrats." },
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
