// Deterministic avatar color derived from a name/email — same person always gets the
// same color across sidebar, cards, and reassign modal without server-side storage.
const PALETTE: Array<{ bg: string; fg: string }> = [
  { bg: "#EDF4FF", fg: "#1B5FD0" },
  { bg: "#FEF6E7", fg: "#B7791F" },
  { bg: "#F3EBFD", fg: "#7C4FCC" },
  { bg: "#EFFAF5", fg: "#2C7D5F" },
  { bg: "#FDEEEE", fg: "#C62F36" },
  { bg: "#EFF4FB", fg: "#3B6296" },
];

function hashString(s: string): number {
  let h = 0;
  for (let i = 0; i < s.length; i++) {
    h = (h * 31 + s.charCodeAt(i)) | 0;
  }
  return Math.abs(h);
}

export function avatarColorFor(name: string): { bg: string; fg: string } {
  return PALETTE[hashString(name) % PALETTE.length];
}

export function initialsFor(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
