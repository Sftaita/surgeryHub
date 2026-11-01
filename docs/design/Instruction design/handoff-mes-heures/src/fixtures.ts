import type { Prestation } from './types';

/**
 * Octobre 2026 — mêmes données que les maquettes (canevas « Mes heures — maquettes », révision 3).
 * Durées calculées depuis début/fin : 82h30 encodées / 58h15 validées.
 */
const S = {
  brug: { siteId: 'brug', siteName: 'CHU Brugmann', siteShortName: 'Brugmann', rateCents: 4200 },
  eras: { siteId: 'eras', siteName: 'Hôpital Erasme', siteShortName: 'Erasme', rateCents: 4200 },
  delta: { siteId: 'delta', siteName: 'CHIREC Delta', siteShortName: 'Delta', rateCents: 4500 },
  sj: { siteId: 'sj', siteName: 'Clinique Saint-Jean', siteShortName: 'Saint-Jean', rateCents: 4000 }
} as const;

const p = (id: string, day: number, from: string, to: string, surgeonName: string, site: keyof typeof S, status: 'pending' | 'validated'): Prestation => ({
  missionId: id,
  start: `2026-10-${String(day).padStart(2, '0')}T${from}:00`,
  end: `2026-10-${String(day).padStart(2, '0')}T${to}:00`,
  surgeonName,
  status,
  ...S[site]
});

export const OCTOBER_2026: Prestation[] = [
  p('m-1291', 29, '07:30', '16:00', 'Dr Martin Dubois', 'brug', 'pending'),
  p('m-1287', 27, '08:00', '14:15', 'Dr Claire Lambert', 'delta', 'pending'),
  p('m-1284', 26, '07:45', '17:15', 'Dr Karim Benali', 'eras', 'pending'),
  p('m-1270', 22, '07:30', '15:45', 'Dr Martin Dubois', 'brug', 'validated'),
  p('m-1266', 21, '12:00', '19:30', 'Dr Anne Peeters', 'sj', 'validated'),
  p('m-1251', 16, '08:00', '18:00', 'Dr Claire Lambert', 'delta', 'validated'),
  p('m-1240', 13, '07:30', '13:00', 'Dr Karim Benali', 'eras', 'validated'),
  p('m-1228', 9, '07:30', '16:30', 'Dr Martin Dubois', 'brug', 'validated'),
  p('m-1219', 6, '08:00', '16:00', 'Dr Anne Peeters', 'sj', 'validated'),
  p('m-1205', 2, '07:00', '17:00', 'Dr Karim Benali', 'eras', 'validated')
];
