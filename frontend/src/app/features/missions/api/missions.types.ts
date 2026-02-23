export type AllowedAction =
  | "view"
  | "edit"
  | "publish"
  | "claim" // Lot 3 — Instrumentist
  | "submit"
  | "cancel"
  | "delete"
  | "edit_encoding"
  // Lot F1 — Missions DECLARED
  | "approve"
  | "reject"
  | "edit_hours"
  | "declare"
  // compat éventuelle si backend renvoie "encoding" (reste piloté par allowedActions)
  | "encoding";

export type SchedulePrecision = "APPROXIMATE" | "EXACT";
export type MissionType = "BLOCK" | "CONSULTATION";

/**
 * MissionStatus (frontend) — strictement aligné sur App\Enum\MissionStatus (backend).
 * Le frontend ne déduit jamais un droit par statut : seul allowedActions fait foi.
 */
export type MissionStatus =
  | "DRAFT"
  | "OPEN"
  | "DECLARED"
  | "ASSIGNED"
  | "REJECTED"
  | "SUBMITTED"
  | "VALIDATED"
  | "CLOSED"
  | "IN_PROGRESS";

export type CreateMissionBody = {
  siteId: number;
  type: MissionType;
  schedulePrecision: SchedulePrecision;
  startAt: string;
  endAt: string;
  surgeonUserId: number;
};

export type CreateMissionResult = Mission;

export type PaginatedResponse<T> = {
  items: T[];
  total: number;
  page?: number;
  limit?: number;
};

export type SiteListItem = {
  id: number;
  name: string;
  address?: string;
  timezone?: string;
};

export type UserListItem = {
  id: number;
  email: string;
  firstname?: string | null;
  lastname?: string | null;
  active?: boolean;
  employmentType?: string | null;
  displayName?: string | null;
  defaultCurrency?: string | null;
};

/**
 * Lot 2b (correction finale) — /api/instrumentists
 * displayName = libellé principal UI
 * aucun lien instrumentiste↔site côté frontend
 */
export type InstrumentistListItem = {
  id: number;
  email: string;
  firstname?: string | null;
  lastname?: string | null;
  active?: boolean;
  employmentType?: string | null;
  defaultCurrency?: string | null;
  displayName?: string | null;
};

export type InstrumentistsResponse = PaginatedResponse<InstrumentistListItem>;

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
  displayName?: string | null;
}

export interface Mission {
  id: number;

  type: MissionType;
  schedulePrecision: SchedulePrecision;

  startAt: string;
  endAt: string;

  site?: SiteRef | null;
  siteId?: number;

  status?: MissionStatus;

  surgeon?: UserRef | null;
  instrumentist?: UserRef | null;

  allowedActions?: AllowedAction[];

  [key: string]: unknown;
}
