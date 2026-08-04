import * as React from "react";

interface IconProps {
  size?: number;
  className?: string;
}

const base = (size: number): React.SVGProps<SVGSVGElement> => ({
  width: size,
  height: size,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 2,
  strokeLinecap: "round",
  strokeLinejoin: "round",
});

/** Set d'icônes minimal, porté depuis le prototype de référence (react-catalogue-prestations)
 *  — SVG inline, aucune dépendance supplémentaire (cohérent avec @mui/icons-material déjà
 *  utilisé ailleurs dans l'app, mais ce module reste volontairement autonome). */
export function PlusIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} className={className}>
      <path d="M12 5v14M5 12h14" />
    </svg>
  );
}
export function CloseIcon({ size = 15, className }: IconProps) {
  return (
    <svg {...base(size)} strokeWidth={2.4} className={className}>
      <path d="M6 6l12 12M18 6 6 18" />
    </svg>
  );
}
export function SearchIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} className={className}>
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </svg>
  );
}
export function EditIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} className={className}>
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
    </svg>
  );
}
export function CheckIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} strokeWidth={3} className={className}>
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}
export function WarningIcon({ size = 14, className }: IconProps) {
  return (
    <svg {...base(size)} strokeWidth={2.2} className={className}>
      <path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3Z" />
      <path d="M12 9v4M12 17h.01" />
    </svg>
  );
}
export function BuildingIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} className={className}>
      <path d="M3 21h18" />
      <path d="M6 21V8l6-4 6 4v13" />
      <path d="M10 21v-6h4v6" />
    </svg>
  );
}
export function BookIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} className={className}>
      <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
      <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />
    </svg>
  );
}
export function ArrowLeftIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} strokeWidth={2.3} className={className}>
      <path d="m15 18-6-6 6-6" />
    </svg>
  );
}
export function ChevronRightIcon({ size = 16, className }: IconProps) {
  return (
    <svg {...base(size)} strokeWidth={2.2} className={className}>
      <path d="m9 18 6-6-6-6" />
    </svg>
  );
}
