// src/app/features/missions/api/missions.types.ts

export type AllowedAction =
  | "view"
  | "edit"
  | "publish"
  | "submit"
  | "cancel"
  | "delete";

export type SchedulePrecision = "APPROXIMATE" | "EXACT";
export type MissionType = "BLOCK" | "CONSULTATION";

/**
 * Typage générique des réponses paginées API:
 * Utilisé par fetchMissions (Lot 1).
 */
export type PaginatedResponse<T> = {
  items: T[];
  total: number;
  page?: number;
  limit?: number;
};

export interface SiteRef {
  id: number;
  name?: string;
  address?: string;
  timezone?: string;
}

export interface UserRef {
  id: number;
  email: string;
  firstname?: string | null;
  lastname?: string | null;
  active?: boolean;
  employmentType?: string | null;
}

export interface Mission {
  id: number;

  type: MissionType;
  schedulePrecision: SchedulePrecision;

  startAt: string;
  endAt: string;

  // backend peut renvoyer soit site objet, soit siteId (selon endpoints/serializer)
  site?: SiteRef | null;
  siteId?: number;

  status?: string;

  surgeon?: UserRef | null;
  instrumentist?: UserRef | null;

  // IMPORTANT: actions autorisées côté API
  allowedActions?: AllowedAction[];

  // Le reste peut exister mais on n’affiche pas patient/financier
  [key: string]: unknown;
}
