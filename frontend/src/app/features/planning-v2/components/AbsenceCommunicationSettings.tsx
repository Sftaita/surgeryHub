import * as React from "react";
import { Alert, Box, Button, CircularProgress, Stack, TextField, Typography } from "@mui/material";
import CampaignOutlinedIcon from "@mui/icons-material/CampaignOutlined";
import MarkEmailReadOutlinedIcon from "@mui/icons-material/MarkEmailReadOutlined";
import OpenInNewIcon from "@mui/icons-material/OpenInNew";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";

import { getAbsenceCommunicationSettings, updateAbsenceCommunicationSettings, extractErrorV2 } from "../api/planningV2.api";
import type { AbsenceCommunicationSiteSettingV2 } from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

/**
 * Communication des absences chirurgiens — Lot A/B (D-114), revue post-déploiement. Deux
 * réglages indépendants par site : « Libération de salle » (Lot A, toggle simple, comportement
 * inchangé) et « Gestion du bloc » (Lot B) — ce dernier ne porte plus ici que le comportement
 * (activé/désactivé + délai). Les coordonnées (To/CC) sont des données d'établissement,
 * éditées depuis la fiche établissement (Établissements → Modifier), affichées ici en lecture
 * seule uniquement.
 *
 * Le toggle n'envoie plus jamais un PATCH `{notifyBlockManagementEnabled: true}` isolé : le
 * backend exige un contact établissement valide dès que ce champ passe à `true`, ce qu'un
 * site jamais configuré ne peut structurellement pas satisfaire (verrou circulaire corrigé,
 * voir l'audit). Activer un site sans contact affiche un message explicite avec un lien direct
 * vers la fiche établissement, jamais le toast technique du backend.
 */
export function AbsenceCommunicationSettings() {
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [editingSiteId, setEditingSiteId] = React.useState<number | null>(null);
  const [blockedSiteId, setBlockedSiteId] = React.useState<number | null>(null);

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

  const blockManagementToggleOffMutation = useMutation({
    mutationFn: (siteId: number) => updateAbsenceCommunicationSettings(siteId, { notifyBlockManagementEnabled: false }),
    onSuccess: () => { toast.success("Réglage enregistré"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const items = React.useMemo(
    () => [...(settingsQuery.data?.items ?? [])].sort((a, b) => a.site.name.localeCompare(b.site.name)),
    [settingsQuery.data],
  );

  function handleBlockManagementToggle(item: AbsenceCommunicationSiteSettingV2) {
    if (item.notifyBlockManagementEnabled) {
      blockManagementToggleOffMutation.mutate(item.site.id);
      setEditingSiteId(null);
      setBlockedSiteId(null);
      return;
    }
    // Activation : jamais de PATCH toggle-seul — le backend exige un contact établissement
    // valide au moment même où ce champ passerait à true, ce qu'un site jamais configuré ne
    // peut pas satisfaire. On ouvre soit le formulaire de délai (contact déjà présent), soit
    // un message bloquant explicite (contact manquant) — jamais le toast technique brut.
    if (!item.blockManagementContactEmail) {
      setBlockedSiteId(item.site.id);
      setEditingSiteId(null);
      return;
    }
    setEditingSiteId(item.site.id);
    setBlockedSiteId(null);
  }

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
                  <ContactSummary item={item} navigate={navigate} />
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
                    disabled={blockManagementToggleOffMutation.isPending}
                    onClick={() => handleBlockManagementToggle(item)}
                    ariaLabel={`Prévenir la gestion du bloc pour le site ${item.site.name}`}
                  />
                </Stack>
              </Stack>

              {blockedSiteId === item.site.id && (
                <Box sx={{ px: 2.25, py: 2, bgcolor: "#FAFBFC", borderTop: `1px solid ${planningV2Colors.divider}` }}>
                  <Alert severity="warning" action={
                    <Button
                      size="small" color="inherit" endIcon={<OpenInNewIcon sx={{ fontSize: 15 }} />}
                      onClick={() => navigate(`/app/m/hospitals?edit=${item.site.id}`)}
                      sx={{ textTransform: "none", fontWeight: 600, whiteSpace: "nowrap" }}
                    >
                      Configurer l'établissement
                    </Button>
                  }>
                    Configurez d'abord l'adresse de la gestion du bloc dans la fiche de l'établissement.
                  </Alert>
                </Box>
              )}

              {item.notifyBlockManagementEnabled && editingSiteId === item.site.id && (
                <BlockManagementDelayForm item={item} onSaved={invalidate} onClose={() => setEditingSiteId(null)} />
              )}
              {!item.notifyBlockManagementEnabled && editingSiteId === item.site.id && (
                <BlockManagementActivateForm item={item} onSaved={invalidate} onClose={() => setEditingSiteId(null)} />
              )}
            </Box>
          ))}
        </Stack>
      )}
    </Box>
  );
}

function ContactSummary({ item, navigate }: { item: AbsenceCommunicationSiteSettingV2; navigate: ReturnType<typeof useNavigate> }) {
  return (
    <Box sx={{ mt: 1, fontSize: 12.5 }}>
      {item.blockManagementContactEmail ? (
        <Stack spacing={0.25}>
          <Typography sx={{ fontSize: 12.5 }}>
            <Box component="span" sx={{ color: planningV2Colors.textMuted }}>Adresse principale : </Box>
            <Box component="span" sx={{ fontWeight: 600 }}>{item.blockManagementContactEmail}</Box>
          </Typography>
          {item.blockManagementContactCc.length > 0 && (
            <Typography sx={{ fontSize: 12.5 }}>
              <Box component="span" sx={{ color: planningV2Colors.textMuted }}>Copies : </Box>
              {item.blockManagementContactCc.join(", ")}
            </Typography>
          )}
        </Stack>
      ) : (
        <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, fontStyle: "italic" }}>
          Aucun contact configuré pour cet établissement.
        </Typography>
      )}
      <Button
        size="small" onClick={() => navigate(`/app/m/hospitals?edit=${item.site.id}`)}
        sx={{ textTransform: "none", fontSize: 12, fontWeight: 600, px: 0, mt: 0.25, minWidth: 0 }}
      >
        Modifier les contacts de l'établissement
      </Button>
    </Box>
  );
}

/** Site déjà activé (contact déjà valide, forcément) — ne modifie plus que le délai. */
function BlockManagementDelayForm({
  item, onSaved, onClose,
}: {
  item: AbsenceCommunicationSiteSettingV2;
  onSaved: () => void;
  onClose: () => void;
}) {
  const toast = useToast();
  const [delayDays, setDelayDays] = React.useState<string>(item.blockManagementDelayDays !== null ? String(item.blockManagementDelayDays) : "");

  const saveMutation = useMutation({
    mutationFn: () =>
      updateAbsenceCommunicationSettings(item.site.id, {
        blockManagementDelayDays: delayDays.trim() === "" ? null : Number(delayDays),
      }),
    onSuccess: () => { toast.success("Réglage enregistré"); onSaved(); onClose(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const delayValid = delayDays.trim() !== "" && Number.isInteger(Number(delayDays)) && Number(delayDays) >= 0 && Number(delayDays) <= 365;

  return (
    <Box sx={{ px: 2.25, py: 2, bgcolor: "#FAFBFC", borderTop: `1px solid ${planningV2Colors.divider}` }}>
      <Stack spacing={2}>
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
            variant="contained" disableElevation disabled={!delayValid || saveMutation.isPending}
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

/**
 * Première activation d'un site dont le contact établissement est déjà valide (sinon le
 * message bloquant s'affiche à la place, voir `handleBlockManagementToggle`). Envoie un
 * unique PATCH complet (`notifyBlockManagementEnabled: true` + délai) — jamais le toggle seul.
 */
function BlockManagementActivateForm({
  item, onSaved, onClose,
}: {
  item: AbsenceCommunicationSiteSettingV2;
  onSaved: () => void;
  onClose: () => void;
}) {
  const toast = useToast();
  const [delayDays, setDelayDays] = React.useState<string>(item.blockManagementDelayDays !== null ? String(item.blockManagementDelayDays) : "");

  const saveMutation = useMutation({
    mutationFn: () =>
      updateAbsenceCommunicationSettings(item.site.id, {
        notifyBlockManagementEnabled: true,
        blockManagementDelayDays: delayDays.trim() === "" ? null : Number(delayDays),
      }),
    onSuccess: () => { toast.success("Réglage enregistré"); onSaved(); onClose(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const delayValid = delayDays.trim() !== "" && Number.isInteger(Number(delayDays)) && Number(delayDays) >= 0 && Number(delayDays) <= 365;

  return (
    <Box sx={{ px: 2.25, py: 2, bgcolor: "#FAFBFC", borderTop: `1px solid ${planningV2Colors.divider}` }}>
      <Stack spacing={2}>
        <Alert severity="info" sx={{ fontSize: 12.5 }}>
          Utilisera le contact établissement actuel : <strong>{item.blockManagementContactEmail}</strong>
          {item.blockManagementContactCc.length > 0 && <> (copie : {item.blockManagementContactCc.join(", ")})</>}.
        </Alert>
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
            variant="contained" disableElevation disabled={!delayValid || saveMutation.isPending}
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
