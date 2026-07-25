import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";

const RED_50 = "#FDEEEE";
const RED_600 = "#E5484D";
const RED_700 = "#C93A3F";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";

function WarnIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={RED_600} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
      <path d="M12 9v4M12 17h.01" />
    </svg>
  );
}

type Props = {
  open: boolean;
  loading: boolean;
  title: string;
  message: string;
  onClose: () => void;
  onConfirm: () => void;
  /** Topic d'aide contextuel — omis par défaut, ce composant est réutilisé hors encodage. */
  helpTopicId?: string;
};

export default function ConfirmDeleteDialog({
  open,
  loading,
  title,
  message,
  onClose,
  onConfirm,
  helpTopicId,
}: Props) {
  return (
    <SheetModal open={open} title={title} onClose={onClose} closeDisabled={loading} helpTopicId={helpTopicId}>
      <Box sx={{ mt: "18px", display: "flex", alignItems: "flex-start", gap: "12px", background: RED_50, borderRadius: "13px", padding: "14px" }}>
        <Box sx={{ flexShrink: 0, mt: "1px" }}><WarnIcon /></Box>
        <Box>
          <Box sx={{ fontSize: 14, fontWeight: 700, color: GRAY_700 }}>Cette action est irréversible.</Box>
          {message && (
            <Box sx={{ mt: "3px", fontSize: 13.5, color: GRAY_500 }}>{message}</Box>
          )}
        </Box>
      </Box>

      <Box
        component="button"
        type="button"
        onClick={onConfirm}
        disabled={loading}
        sx={{
          mt: "20px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: RED_600, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer",
          "&:hover": { background: RED_700 }, "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default" },
        }}
      >
        {loading ? "…" : "Supprimer"}
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
