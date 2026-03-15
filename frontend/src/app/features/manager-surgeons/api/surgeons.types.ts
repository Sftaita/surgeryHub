export type SurgeonListItemDTO = {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  displayName: string;
  active: boolean;
  profilePicturePath: string | null;
};

export type SurgeonSiteMembershipDTO = {
  id: number;
  site: { id: number; name: string };
  siteRole: string;
};

export type SurgeonProfileDTO = {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  displayName: string;
  active: boolean;
  profilePicturePath: string | null;
  siteMemberships: SurgeonSiteMembershipDTO[];
};

export type SurgeonCreateDTO = {
  email: string;
  firstname?: string;
  lastname?: string;
  phone?: string;
  siteIds: number[];
};

export type SurgeonPlanningEvent = {
  id: number;
  title: string;
  start: string;
  end: string;
  allDay: boolean;
  instrumentist?: { id: number | null; firstname: string | null; lastname: string | null };
  site?: { id: number | null; name: string | null };
};
