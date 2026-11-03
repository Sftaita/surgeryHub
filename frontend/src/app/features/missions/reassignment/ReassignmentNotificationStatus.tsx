import * as React from "react";
import { Alert, Stack, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import {
  deliveryLabel,
  fetchReassignmentOperation,
  ROLE_LABEL,
  type ReassignmentResult,
} from "./reassignment.api";

const MAX_POLLS = 15;

/**
 * D-181 — qui a réellement été averti, et comment : lu auprès du serveur (traces du
 * NotificationDispatcher), jamais supposé. Rafraîchi quelques secondes, le temps que le
 * worker traite l'opération ; « envoyé » = accepté par le transport, pas lu.
 */
export function ReassignmentNotificationStatus({ result }: { result: ReassignmentResult }) {
  const polls = React.useRef(0);
  const query = useQuery({
    queryKey: ["reassignment-operation", result.operationId],
    queryFn: () => {
      polls.current += 1;
      return fetchReassignmentOperation(result.operationId);
    },
    enabled: result.recipients.length > 0,
    refetchInterval: (q) => {
      const rows = q.state.data ?? [];
      const settled = rows.length >= result.recipients.length && rows.every((r) => r.email !== null && r.email.status !== "QUEUED");
      return settled || polls.current >= MAX_POLLS ? false : 2000;
    },
  });

  if (result.recipients.length === 0) return null;
  const byUser = new Map((query.data ?? []).map((r) => [r.userId, r]));

  return (
    <Stack spacing={0.75} data-testid="reassignment-notification-status">
      <Typography variant="subtitle2" fontWeight={700}>Notifications</Typography>
      {result.recipients.map((r) => {
        const status = byUser.get(r.userId);
        const failed = status?.email?.status === "FAILED" || status?.email?.status === "SKIPPED";
        return (
          <Typography key={r.userId} variant="body2" color={failed ? "warning.dark" : "text.primary"} data-testid={`reassignment-recipient-${r.userId}`}>
            <strong>{r.name}</strong> ({r.roles.map((role) => ROLE_LABEL[role]).join(", ")}) — e-mail : {deliveryLabel(status?.email ?? null)}
            {status?.inApp?.status === "SENT" ? " · notification dans l'application : envoyée" : ""}
          </Typography>
        );
      })}
      {query.isError && <Alert severity="warning">Statut des notifications momentanément indisponible.</Alert>}
    </Stack>
  );
}
