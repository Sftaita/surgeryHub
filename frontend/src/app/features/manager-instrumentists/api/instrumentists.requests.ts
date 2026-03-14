export type InstrumentistsListQuery = {
  search?: string;
  active?: boolean;
  siteId?: number;
};

export type CreateInstrumentistRequest = {
  email: string;
  firstname: string;
  lastname: string;
  phone: string;
  siteIds: number[];
};

export type UpdateInstrumentistRatesRequest = {
  hourlyRate?: number;
  consultationFee?: number;
};

export type AddSiteMembershipRequest = {
  siteId: number;
};

export type InstrumentistPlanningQuery = {
  from: string;
  to: string;
};
