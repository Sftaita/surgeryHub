import { CatalogMaterial, MissionData } from './types';

export const MOCK_MISSION: MissionData = {
  id: '529',
  number: '529',
  surgeon: 'Dr. Anouk Peeters',
  specialty: 'Orthopédie',
  site: 'CHU Brugmann',
  address: 'Site Victor Horta · Laeken',
  date: 'Dimanche 5 juillet 2026',
  scheduled: '07h30 → 15h30',
  type: 'Bloc opératoire',
  hours: null,
  interventions: [
    {
      id: 'i1', name: 'LCA — reconstruction', open: true,
      materials: [
        { id: 'm1', name: 'Vis Biosure HA 7x23 mm', reference: 'AR-7723', qty: 1 },
        { id: 'm2', name: 'FiberWire n°2', reference: 'AR-FW2', qty: 2 },
        { id: 'm3', name: 'TightRope ABS', reference: 'AR-TRABS', qty: 1 }
      ]
    },
    { id: 'i2', name: 'Ménisque médial', open: false, materials: [{ id: 'm4', name: 'Lame shaver 4.2 mm', reference: 'SN-SH42', qty: 1 }] },
    { id: 'i3', name: 'Chondroplastie patellaire', open: false, materials: [] }
  ]
};

export const CATALOG: CatalogMaterial[] = [
  { id: 'c1', name: 'Vis Biosure HA 7x23 mm', brand: 'Arthrex', reference: 'AR-7723' },
  { id: 'c2', name: 'Vis Biosure HA 7x28 mm', brand: 'Arthrex', reference: 'AR-7728' },
  { id: 'c3', name: 'TightRope ABS', brand: 'Arthrex', reference: 'AR-TRABS' },
  { id: 'c4', name: 'Ancre SwiveLock 4.75 mm', brand: 'Arthrex', reference: 'AR-SW475' },
  { id: 'c5', name: 'Endobutton CL Ultra', brand: 'Smith+Nephew', reference: 'SN-ECLU' },
  { id: 'c6', name: 'Lame shaver 4.2 mm', brand: 'Smith+Nephew', reference: 'SN-SH42' },
  { id: 'c7', name: 'Vis RCI 8x25 mm', brand: 'Smith+Nephew', reference: 'SN-RCI825' },
  { id: 'c8', name: 'Vis Asnis III 4.0 mm', brand: 'Stryker', reference: 'ST-AS40' },
  { id: 'c9', name: 'Agrafe EasyClip 10 mm', brand: 'Stryker', reference: 'ST-EC10' },
  { id: 'c10', name: 'Ancre JuggerKnot 1.4 mm', brand: 'Zimmer Biomet', reference: 'ZB-JK14' }
];
