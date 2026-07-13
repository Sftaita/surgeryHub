// Types alignés strictement avec api.md

export type EmploymentTypeDTO = "EMPLOYEE" | "FREELANCER" | null;

export type InstrumentistListItemDTO = {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  active: boolean;
  employmentType: EmploymentTypeDTO;
  defaultCurrency: string;
  displayName: string;
  profilePicturePath?: string | null;
};

export type InstrumentistsListResponseDTO = {
  items: InstrumentistListItemDTO[];
  total: number;
};

export type SiteMembershipDTO = {
  id: number;
  site: {
    id: number;
    name: string;
  };
  siteRole: string;
};

export type InstrumentistDetailDTO = {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  displayName: string;
  active: boolean;
  employmentType: EmploymentTypeDTO;
  defaultCurrency: string;
  hourlyRate: string | null;
  consultationFee: string | null;
  profilePicturePath: string | null;
  siteMemberships: SiteMembershipDTO[];
  specialties: string[];
};

export type CreateInstrumentistResponseDTO = {
  instrumentist: {
    id: number;
    email: string;
    firstname: string | null;
    lastname: string | null;
    displayName: string;
    active: boolean;
    employmentType: EmploymentTypeDTO;
    defaultCurrency: string;
    siteIds: number[];
    invitationExpiresAt: string;
  };
  warnings: {
    code: string;
    message?: string;
  }[];
};

export type InstrumentistRatesDTO = {
  id: number;
  hourlyRate: string | null;
  consultationFee: string | null;
};

export type InstrumentistActiveStateDTO = {
  id: number;
  active: boolean;
};

export type DeleteMembershipResponseDTO = {
  id: number;
  deleted: true;
};

export type InstrumentistPlanningEventDTO = {
  id: number;
  title: string;
  start: string;
  end: string;
  allDay: boolean;
  surgeon: {
    id: number;
    firstname: string | null;
    lastname: string | null;
    displayName: string;
  };
  site: {
    id: number;
    name: string;
  };
};
