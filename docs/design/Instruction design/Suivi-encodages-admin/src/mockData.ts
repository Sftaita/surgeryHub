import { MissionRow, FilterGroup } from './types';

export const dayLabel = (date: string) =>
  ({ 'lun. 7 sept.': 'Lundi 7 septembre', 'mar. 8 sept.': 'Mardi 8 septembre', 'mer. 9 sept.': 'Mercredi 9 septembre' } as Record<string, string>)[date] || date;

export const mockFunnel = [
  { label: 'terminées', count: 18, color: 'var(--gray-300)' },
  { label: 'à encoder', count: 6, color: 'var(--amber-500)' },
  { label: 'en cours', count: 2, color: 'var(--amber-100)' },
  { label: 'soumises', count: 8, color: 'var(--blue-500)' },
  { label: 'validées', count: 4, color: 'var(--green-500)' },
];

export const mockFilters: FilterGroup[] = [
  { label: 'SITE', options: [
    { label: 'CHIREC — Delta', count: 5, on: true },
    { label: 'Clinique Saint-Jean', count: 1, on: false },
    { label: 'CHU Tivoli', count: 0, on: false }] },
  { label: 'ÉTAT D’ENCODAGE', options: [
    { label: 'À encoder', count: 2, on: true },
    { label: 'En cours', count: 1, on: false },
    { label: 'Soumis', count: 2, on: false },
    { label: 'Validé', count: 1, on: false }] },
  { label: 'INSTRUMENTISTE', options: [
    { label: 'Salve Decorte', count: 2, on: false },
    { label: 'Diane de Moor', count: 2, on: false },
    { label: 'Christine V.', count: 1, on: false },
    { label: 'Marie Lambert', count: 1, on: false }] },
  { label: 'ÉCART HORAIRE', options: [
    { label: 'Conforme', count: 1, on: false },
    { label: 'Écart > 1h', count: 3, on: false },
    { label: 'Écart > 3h', count: 1, on: false }] },
];

export const mockRows: MissionRow[] = [
  {
    id: 432, date: 'lun. 7 sept.', time: '08:00', nurse: 'Salve Decorte', surgeon: 'Dr Etienne Willemart',
    site: 'CHIREC — Hôpital Delta · Bloc opératoire', state: 'En cours', late: true,
    planRange: '08:00 → 18:00', planTotal: '10h00', realRange: '08:15 → 17:00', realTotal: '8h45',
    realPct: '87%', gap: '−1h15 vs planifié', gapTone: 'warn',
    hoursNote: 'Durée déclarée par l’instrumentiste : 525 min. Heures explicites.',
    steps: [
      { label: 'Brouillon', when: '07/09 · 17:04', done: true },
      { label: 'Soumis', when: 'en attente', done: false },
      { label: 'Validé', when: '—', done: false }],
    finance: '—', footTitle: 'Encodage en brouillon',
    footSub: 'Rien à valider tant que l’instrumentiste n’a pas soumis.', cta: 'off',
    interventions: [1, 2, 3].map((n) => ({
      id: 'iv' + n, name: 'Prothèse totale de hanche', firm: 'Zimmer Biomet', rep: true,
      materials: [
        { id: 'm' + n + 'a', name: 'Taperloc® Complete Hip System', ref: 'Zimmer Biomet · Réf. Taperloc', qty: 1 },
        { id: 'm' + n + 'b', name: 'G7® Acetabular System', ref: 'Zimmer Biomet · Réf. G7 COTYLE', qty: 1 }],
    })),
  },
  {
    id: 433, date: 'lun. 7 sept.', time: '08:00', nurse: 'Christine Vanmessem', surgeon: 'Dr Etienne Lejeune',
    site: 'CHIREC — Hôpital Delta · Bloc opératoire', state: 'À encoder', late: false,
    planRange: '08:00 → 13:00', planTotal: '5h00', realRange: 'non démarré', realTotal: '—',
    realPct: '0%', gap: 'en attente', gapTone: 'muted',
    hoursNote: 'Horaire planifié, aucune heure réelle déclarée.',
    steps: [
      { label: 'Brouillon', when: 'pas ouvert', done: false },
      { label: 'Soumis', when: '—', done: false },
      { label: 'Validé', when: '—', done: false }],
    finance: '—', footTitle: 'Encodage non démarré',
    footSub: 'Relancez l’instrumentiste : échéance ce soir 22h.', cta: 'off', interventions: [],
  },
  {
    id: 435, date: 'mar. 8 sept.', time: '08:00', nurse: 'Diane de Moor', surgeon: 'Dr Steven Petronilia',
    site: 'CHIREC — Hôpital Delta · Bloc opératoire', state: 'Soumis', late: false,
    planRange: '08:00 → 18:00', planTotal: '10h00', realRange: '08:00 → 16:45', realTotal: '8h45',
    realPct: '87%', gap: '−1h15 vs planifié', gapTone: 'warn',
    hoursNote: 'Soumis le 12/09 à 19:22 par l’instrumentiste.',
    steps: [
      { label: 'Brouillon', when: '12/09 · 18:40', done: true },
      { label: 'Soumis', when: '12/09 · 19:22', done: true },
      { label: 'Validé', when: 'à faire', done: false }],
    finance: 'à valider', footTitle: 'Prêt à valider',
    footSub: '2 interventions · 3 références · 1 120 € facturables.', cta: 'on',
    interventions: [
      { id: 'a', name: 'Plastie du ligament croisé antérieur', firm: 'Smith & Nephew', materials: [
        { id: 'a1', name: 'Fast-Fix', ref: 'Smith & Nephew · Réf. FastFix', qty: 3 },
        { id: 'a2', name: 'Fiber Stitch', ref: 'Arthrex · Réf. FiberStitch', qty: 1 }] },
      { id: 'b', name: 'Arthrodèse intersomatique lombaire (1 niveau)', firm: 'Globus Medical', rep: true, materials: [
        { id: 'b1', name: 'COALITION™ Spacer', ref: 'Globus Medical · Réf. Coalition-Cage', qty: 1 }] }],
  },
  {
    id: 437, date: 'mer. 9 sept.', time: '10:30', nurse: 'Marie Lambert', surgeon: 'Dr Anne Vandeputte',
    site: 'Clinique Saint-Jean · Bloc opératoire', state: 'Validé', late: false,
    planRange: '10:30 → 17:30', planTotal: '7h00', realRange: '10:30 → 17:20', realTotal: '6h50',
    realPct: '97%', gap: 'conforme', gapTone: 'ok',
    hoursNote: 'Validé le 12/09 par Samy Etaita.',
    steps: [
      { label: 'Brouillon', when: '12/09 · 17:35', done: true },
      { label: 'Soumis', when: '12/09 · 17:51', done: true },
      { label: 'Validé', when: '12/09 · 21:04', done: true }],
    finance: '1 240 €', footTitle: 'Validé', footSub: 'Prêt pour la facture firme du mois.', cta: 'done',
    interventions: [
      { id: 'c', name: 'Ostéosynthèse du radius distal', firm: 'DePuy Synthes', materials: [
        { id: 'c1', name: 'VA-LCP Two-Column', ref: 'DePuy Synthes · Réf. 02.111', qty: 1 }] }],
  },
];
