import { Avatar as MuiAvatar, Box } from "@mui/material";
import PersonOutlineOutlinedIcon from "@mui/icons-material/PersonOutlineOutlined";

import { avatarColorFor, initialsFor } from "./avatarColor";

export type AvatarSize = "xs" | "sm" | "md" | "lg" | "xl";

export const AVATAR_SIZE_PX: Record<AvatarSize, number> = {
  xs: 24,
  sm: 32,
  md: 40,
  lg: 56,
  xl: 88,
};

interface PersonAvatarProps {
  name: string;
  photoUrl?: string | null;
  size?: AvatarSize;
}

/**
 * Single source of truth for rendering a person (surgeon/instrumentist) anywhere
 * in the app: photo when available, otherwise the same initials pastille
 * (avatarColorFor/initialsFor) used everywhere else, for a consistent identity
 * across screens.
 */
export function PersonAvatar({ name, photoUrl, size = "sm" }: PersonAvatarProps) {
  const px = AVATAR_SIZE_PX[size];

  if (photoUrl) {
    return (
      <MuiAvatar
        src={photoUrl}
        alt={name}
        sx={{ width: px, height: px, flex: "none" }}
      />
    );
  }

  const { bg, fg } = avatarColorFor(name);
  return (
    <Box
      sx={{
        width: px, height: px, borderRadius: "999px", flex: "none",
        bgcolor: bg, color: fg,
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: Math.round(px * 0.42), fontWeight: 700,
      }}
    >
      {initialsFor(name)}
    </Box>
  );
}

/** Dashed placeholder for "no instrumentist assigned yet" — never leave the slot visually empty. */
export function EmptyAvatar({ size = 24 }: { size?: number }) {
  return (
    <Box
      sx={{
        width: size, height: size, borderRadius: "999px", flex: "none",
        border: "1.5px dashed #C2C9D1", bgcolor: "#fff",
        display: "flex", alignItems: "center", justifyContent: "center",
      }}
    >
      <PersonOutlineOutlinedIcon sx={{ fontSize: Math.round(size * 0.6), color: "#C2C9D1" }} />
    </Box>
  );
}
