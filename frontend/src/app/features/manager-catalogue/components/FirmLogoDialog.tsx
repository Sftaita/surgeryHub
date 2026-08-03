import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Typography } from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { AvatarUploader } from "../../../ui/avatar/AvatarUploader";
import { resolveApiAssetUrl } from "../../../api/apiAssetUrl";
import { useToast } from "../../../ui/toast/useToast";
import { uploadFirmLogo, deleteFirmLogo } from "../api/catalogue.api";
import type { FirmDTO } from "../api/catalogue.types";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? "Erreur inconnue";
}

/**
 * Ajouter/remplacer/supprimer/prévisualiser le logo d'une firme — le logo est une
 * propriété exclusive de Firm, jamais dupliquée sur une prestation/un matériel/une
 * facture (docs/design/screens/catalogue-prestations/README.md, écran 11). Réutilise
 * AvatarUploader tel quel (déjà générique nom+photo, upload+suppression).
 */
export function FirmLogoDialog({ open, onClose, firm }: { open: boolean; onClose: () => void; firm: FirmDTO }) {
  const qc = useQueryClient();
  const toast = useToast();

  const invalidate = () => qc.invalidateQueries({ queryKey: ["firms"] });

  const uploadMutation = useMutation({
    mutationFn: (file: File) => uploadFirmLogo(firm.id, file),
    onSuccess: () => { toast.success("Logo mis à jour"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const deleteMutation = useMutation({
    mutationFn: () => deleteFirmLogo(firm.id),
    onSuccess: () => { toast.success("Logo supprimé"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>Logo — {firm.name}</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          Le logo appartient à la firme : réutilisé partout où elle apparaît (sélecteur, référentiel, détail de prestation…), jamais dupliqué par écran.
        </Typography>
        <Box sx={{ display: "flex", justifyContent: "center", py: 1 }}>
          <AvatarUploader
            name={firm.name}
            photoUrl={resolveApiAssetUrl(firm.logoPath)}
            size="xl"
            onFileReady={async (file) => { await uploadMutation.mutateAsync(file); }}
            onRemove={firm.logoPath ? () => deleteMutation.mutate() : undefined}
            helperText="JPEG, PNG ou WebP — 5 Mo max."
          />
        </Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} variant="contained" disableElevation>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}
