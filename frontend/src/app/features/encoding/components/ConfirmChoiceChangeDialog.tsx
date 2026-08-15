import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";

const AMBER_50 = "#FEF6E7";
const AMBER_700 = "#B7791F";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";

function WarnIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={AMBER_700} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
      <path d="M12 9v4M12 17h.01" />
    </svg>
  );
}

type Props = {
  open: boolean;
  loading: boolean;
  /** Message exact renvoyé par le backend (ChoiceOptionChangeRequiresConfirmationException). */
  message: string;
  onClose: () => void;
  onConfirm: () => void;
};

/**
 * Tarification firme conditionnée à un choix obligatoire, §8 du prompt — changer le
 * choix sélectionné alors que du matériel désormais incompatible est déjà encodé exige
 * une confirmation explicite (jamais une suppression silencieuse). Déclenché par
 * ChoiceOptionChangeRequiresConfirmationException (409) sur PATCH .../interventions/{id}.
 */
export default function ConfirmChoiceChangeDialog({
  open,
  loading,
  message,
  onClose,
  onConfirm,
}: Props) {
  return (
    <SheetModal open={open} title="Changer la sélection ?" onClose={onClose} closeDisabled={loading}>
      <Box sx={{ mt: "18px", display: "flex", alignItems: "flex-start", gap: "12px", background: AMBER_50, borderRadius: "13px", padding: "14px" }}>
        <Box sx={{ flexShrink: 0, mt: "1px" }}><WarnIcon /></Box>
        <Box sx={{ fontSize: 13.5, color: GRAY_700, lineHeight: 1.5 }}>{message}</Box>
      </Box>

      <Box
        component="button"
        type="button"
        onClick={onConfirm}
        disabled={loading}
        sx={{
          mt: "20px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer",
          "&:hover": { background: GREEN_700 }, "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default" },
        }}
      >
        {loading ? "…" : "Changer"}
      </Box>
      <Box
        component="button"
        type="button"
        onClick={onClose}
        disabled={loading}
        sx={{ mt: "8px", width: "100%", height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
      >
        Annuler
      </Box>
    </SheetModal>
  );
}
