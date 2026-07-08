import * as React from "react";
import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Slider,
  Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import RestartAltIcon from "@mui/icons-material/RestartAlt";
import Cropper, { type Area, type Point } from "react-easy-crop";

import { getCroppedImageBlob } from "./cropImage";

interface AvatarCropDialogProps {
  open: boolean;
  imageSrc: string | null;
  fileName: string;
  mimeType: string;
  onCancel: () => void;
  onConfirm: (file: File) => void;
}

const INITIAL_CROP: Point = { x: 0, y: 0 };
const INITIAL_ZOOM = 1;

/**
 * Square crop step shown between file selection and upload — keeps every avatar
 * consistently framed regardless of the source photo's aspect ratio.
 * react-easy-crop is headless (no built-in chrome), so it drops straight into
 * our own MUI Dialog instead of fighting a canvas editor's own UI.
 */
export function AvatarCropDialog({
  open,
  imageSrc,
  fileName,
  mimeType,
  onCancel,
  onConfirm,
}: AvatarCropDialogProps) {
  const [crop, setCrop] = React.useState<Point>(INITIAL_CROP);
  const [zoom, setZoom] = React.useState(INITIAL_ZOOM);
  const [croppedAreaPixels, setCroppedAreaPixels] = React.useState<Area | null>(null);
  const [isProcessing, setIsProcessing] = React.useState(false);

  React.useEffect(() => {
    if (open) {
      setCrop(INITIAL_CROP);
      setZoom(INITIAL_ZOOM);
      setCroppedAreaPixels(null);
    }
  }, [open, imageSrc]);

  const handleReset = () => {
    setCrop(INITIAL_CROP);
    setZoom(INITIAL_ZOOM);
  };

  const handleConfirm = async () => {
    if (!imageSrc || !croppedAreaPixels) return;
    setIsProcessing(true);
    try {
      const blob = await getCroppedImageBlob(imageSrc, croppedAreaPixels, mimeType);
      onConfirm(new File([blob], fileName, { type: mimeType }));
    } finally {
      setIsProcessing(false);
    }
  };

  return (
    <Dialog open={open} onClose={onCancel} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ display: "flex", alignItems: "center", justifyContent: "space-between", pr: 1 }}>
        Recadrer la photo
        <IconButton aria-label="Fermer" size="small" onClick={onCancel} disabled={isProcessing}>
          <CloseIcon fontSize="small" />
        </IconButton>
      </DialogTitle>
      <DialogContent>
        <Box sx={{ position: "relative", width: "100%", aspectRatio: "1 / 1", bgcolor: "grey.900", borderRadius: 1, overflow: "hidden" }}>
          {imageSrc && (
            <Cropper
              image={imageSrc}
              crop={crop}
              zoom={zoom}
              aspect={1}
              cropShape="round"
              showGrid={false}
              onCropChange={setCrop}
              onZoomChange={setZoom}
              onCropComplete={(_area, areaPixels) => setCroppedAreaPixels(areaPixels)}
            />
          )}
        </Box>

        <Box sx={{ display: "flex", alignItems: "center", gap: 1.5, mt: 2 }}>
          <Typography id="avatar-crop-zoom-label" variant="body2" color="text.secondary" sx={{ minWidth: 36 }}>
            Zoom
          </Typography>
          <Slider
            aria-labelledby="avatar-crop-zoom-label"
            value={zoom}
            min={1}
            max={3}
            step={0.05}
            onChange={(_e, value) => setZoom(value as number)}
          />
          <IconButton aria-label="Recentrer" size="small" onClick={handleReset}>
            <RestartAltIcon fontSize="small" />
          </IconButton>
        </Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5, gap: 1 }}>
        <Button onClick={onCancel} disabled={isProcessing} color="inherit">
          Annuler
        </Button>
        <Button variant="contained" onClick={handleConfirm} disabled={isProcessing || !croppedAreaPixels}>
          {isProcessing ? "Traitement…" : "Valider"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
