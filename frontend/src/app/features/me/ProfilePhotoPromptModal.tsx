import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import { uploadProfilePicture } from "./api/me.api";
import { useAuth } from "../../auth/AuthContext";
import { useToast } from "../../ui/toast/useToast";
import { AvatarUploader } from "../../ui/avatar/AvatarUploader";

export function ProfilePhotoPromptModal({
  open,
  onDismiss,
}: {
  open: boolean;
  onDismiss: () => void;
}) {
  const { state, refreshUser } = useAuth();
  const toast = useToast();

  const name =
    state.status === "authenticated"
      ? [state.user.firstname, state.user.lastname].filter(Boolean).join(" ").trim() || "Utilisateur"
      : "Utilisateur";

  async function handleFileReady(file: File) {
    await uploadProfilePicture(file);
    toast.success("Photo de profil ajoutée.");
    await refreshUser();
    onDismiss();
  }

  return (
    <Dialog open={open} onClose={onDismiss} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ display: "flex", alignItems: "center", justifyContent: "space-between", pr: 1 }}>
        Ajoutez votre photo de profil
        <IconButton aria-label="Fermer" size="small" onClick={onDismiss}>
          <CloseIcon fontSize="small" />
        </IconButton>
      </DialogTitle>
      <DialogContent>
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 2, py: 1 }}>
          <AvatarUploader
            name={name}
            size="xl"
            onFileReady={handleFileReady}
            helperText="JPEG, PNG ou WebP — 5 Mo max."
          />
          <Typography variant="body2" color="text.secondary" textAlign="center">
            Votre photo facilite l'identification dans les plannings et les missions.
          </Typography>
        </Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onDismiss} color="inherit">
          Plus tard
        </Button>
      </DialogActions>
    </Dialog>
  );
}
