import React from 'react';

const P: Record<string, React.ReactNode> = {
  search: <><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></>,
  filter: <path d="M3 5h18M6 12h12M10 19h4" />,
  download: <path d="M12 3v12M7 10l5 5 5-5M5 21h14" />,
  chevronRight: <path d="m9 6 6 6-6 6" />,
  chevronLeft: <path d="m15 6-6 6 6 6" />,
  close: <path d="M18 6 6 18M6 6l12 12" />,
  check: <path d="m5 13 4 4L19 7" />,
  clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
  alert: <><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 2.4 18a1.8 1.8 0 0 0 1.6 2.7h16a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0Z" /></>,
  info: <><circle cx="12" cy="12" r="9" /><path d="M12 8h.01M11 12h1v4h1" /></>,
  retry: <><path d="M4 4v6h6" /><path d="M20 12a8 8 0 1 1-2.3-5.7L20 9" /></>,
  rep: <><path d="M16 19a4 4 0 0 0-8 0" /><circle cx="12" cy="10" r="3" /><circle cx="12" cy="12" r="9.5" /></>,
};

/** Icônes inline (Lucide-like, trait 2.2–2.8). Aucune dépendance. */
export function Icon({ name, size = 16, stroke = 2.4 }: { name: keyof typeof P | string; size?: number; stroke?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={stroke} strokeLinecap="round" strokeLinejoin="round" style={{ flex: 'none' }}>
      {P[name]}
    </svg>
  );
}
