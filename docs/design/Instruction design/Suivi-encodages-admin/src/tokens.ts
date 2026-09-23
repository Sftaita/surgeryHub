import { EncodingState, GapTone } from './types';

/** Pastille d'état. Le rouge est RÉSERVÉ au retard et à l'anomalie — jamais à un état normal. */
export const STATE_TONE: Record<EncodingState, { bg: string; fg: string }> = {
  'À encoder': { bg: 'var(--amber-500)', fg: 'var(--gray-950)' },
  'En cours':  { bg: 'var(--amber-100)', fg: 'var(--amber-700)' },
  'Soumis':    { bg: 'var(--blue-50)',   fg: 'var(--blue-700)' },
  'Validé':    { bg: 'var(--green-100)', fg: 'var(--green-800)' },
};

/** Couleur de la jauge d'heures et du libellé d'écart. */
export const GAP_BAR: Record<GapTone, string> = {
  ok: 'var(--green-500)', warn: 'var(--amber-500)', bad: 'var(--red-600)', muted: 'var(--gray-300)',
};
export const GAP_FG: Record<GapTone, string> = {
  ok: 'var(--green-700)', warn: 'var(--amber-700)', bad: 'var(--red-700)', muted: 'var(--gray-400)',
};
/** Encre du libellé posé SUR la jauge : sombre sur ambre/gris clair (contraste), blanc sur rouge/vert. */
export const GAP_INK: Record<GapTone, string> = {
  ok: '#fff', warn: 'var(--gray-950)', bad: '#fff', muted: 'var(--gray-950)',
};

export const initials = (name: string) =>
  name.replace(/^Dr\s+/, '').split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase();
