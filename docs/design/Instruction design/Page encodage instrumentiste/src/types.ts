export interface MaterialLineData {
  id: string;
  /** Commercial name, bold. e.g. "Fast-Fix". */
  name: string;
  /** Manufacturer. e.g. "Smith & Nephew". */
  brand: string;
  /** Catalogue reference / product family. e.g. "FastFix". */
  reference: string;
  /** 2–3 char manufacturer initials for the chip ("S&N", "ARX", "GM"). Derived from `brand` when absent. */
  chip?: string;
  /** Billing category badge — "implant", "consommable"… Undefined = no badge. */
  tag?: string;
  qty: number;
  isNew?: boolean;
  notFound?: boolean;
}

export interface InterventionData {
  id: string;
  name: string;
  materials: MaterialLineData[];
}

export interface WorkedHours {
  /** "10h45" */
  start: string;
  /** "18h15" */
  end: string;
  /** minutes */
  breakMinutes: number;
  /** "7h30" — total net, pré-calculé côté écran. */
  total: string;
}
