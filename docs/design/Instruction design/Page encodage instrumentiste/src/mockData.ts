import { InterventionData, WorkedHours } from './types';

export const mockHours: WorkedHours = { start: '10h45', end: '18h15', breakMinutes: 45, total: '7h30' };

export const mockInterventions: InterventionData[] = [
  {
    id: 'i1',
    name: 'Plastie du ligament croisé antérieur du genou',
    materials: [
      { id: 'm1', name: 'Fast-Fix', brand: 'Smith & Nephew', reference: 'FastFix', chip: 'S&N', tag: 'implant', qty: 3 },
      { id: 'm2', name: 'Fiber Stitch', brand: 'Arthrex', reference: 'FiberStitch', chip: 'ARX', qty: 1 },
    ],
  },
  {
    id: 'i2',
    name: 'Arthrodèse intersomatique lombaire par voie transforaminale (1 niveau)',
    materials: [
      { id: 'm3', name: 'COALITION™ Spacer', brand: 'Globus Medical', reference: 'Coalition-Cage', chip: 'GM', tag: 'implant', qty: 1 },
    ],
  },
  { id: 'i3', name: 'Suture de Bankart', materials: [] },
];
