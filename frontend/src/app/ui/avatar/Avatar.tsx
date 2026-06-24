import { Box } from "@mui/material";
import PersonOutlineOutlinedIcon from "@mui/icons-material/PersonOutlineOutlined";

import { avatarColorFor, initialsFor } from "./avatarColor";

interface Props {
  name: string;
  size?: number;
}

/** Initials pastille, color derived from the person's name (avatarColorFor) — used for surgeons/instrumentists everywhere a person is shown. */
export function Avatar({ name, size = 24 }: Props) {
  const { bg, fg } = avatarColorFor(name);
  return (
    <Box
      sx={{
        width: size, height: size, borderRadius: "999px", flex: "none",
        bgcolor: bg, color: fg,
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: Math.round(size * 0.42), fontWeight: 700,
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
