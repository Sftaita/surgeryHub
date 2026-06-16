import { Chip } from "@mui/material";
import type { InvitationStatus } from "../api/admin.types";

const CONFIG: Record<InvitationStatus, { label: string; color: "success" | "warning" | "default" | "error" | "info" }> = {
  used:            { label: "Activé",           color: "success" },
  pending:         { label: "En attente",        color: "info" },
  expired:         { label: "Expiré",            color: "warning" },
  email_not_sent:  { label: "Email non envoyé",  color: "error" },
  none:            { label: "Aucune invitation", color: "default" },
};

interface Props {
  status: InvitationStatus;
  size?: "small" | "medium";
}

export function InvitationStatusChip({ status, size = "small" }: Props) {
  const { label, color } = CONFIG[status] ?? CONFIG.none;
  return <Chip label={label} color={color} size={size} variant="outlined" />;
}
