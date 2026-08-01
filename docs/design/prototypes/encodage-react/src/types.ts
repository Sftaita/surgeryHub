export interface WorkedHours {
  start: number; // minutes from midnight
  end: number;
  pause: number;
  nextDay: boolean;
}

export interface MaterialLineData {
  id: string;
  name: string;
  reference: string;
  qty: number;
  isNew?: boolean;
  notFound?: boolean;
}

export interface InterventionData {
  id: string;
  name: string;
  open: boolean;
  materials: MaterialLineData[];
}

export interface MissionData {
  id: string;
  number: string;
  surgeon: string;
  specialty: string;
  site: string;
  address: string;
  date: string;
  scheduled: string; // "07h30 → 15h30"
  type: string;
  hours: WorkedHours | null;
  interventions: InterventionData[];
}

export interface CatalogMaterial {
  id: string;
  name: string;
  brand: string;
  reference: string;
}
