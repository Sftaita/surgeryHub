export type SystemRole = "INSTRUMENTIST" | "SURGEON" | "MANAGER" | "ADMIN";

export type SiteDTO = {
  id: number;
  name: string;
  timezone: string; // e.g. "Europe/Brussels"
};

export type InstrumentistProfileDTO = {
  employmentType: "EMPLOYEE" | "FREELANCER";
  defaultCurrency: "EUR" | string;
};

export type MeDTO = {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  role: SystemRole;
  instrumentistProfile: InstrumentistProfileDTO | null;
  sites: SiteDTO[];
  activeSiteId: number | null;
};

export type LoginResponseDTO = {
  accessToken: string;
  refreshToken: string;
};

export type RefreshResponseDTO = {
  accessToken: string;
  refreshToken?: string;
};
