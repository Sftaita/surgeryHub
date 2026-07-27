import { Chip, type ChipProps } from "@mui/material";

export type StatusBadgeConfig = {
  label: string;
  color: ChipProps["color"];
  variant?: ChipProps["variant"];
};

type StatusBadgeProps<T extends string> = {
  status: T;
  config: Record<T, StatusBadgeConfig>;
  size?: ChipProps["size"];
  onClick?: () => void;
  sx?: ChipProps["sx"];
};

/**
 * Chip statut générique — un `status` + une table `{status: {label, color}}`.
 * Généralisé depuis `InvitationStatusChip` (le seul exemple déjà correct
 * dans le projet), remplace les switch/ternaires dupliqués par page.
 */
export function StatusBadge<T extends string>({ status, config, size = "small", onClick, sx }: StatusBadgeProps<T>) {
  const entry = config[status];
  return (
    <Chip
      label={entry?.label ?? status}
      color={entry?.color ?? "default"}
      variant={entry?.variant}
      size={size}
      onClick={onClick}
      sx={onClick ? { cursor: "pointer", ...sx } : sx}
    />
  );
}

type ActiveBadgeProps = {
  active: boolean;
  activeLabel?: string;
  inactiveLabel?: string;
  size?: ChipProps["size"];
  /** Filled quand actif / outlined quand inactif — comportement historique de FirmsPage. */
  filledWhenActive?: boolean;
  onClick?: () => void;
  sx?: ChipProps["sx"];
};

/** Cas le plus fréquent du projet (5+ occurrences dupliquées) : actif/inactif. */
export function ActiveBadge({
  active,
  activeLabel = "Actif",
  inactiveLabel = "Inactif",
  size = "small",
  filledWhenActive = false,
  onClick,
  sx,
}: ActiveBadgeProps) {
  return (
    <StatusBadge
      status={active ? "active" : "inactive"}
      config={{
        active: { label: activeLabel, color: "success", variant: filledWhenActive ? "filled" : undefined },
        inactive: { label: inactiveLabel, color: "default", variant: filledWhenActive ? "outlined" : undefined },
      }}
      size={size}
      onClick={onClick}
      sx={sx}
    />
  );
}

export default StatusBadge;
