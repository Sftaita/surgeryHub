type P = { size?: number; strokeWidth?: number };
const base = (size: number, sw: number) => ({
  width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor',
  strokeWidth: sw, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true
});

/** Icône de l'onglet « Heures » — à ajouter aussi dans handoff-instrumentiste-nav/src/icons.tsx. */
export function ClockIcon({ size = 21, strokeWidth = 2 }: P) {
  return <svg {...base(size, strokeWidth)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>;
}
export function ChevronLeft({ size = 18, strokeWidth = 2.4 }: P) {
  return <svg {...base(size, strokeWidth)}><path d="m15 18-6-6 6-6" /></svg>;
}
export function ChevronRight({ size = 16, strokeWidth = 2.4 }: P) {
  return <svg {...base(size, strokeWidth)}><path d="m9 18 6-6-6-6" /></svg>;
}
export function ChevronDown({ size = 16, strokeWidth = 2.4 }: P) {
  return <svg {...base(size, strokeWidth)}><path d="m6 9 6 6 6-6" /></svg>;
}
export function ChevronUp({ size = 16, strokeWidth = 2.4 }: P) {
  return <svg {...base(size, strokeWidth)}><path d="m18 15-6-6-6 6" /></svg>;
}
export function InfoIcon({ size = 15, strokeWidth = 2.2 }: P) {
  return <svg {...base(size, strokeWidth)}><circle cx="12" cy="12" r="9" /><path d="M12 16v-4M12 8h.01" /></svg>;
}
