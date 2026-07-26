import * as React from "react";
import {
  Alert,
  Box,
  Chip,
  CircularProgress,
  Divider,
  Drawer,
  IconButton,
  Stack,
  Tab,
  Tabs,
  Tooltip,
  Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import { useQuery } from "@tanstack/react-query";
import { getAdminOutboundNotification } from "../api/admin.api";
import type { OutboundNotificationDetail, OutboundNotificationStatus } from "../api/admin.types";

const DRAWER_WIDTH = 480;

interface Props {
  id: number | null;
  onClose: () => void;
}

const STATUS_LABELS: Record<OutboundNotificationStatus, string> = {
  QUEUED: "En attente",
  SENT: "Accepté par le fournisseur",
  FAILED: "Échec",
  SKIPPED: "Non envoyé",
};

const FALLBACK_REASON_LABELS: Record<string, string> = {
  NO_SUBSCRIPTION: "Aucun abonnement Push",
  EXPIRED: "Abonnement(s) Push expiré(s)",
  ALL_FAILED: "Tous les envois Push ont échoué",
  PUSH_DISABLED: "Notifications Push désactivées",
};

function statusColor(status: OutboundNotificationStatus): "default" | "success" | "error" | "warning" {
  switch (status) {
    case "SENT": return "success";
    case "FAILED": return "error";
    case "SKIPPED": return "warning";
    default: return "default";
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
    hour: "2-digit", minute: "2-digit", second: "2-digit",
  });
}

export function AdminOutboundNotificationDrawer({ id, onClose }: Props) {
  const [tab, setTab] = React.useState<"text" | "html">("text");

  React.useEffect(() => {
    setTab("text");
  }, [id]);

  const query = useQuery({
    queryKey: ["admin-outbound-notification", id],
    queryFn: () => getAdminOutboundNotification(id!),
    enabled: id !== null,
  });

  const n: OutboundNotificationDetail | null = query.data ?? null;

  return (
    <Drawer
      anchor="right"
      open={id !== null}
      onClose={onClose}
      PaperProps={{ sx: { width: DRAWER_WIDTH, p: 0 } }}
    >
      <Stack sx={{ height: "100%", overflow: "hidden" }}>
        <Stack
          direction="row"
          alignItems="center"
          justifyContent="space-between"
          sx={{ px: 3, py: 2, borderBottom: "1px solid", borderColor: "divider" }}
        >
          <Typography variant="h6">Notification</Typography>
          <IconButton onClick={onClose} size="small" aria-label="Fermer"><CloseIcon /></IconButton>
        </Stack>

        <Box sx={{ flex: 1, overflowY: "auto", px: 3, py: 2 }}>
          {query.isLoading && (
            <Box sx={{ display: "flex", justifyContent: "center", mt: 4 }}>
              <CircularProgress size={28} />
            </Box>
          )}

          {query.isError && <Alert severity="error">Impossible de charger cette notification.</Alert>}

          {n && (
            <Stack spacing={2}>
              <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                <Chip size="small" label={n.channel === "PUSH" ? "Push" : "Email"} variant="outlined" />
                <Chip size="small" label={STATUS_LABELS[n.status]} color={statusColor(n.status)} />
                {n.fallback && <Chip size="small" label="Repli email" variant="outlined" />}
              </Stack>

              <Stack direction="row" spacing={0.5} alignItems="center">
                <Typography variant="caption" color="text.secondary">
                  « {STATUS_LABELS.SENT} » ne garantit pas que le message a été lu.
                </Typography>
                <Tooltip title="Aucun accusé de lecture n'est disponible pour Push ou email dans SurgicalHub.">
                  <HelpOutlineIcon fontSize="inherit" sx={{ color: "text.secondary" }} />
                </Tooltip>
              </Stack>

              <Divider />

              <Box>
                <Typography variant="overline" color="text.secondary">Destinataire</Typography>
                <Typography variant="body2">{n.recipient?.name ?? "—"}</Typography>
                <Typography variant="body2" color="text.secondary">{n.recipient?.email ?? "—"}</Typography>
              </Box>

              <Box>
                <Typography variant="overline" color="text.secondary">Type</Typography>
                <Typography variant="body2" sx={{ fontFamily: "monospace" }}>{n.notificationType}</Typography>
              </Box>

              {n.missionId && (
                <Box>
                  <Typography variant="overline" color="text.secondary">Ressource liée</Typography>
                  <Typography variant="body2">Mission #{n.missionId}</Typography>
                </Box>
              )}

              <Box>
                <Typography variant="overline" color="text.secondary">Chronologie</Typography>
                <Stack spacing={0.25}>
                  <Typography variant="body2" color="text.secondary">Créée : {formatDate(n.createdAt)}</Typography>
                  {n.queuedAt && <Typography variant="body2" color="text.secondary">Mise en file : {formatDate(n.queuedAt)}</Typography>}
                  {n.sentAt && <Typography variant="body2" color="text.secondary">Acceptée : {formatDate(n.sentAt)}</Typography>}
                  {n.failedAt && <Typography variant="body2" color="text.secondary">Échec : {formatDate(n.failedAt)}</Typography>}
                </Stack>
              </Box>

              {n.fallback && n.fallbackReason && (
                <Box>
                  <Typography variant="overline" color="text.secondary">Raison du repli</Typography>
                  <Typography variant="body2">{FALLBACK_REASON_LABELS[n.fallbackReason] ?? n.fallbackReason}</Typography>
                </Box>
              )}

              {(n.failureCode || n.failureMessage) && (
                <Alert severity="error" sx={{ py: 0.5 }}>
                  {n.failureMessage ?? n.failureCode}
                </Alert>
              )}

              <Divider />

              <Box>
                <Typography variant="overline" color="text.secondary">Contenu</Typography>
                {n.subject && <Typography variant="subtitle2" sx={{ mt: 0.5 }}>{n.subject}</Typography>}
                {n.title && <Typography variant="subtitle2" sx={{ mt: 0.5 }}>{n.title}</Typography>}

                {n.bodyHtml ? (
                  <>
                    <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ minHeight: 32, mt: 1 }}>
                      <Tab value="text" label="Texte" sx={{ minHeight: 32, py: 0 }} />
                      <Tab value="html" label="Aperçu HTML" sx={{ minHeight: 32, py: 0 }} />
                    </Tabs>
                    {tab === "text" ? (
                      <Typography variant="body2" sx={{ whiteSpace: "pre-line", mt: 1 }}>
                        {n.bodyText ?? "—"}
                      </Typography>
                    ) : (
                      // Sandboxed with an EMPTY sandbox attribute — no scripts, no
                      // same-origin access, no forms, no popups. This project has no
                      // HTML-sanitization library installed (checked before writing
                      // this); an unprivileged iframe is the safe option that needs
                      // no new dependency (D-084).
                      <Box
                        component="iframe"
                        title="Aperçu email"
                        srcDoc={n.bodyHtml}
                        sandbox=""
                        sx={{ width: "100%", height: 320, border: "1px solid", borderColor: "divider", borderRadius: 1, mt: 1 }}
                      />
                    )}
                  </>
                ) : (
                  <Typography variant="body2" sx={{ whiteSpace: "pre-line", mt: 1 }}>
                    {n.bodyText ?? "—"}
                  </Typography>
                )}
              </Box>

              {n.payload && Object.keys(n.payload).length > 0 && (
                <Box>
                  <Typography variant="overline" color="text.secondary">Données associées</Typography>
                  <Stack spacing={0.25}>
                    {Object.entries(n.payload).map(([key, value]) => (
                      <Typography key={key} variant="body2" sx={{ fontFamily: "monospace" }}>
                        {key}: {String(value)}
                      </Typography>
                    ))}
                  </Stack>
                </Box>
              )}

              <Divider />

              <Box>
                <Typography variant="overline" color="text.secondary">
                  Tentatives ({n.attempts.length})
                </Typography>
                <Stack spacing={1} sx={{ mt: 0.5 }}>
                  {n.attempts.length === 0 ? (
                    <Typography variant="body2" color="text.secondary">Aucune tentative.</Typography>
                  ) : (
                    n.attempts.map((a) => (
                      <Box
                        key={a.attemptNumber}
                        sx={{ border: "1px solid", borderColor: "divider", borderRadius: 1, p: 1 }}
                      >
                        <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between">
                          <Typography variant="caption" color="text.secondary">#{a.attemptNumber}</Typography>
                          <Chip
                            size="small"
                            label={a.success ? "Réussie" : "Échouée"}
                            color={a.success ? "success" : "error"}
                          />
                        </Stack>
                        <Typography variant="caption" color="text.secondary" sx={{ display: "block" }}>
                          {formatDate(a.startedAt)}{a.provider ? ` — ${a.provider}` : ""}{a.statusCode ? ` — HTTP ${a.statusCode}` : ""}
                        </Typography>
                        {a.reason && (
                          <Typography variant="caption" sx={{ display: "block" }}>{a.reason}</Typography>
                        )}
                      </Box>
                    ))
                  )}
                </Stack>
              </Box>
            </Stack>
          )}
        </Box>
      </Stack>
    </Drawer>
  );
}
