import * as React from "react";
import { Alert, Box, CircularProgress, Divider, Drawer, IconButton, Stack, Typography } from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import { useQuery } from "@tanstack/react-query";

import { getAbsenceCommunicationDetail, extractErrorV2 } from "../api/planningV2.api";
import type { AbsenceCommunicationDeliveryV2, AbsenceCommunicationGlobalStatusV2, AbsenceCommunicationTypeV2 } from "../api/planningV2.types";
import { planningV2Colors, planningV2Radii, alertSeverityTokens } from "../theme/tokens";

const DRAWER_WIDTH = 480;

const TYPE_LABELS: Record<AbsenceCommunicationTypeV2, string> = {
  ROOM_RELEASE: "Libération de salle",
  BLOCK_MANAGEMENT_ABSENCE: "Congé — gestion du bloc",
  BLOCK_MANAGEMENT_MODIFICATION: "Modification de congé",
  BLOCK_MANAGEMENT_CANCELLATION: "Annulation de congé",
};

const STATUS_LABELS: Record<AbsenceCommunicationGlobalStatusV2, string> = {
  SCHEDULED: "Programmé", SENT: "Envoyé", FAILED: "Échec", CANCELLED: "Annulé",
};

const STATUS_TOKENS: Record<AbsenceCommunicationGlobalStatusV2, { fg: string; bg: string }> = {
  SCHEDULED: alertSeverityTokens.info,
  SENT: alertSeverityTokens.ok,
  FAILED: alertSeverityTokens.crit,
  CANCELLED: { fg: planningV2Colors.textSecondary, bg: "#F1F4F7" },
};

function fmt(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString("fr-BE") : "—";
}

/**
 * Communication des absences chirurgiens — Lot C (D-114), §18/§20 de la demande. Détail
 * d'une communication : contexte, email exact, deliveries. Reste entièrement exploitable
 * même si l'Absence source a été supprimée entretemps (`absenceId` peut être `null`, tout le
 * reste vient des snapshots — jamais une dépendance à l'existence de l'Absence).
 */
export function AbsenceCommunicationJournalDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const query = useQuery({
    queryKey: ["planning-v2", "absence-communication-detail", id],
    queryFn: () => getAbsenceCommunicationDetail(id!),
    enabled: id !== null,
  });

  const detail = query.data;

  return (
    <Drawer anchor="right" open={id !== null} onClose={onClose} slotProps={{ paper: { sx: { width: DRAWER_WIDTH } } }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 2.25, py: 1.75, borderBottom: `1px solid ${planningV2Colors.divider}` }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700 }}>
          {detail ? TYPE_LABELS[detail.type] : "Détail de la communication"}
        </Typography>
        <IconButton size="small" onClick={onClose} aria-label="Fermer"><CloseIcon fontSize="small" /></IconButton>
      </Stack>

      <Box sx={{ flex: 1, overflowY: "auto", px: 2.25, py: 2 }}>
        {query.isLoading ? (
          <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}><CircularProgress size={22} /></Box>
        ) : query.isError ? (
          <Alert severity="error">{extractErrorV2(query.error)}</Alert>
        ) : detail ? (
          <Stack spacing={2.5}>
            <Section title="Contexte">
              <Field label="Chirurgien" value={detail.surgeon?.name ?? "—"} />
              <Field label="Site" value={detail.site?.name ?? "—"} />
              <Field label="Période du congé" value={`${new Date(detail.absenceDateStart + "T00:00:00").toLocaleDateString("fr-BE")} → ${new Date(detail.absenceDateEnd + "T00:00:00").toLocaleDateString("fr-BE")}`} />
              <Field label="Révision" value={String(detail.revisionNumber)} />
              <Field label="Créé le" value={fmt(detail.createdAt)} />
              {!detail.absenceId && (
                <Alert severity="info" sx={{ mt: 0.5, fontSize: 12.5 }}>
                  L'absence d'origine a été supprimée depuis — cette communication reste consultable via son historique.
                </Alert>
              )}
            </Section>

            <Divider />

            <Section title="Email">
              <Field label="Objet" value={detail.subject} />
              {detail.replyTo && <Field label="Reply-To" value={detail.replyTo} />}
              <Box sx={{ mt: 1 }}>
                <Typography sx={{ fontSize: 11.5, fontWeight: 700, color: planningV2Colors.textMuted, textTransform: "uppercase", letterSpacing: "0.04em", mb: 0.5 }}>
                  Corps
                </Typography>
                <Box sx={{ bgcolor: "#FAFBFC", border: `1px solid ${planningV2Colors.divider}`, borderRadius: planningV2Radii.button, p: 1.5, whiteSpace: "pre-wrap", fontSize: 13 }}>
                  {detail.body}
                </Box>
              </Box>
            </Section>

            <Divider />

            <Section title={`Deliveries (${detail.deliveries.length})`}>
              <Stack spacing={1.25}>
                {detail.deliveries.map((d) => (
                  <DeliveryCard key={d.id} delivery={d} />
                ))}
              </Stack>
            </Section>
          </Stack>
        ) : null}
      </Box>
    </Drawer>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Box>
      <Typography sx={{ fontSize: 12.5, fontWeight: 700, color: planningV2Colors.textStrong, mb: 1 }}>{title}</Typography>
      <Stack spacing={0.75}>{children}</Stack>
    </Box>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <Stack direction="row" spacing={1} justifyContent="space-between">
      <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted }}>{label}</Typography>
      <Typography sx={{ fontSize: 13, fontWeight: 600, textAlign: "right" }}>{value}</Typography>
    </Stack>
  );
}

function DeliveryCard({ delivery }: { delivery: AbsenceCommunicationDeliveryV2 }) {
  const t = STATUS_TOKENS[delivery.status];
  return (
    <Box sx={{ border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.button, p: 1.25 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 0.5 }}>
        <Typography sx={{ fontSize: 13, fontWeight: 700 }}>{delivery.to}</Typography>
        <Box sx={{ bgcolor: t.bg, color: t.fg, fontWeight: 700, fontSize: 11, px: 0.9, py: 0.2, borderRadius: planningV2Radii.pill }}>
          {STATUS_LABELS[delivery.status]}
        </Box>
      </Stack>
      {delivery.cc.length > 0 && (
        <Typography sx={{ fontSize: 12, color: planningV2Colors.textMuted }}>CC : {delivery.cc.join(", ")}</Typography>
      )}
      <Typography sx={{ fontSize: 11.5, color: planningV2Colors.textSecondary, mt: 0.5 }}>
        {delivery.attemptCount} tentative{delivery.attemptCount > 1 ? "s" : ""}
        {delivery.scheduledAt && ` · programmé ${fmt(delivery.scheduledAt)}`}
        {delivery.sentAt && ` · envoyé ${fmt(delivery.sentAt)}`}
        {delivery.cancelledAt && ` · annulé ${fmt(delivery.cancelledAt)}`}
      </Typography>
      {delivery.lastError && (
        <Typography sx={{ fontSize: 12, color: alertSeverityTokens.crit.fg, mt: 0.5 }}>{delivery.lastError}</Typography>
      )}
    </Box>
  );
}
