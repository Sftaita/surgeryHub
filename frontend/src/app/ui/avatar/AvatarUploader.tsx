import * as React from "react";
import { Box, CircularProgress, IconButton, Typography } from "@mui/material";
import PhotoCameraIcon from "@mui/icons-material/PhotoCamera";
import DeleteOutlineIcon from "@mui/icons-material/DeleteOutline";

import { PersonAvatar, AVATAR_SIZE_PX, type AvatarSize } from "./PersonAvatar";
import { AvatarCropDialog } from "./AvatarCropDialog";

export const AVATAR_ACCEPTED_MIME_TYPES = ["image/jpeg", "image/png", "image/webp"];
export const AVATAR_MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 Mo

function validatePhotoFile(file: File): string | null {
  if (!AVATAR_ACCEPTED_MIME_TYPES.includes(file.type)) {
    return "Format non accepté. Utilisez JPEG, PNG ou WebP.";
  }
  if (file.size > AVATAR_MAX_SIZE_BYTES) {
    return "La photo ne doit pas dépasser 5 Mo.";
  }
  return null;
}

interface AvatarUploaderProps {
  name: string;
  photoUrl?: string | null;
  size?: AvatarSize;
  disabled?: boolean;
  helperText?: string;
  /**
   * Called with the cropped, validated file.
   * - Return a Promise (e.g. an upload call) and the component manages its own
   *   busy/error state, reverting the preview if it rejects — for authenticated
   *   self-service upload (ProfilePage, reminder modal).
   * - Return void/undefined and the component just keeps the local preview,
   *   handing the file off for the caller to submit later — for the onboarding
   *   form, which bundles the photo into account activation instead of
   *   uploading it immediately.
   */
  onFileReady: (file: File) => void | Promise<void>;
  /** Shown as a delete affordance once a photo/preview exists. Omit to hide it. */
  onRemove?: () => void;
}

/**
 * Interactive avatar: click or drag a file, crop it square, then hand it off
 * via onFileReady. Wraps PersonAvatar so the idle state always matches the
 * read-only rendering used everywhere else.
 */
export function AvatarUploader({
  name,
  photoUrl,
  size = "xl",
  disabled = false,
  helperText,
  onFileReady,
  onRemove,
}: AvatarUploaderProps) {
  const fileInputRef = React.useRef<HTMLInputElement>(null);
  const [localPreview, setLocalPreview] = React.useState<string | null>(null);
  const [pendingRawUrl, setPendingRawUrl] = React.useState<string | null>(null);
  const [pendingFile, setPendingFile] = React.useState<File | null>(null);
  const [isBusy, setIsBusy] = React.useState(false);
  const [isDragOver, setIsDragOver] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const displayedPhotoUrl = localPreview ?? photoUrl ?? null;
  const hasPhoto = !!displayedPhotoUrl;
  const px = AVATAR_SIZE_PX[size];

  const revokeRawUrl = () => {
    if (pendingRawUrl) URL.revokeObjectURL(pendingRawUrl);
  };

  const handleFile = (file: File | undefined | null) => {
    if (!file || disabled) return;

    const validationError = validatePhotoFile(file);
    if (validationError) {
      setError(validationError);
      return;
    }

    setError(null);
    setPendingFile(file);
    setPendingRawUrl(URL.createObjectURL(file));
  };

  const closeCropDialog = () => {
    revokeRawUrl();
    setPendingRawUrl(null);
    setPendingFile(null);
  };

  const handleCropConfirm = async (croppedFile: File) => {
    const previewUrl = URL.createObjectURL(croppedFile);
    closeCropDialog();
    setLocalPreview(previewUrl);
    setError(null);

    const result = onFileReady(croppedFile);
    if (result && typeof (result as Promise<void>).then === "function") {
      setIsBusy(true);
      try {
        await result;
      } catch {
        URL.revokeObjectURL(previewUrl);
        setLocalPreview(null);
        setError("Impossible d'ajouter la photo. Réessayez.");
      } finally {
        setIsBusy(false);
      }
    }
  };

  const handleInputChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0] ?? null;
    event.target.value = "";
    handleFile(file);
  };

  const handleDrop = (event: React.DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setIsDragOver(false);
    handleFile(event.dataTransfer.files?.[0]);
  };

  const handleRemove = () => {
    if (localPreview) URL.revokeObjectURL(localPreview);
    setLocalPreview(null);
    setError(null);
    onRemove?.();
  };

  return (
    <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 1 }}>
      <Box
        onDragOver={(e) => { e.preventDefault(); if (!disabled) setIsDragOver(true); }}
        onDragLeave={() => setIsDragOver(false)}
        onDrop={handleDrop}
        sx={{
          position: "relative",
          width: px,
          height: px,
          borderRadius: "999px",
          transition: "box-shadow 0.15s ease-out",
          boxShadow: isDragOver ? (theme) => `0 0 0 3px ${theme.palette.primary.main}` : "none",
        }}
      >
        <PersonAvatar name={name} photoUrl={displayedPhotoUrl} size={size} />

        {isBusy && (
          <Box
            sx={{
              position: "absolute", inset: 0, borderRadius: "999px",
              bgcolor: "rgba(0,0,0,0.35)", display: "flex", alignItems: "center", justifyContent: "center",
            }}
          >
            <CircularProgress size={Math.round(px * 0.32)} sx={{ color: "#fff" }} />
          </Box>
        )}

        <input
          ref={fileInputRef}
          type="file"
          accept={AVATAR_ACCEPTED_MIME_TYPES.join(",")}
          style={{ display: "none" }}
          onChange={handleInputChange}
          disabled={disabled}
        />

        <IconButton
          aria-label={hasPhoto ? "Changer la photo de profil" : "Ajouter une photo de profil"}
          size="small"
          onClick={() => fileInputRef.current?.click()}
          disabled={disabled || isBusy}
          sx={{
            position: "absolute", bottom: -2, right: -2,
            bgcolor: "background.paper", border: "1px solid", borderColor: "divider",
            width: 28, height: 28,
            "&:hover": { bgcolor: "grey.100" },
          }}
        >
          <PhotoCameraIcon sx={{ fontSize: 15 }} />
        </IconButton>

        {hasPhoto && onRemove && (
          <IconButton
            aria-label="Supprimer la photo de profil"
            size="small"
            onClick={handleRemove}
            disabled={disabled || isBusy}
            sx={{
              position: "absolute", bottom: -2, left: -2,
              bgcolor: "background.paper", border: "1px solid", borderColor: "divider",
              width: 28, height: 28,
              "&:hover": { bgcolor: "error.light", color: "error.contrastText" },
            }}
          >
            <DeleteOutlineIcon sx={{ fontSize: 15 }} />
          </IconButton>
        )}
      </Box>

      {error && (
        <Typography variant="caption" color="error" textAlign="center">
          {error}
        </Typography>
      )}
      {!error && helperText && (
        <Typography variant="caption" color="text.secondary" textAlign="center">
          {helperText}
        </Typography>
      )}

      <AvatarCropDialog
        open={!!pendingRawUrl && !!pendingFile}
        imageSrc={pendingRawUrl}
        fileName={pendingFile?.name ?? "avatar.jpg"}
        mimeType={pendingFile?.type ?? "image/jpeg"}
        onCancel={closeCropDialog}
        onConfirm={handleCropConfirm}
      />
    </Box>
  );
}
