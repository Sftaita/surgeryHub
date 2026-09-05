import * as React from "react";
import { Alert, Box, Button, CircularProgress, IconButton, Stack, TextField, Typography } from "@mui/material";
import CampaignOutlinedIcon from "@mui/icons-material/CampaignOutlined";
import MarkEmailReadOutlinedIcon from "@mui/icons-material/MarkEmailReadOutlined";
import AddIcon from "@mui/icons-material/Add";
import CloseIcon from "@mui/icons-material/Close";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getAbsenceCommunicationSettings, updateAbsenceCommunicationSettings, extractErrorV2 } from "../api/planningV2.api";
import type { AbsenceCommunicationSiteSettingV2 } from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Communication des absences chirurgiens — Lot A/B (D-114). Deux réglages indépendants par
 * site : « Libération de salle » (Lot A, toggle simple, comportement inchangé) et
 * « Gestion du bloc » (Lot B, toggle + To + CC dynamiques + délai). Le chirurgien concerné
 * n'apparaît jamais comme valeur modifiable dans la liste CC — ajouté automatiquement côté
 * backend au moment de l'envoi.
 */
export function AbsenceCommunicationSettings() {
  const toast = useToast();
  const qc = useQueryClient();
  const [editingSiteId, setEditingSiteId] = React.useState<number | null>(null);

  const settingsQuery = useQuery({
    queryKey: ["planning-v2", "absence-communication-settings"],
    queryFn: () => getAbsenceCommunicationSettings(),
  });

  function invalidate() {
    qc.invalidateQueries({ queryKey: ["planning-v2", "absence-communication-settings"] });
  }

  const colleaguesToggleMutation = useMutation({
    mutationFn: (vars: { siteId: number; notifyColleaguesEnabled: boolean }) =>
      updateAbsenceCommunicationSettings(vars.siteId, { notifyColleaguesEnabled: vars.notifyColleaguesEnabled }),
    onSuccess: () => { toast.success("Réglage enregistré"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const blockManagementToggleMutation = useMutation({
    mutationFn: (vars: { siteId: number; notifyBlockManagementEnabled: boolean }) =>
      updateAbsenceCommunicationSettings(vars.siteId, { notifyBlockManagementEnabled: vars.notifyBlockManagementEnabled }),
    onSuccess: () => { toast.success("Réglage enregistré"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const items = React.useMemo(
    () => [...(settingsQuery.data?.items ?? [])].sort((a, b) => a.site.name.localeCompare(b.site.name)),
    [settingsQuery.data],
  );

  return (
    <Box>
      <Typography sx={{ fontSize: 14.5, fontWeight: 700, mb: 2 }}>Communication des absences</Typography>

      {settingsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>
      ) : settingsQuery.isError ? (
        <Alert severity="error">{extractErrorV2(settingsQuery.error)}</Alert>
      ) : items.length === 0 ? (
        <Alert severity="info">Aucun site configuré.</Alert>
      ) : (
        <Stack spacing={2.25}>
          {items.map((item) => (
            <Box
              key={item.site.id}
              sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}
            >
              <Typography sx={{ fontSize: 14.5, fontWeight: 700, px: 2.25, py: 1.5, borderBottom: `1px solid ${planningV2Colors.divider}` }}>
                {item.site.name}
              </Typography>

              {/* Libération de salle — Lot A, comportement inchangé */}
              <Stack direction="row" alignItems="flex-start" spacing={1.75} sx={{ px: 2.25, py: 2, borderBottom: `1px solid ${planningV2Colors.divider}` }}>
                <IconBadge on={item.notifyColleaguesEnabled} icon={<CampaignOutlinedIcon sx={{ fontSize: 17 }} />} />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography sx={{ fontSize: 13, fontWeight: 700 }}>Libération de salle</Typography>
                  <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, mt: 0.25 }}>
                    Envoie aux chirurgiens du site les dates des blocs opératoires libérés lorsqu'un chirurgien encode une absence.
                  </Typography>
                </Box>
                <SwitchPill
                  on={item.notifyColleaguesEnabled}
                  disabled={colleaguesToggleMutation.isPending}
                  onClick={() => colleaguesToggleMutation.mutate({ siteId: item.site.id, notifyColleaguesEnabled: !item.notifyColleaguesEnabled })}
                  ariaLabel={`Informer les chirurgiens du site ${item.site.name}`}
                />
              </Stack>

              {/* Gestion du bloc — Lot B */}
              <Stack direction="row" alignItems="flex-start" spacing={1.75} sx={{ px: 2.25, py: 2 }}>
                <IconBadge on={item.notifyBlockManagementEnabled} icon={<MarkEmailReadOutlinedIcon sx={{ fontSize: 17 }} />} />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography sx={{ fontSize: 13, fontWeight: 700 }}>Prévenir automatiquement la gestion du bloc</Typography>
                  <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, mt: 0.25 }}>
                    Le chirurgien concerné recevra automatiquement une copie de l'email.
                  </Typography>
                </Box>
                <Stack direction="row" spacing={1} alignItems="center">
                  {item.notifyBlockManagementEnabled && (
                    <Button
                      size="small" onClick={() => setEditingSiteId(editingSiteId === item.site.id ? null : item.site.id)}
                      sx={{ textTransform: "none", fontSize: 12.5, fontWeight: 600 }}
                    >
                      {editingSiteId === item.site.id ? "Fermer" : "Configurer"}
                    </Button>
                  )}
                  <SwitchPill
                    on={item.notifyBlockManagementEnabled}
                    disabled={blockManagementToggleMutation.isPending}
                    onClick={() => {
                      const nextEnabled = !item.notifyBlockManagementEnabled;
                      blockManagementToggleMutation.mutate({ siteId: item.site.id, notifyBlockManagementEnabled: nextEnabled });
                      if (nextEnabled) setEditingSiteId(item.site.id);
                    }}
                    ariaLabel={`Prévenir la gestion du bloc pour le site ${item.site.name}`}
                  />
                </Stack>
              </Stack>

              {item.notifyBlockManagementEnabled && editingSiteId === item.site.id && (
                <BlockManagementForm item={item} onSaved={invalidate} onClose={() => setEditingSiteId(null)} />
              )}
            </Box>
          ))}
        </Stack>
      )}
    </Box>
  );
}

function BlockManagementForm({
  item, onSaved, onClose,
}: {
  item: AbsenceCommunicationSiteSettingV2;
  onSaved: () => void;
  onClose: () => void;
}) {
  const toast = useToast();
  const [to, setTo] = React.useState(item.blockManagementEmailTo ?? "");
  const [cc, setCc] = React.useState<string[]>(item.blockManagementEmailCc);
  const [newCc, setNewCc] = React.useState("");
  const [delayDays, setDelayDays] = React.useState<string>(item.blockManagementDelayDays !== null ? String(item.blockManagementDelayDays) : "");

  const saveMutation = useMutation({
    mutationFn: () =>
      updateAbsenceCommunicationSettings(item.site.id, {
        blockManagementEmailTo: to.trim() === "" ? null : to.trim(),
        blockManagementEmailCc: cc,
        blockManagementDelayDays: delayDays.trim() === "" ? null : Number(delayDays),
      }),
    onSuccess: () => { toast.success("Réglage enregistré"); onSaved(); onClose(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  function addCc() {
    const address = newCc.trim();
    if (address === "" || !EMAIL_RE.test(address)) {
      toast.error("Adresse email invalide.");
      return;
    }
    if (cc.some((c) => c.toLowerCase() === address.toLowerCase()) || address.toLowerCase() === to.trim().toLowerCase()) {
      toast.error("Cette adresse figure déjà dans la liste.");
      return;
    }
    setCc([...cc, address]);
    setNewCc("");
  }

  const toValid = to.trim() !== "" && EMAIL_RE.test(to.trim());
  const delayValid = delayDays.trim() !== "" && Number.isInteger(Number(delayDays)) && Number(delayDays) >= 0 && Number(delayDays) <= 365;

  return (
    <Box sx={{ px: 2.25, py: 2, bgcolor: "#FAFBFC", borderTop: `1px solid ${planningV2Colors.divider}` }}>
      <Stack spacing={2}>
        <TextField
          label="Adresse principale (To)" size="small" fullWidth required
          value={to} onChange={(e) => setTo(e.target.value)}
          error={to.trim() !== "" && !toValid}
        />

        <Box>
          <Typography sx={{ fontSize: 12.5, fontWeight: 600, mb: 0.75 }}>Adresses en copie (CC)</Typography>
          <Stack spacing={0.75} sx={{ mb: 1 }}>
            {cc.map((address) => (
              <Stack key={address} direction="row" alignItems="center" spacing={1} sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.button, px: 1.25, py: 0.5 }}>
                <Typography sx={{ fontSize: 13, flex: 1 }}>{address}</Typography>
                <IconButton size="small" aria-label={`Supprimer ${address}`} onClick={() => setCc(cc.filter((c) => c !== address))}>
                  <CloseIcon sx={{ fontSize: 15 }} />
                </IconButton>
              </Stack>
            ))}
          </Stack>
          <Stack direction="row" spacing={1}>
            <TextField
              size="small" fullWidth placeholder="ajouter une adresse CC"
              value={newCc} onChange={(e) => setNewCc(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addCc(); } }}
            />
            <Button size="small" startIcon={<AddIcon sx={{ fontSize: 16 }} />} onClick={addCc} sx={{ textTransform: "none", whiteSpace: "nowrap" }}>
              Ajouter
            </Button>
          </Stack>
        </Box>

        <TextField
          label="Envoyer X jours avant le début du congé" size="small" type="number"
          value={delayDays} onChange={(e) => setDelayDays(e.target.value)}
          error={delayDays.trim() !== "" && !delayValid}
          slotProps={{ htmlInput: { min: 0, max: 365 } }}
          sx={{ maxWidth: 320 }}
        />

        <Stack direction="row" spacing={1} justifyContent="flex-end">
          <Button onClick={onClose} sx={{ textTransform: "none" }}>Annuler</Button>
          <Button
            variant="contained" disableElevation disabled={!toValid || !delayValid || saveMutation.isPending}
            onClick={() => saveMutation.mutate()}
            sx={{ textTransform: "none", bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
          >
            Enregistrer
          </Button>
        </Stack>
      </Stack>
    </Box>
  );
}

function IconBadge({ on, icon }: { on: boolean; icon: React.ReactNode }) {
  return (
    <Box sx={{
      width: 34, height: 34, borderRadius: planningV2Radii.button, flex: "none", display: "flex", alignItems: "center", justifyContent: "center",
      bgcolor: on ? planningV2Colors.infoBg : "#F1F4F7",
      color: on ? planningV2Colors.brand : planningV2Colors.textSecondary,
    }}>
      {icon}
    </Box>
  );
}

function SwitchPill({ on, disabled, onClick, ariaLabel }: { on: boolean; disabled?: boolean; onClick: () => void; ariaLabel: string }) {
  return (
    <Box
      component="button"
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={ariaLabel}
      onClick={onClick}
      disabled={disabled}
      sx={{
        width: 42, height: 24, borderRadius: planningV2Radii.pill, flex: "none", position: "relative", border: "none", padding: 0,
        cursor: disabled ? "default" : "pointer",
        bgcolor: on ? planningV2Colors.brand : "#E7EBEF", opacity: disabled ? 0.6 : 1, transition: "background .15s",
      }}
    >
      <Box sx={{
        position: "absolute", top: 2, left: on ? 21 : 2, width: 20, height: 20, borderRadius: "999px",
        bgcolor: "#fff", boxShadow: "0 1px 3px rgba(0,0,0,.2)", transition: "left .15s",
      }} />
    </Box>
  );
}
