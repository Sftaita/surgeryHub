export type InvitationStatus = "pending" | "expired" | "used" | "email_not_sent" | "none";

export type BusinessRole = "ADMIN" | "MANAGER" | "SURGEON" | "INSTRUMENTIST";

export interface AdminSiteSummary {
  id: number;
  name: string;
}

export interface AdminSiteMembership {
  id: number;
  site: AdminSiteSummary;
  siteRole: string;
}

export interface AdminUserListItem {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  displayName: string;
  role: BusinessRole;
  active: boolean;
  invitationStatus: InvitationStatus;
  sites: AdminSiteSummary[];
}

export interface AdminUserDetail {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  phone: string | null;
  displayName: string;
  role: BusinessRole;
  active: boolean;
  invitationStatus: InvitationStatus;
  invitationExpiresAt: string | null;
  invitationLastSentAt: string | null;
  siteMemberships: AdminSiteMembership[];
}

export interface AdminUsersListResponse {
  items: AdminUserListItem[];
  total: number;
}

export interface AdminInvitationItem {
  id: number;
  email: string;
  displayName: string;
  role: BusinessRole;
  active: boolean;
  invitationStatus: InvitationStatus;
  invitationExpiresAt: string | null;
  invitationLastSentAt: string | null;
}

export interface AdminInvitationsListResponse {
  items: AdminInvitationItem[];
  total: number;
}

export interface AdminAuditActor {
  id: number;
  email: string;
  displayName: string;
}

export interface AdminAuditEvent {
  id: number;
  eventType: string;
  description: string;
  payload: Record<string, unknown> | null;
  createdAt: string;
  actor: AdminAuditActor | null;
  targetUser: AdminAuditActor | null;
}

export interface AdminAuditListResponse {
  items: AdminAuditEvent[];
  total: number;
  limit: number;
  offset: number;
}

export interface AdminCreateUserPayload {
  email: string;
  firstname: string;
  lastname: string;
  phone?: string;
  role: "ROLE_INSTRUMENTIST" | "ROLE_SURGEON" | "ROLE_MANAGER";
  siteIds: number[];
}

export interface AdminChangeRolePayload {
  newRole: "ROLE_INSTRUMENTIST" | "ROLE_SURGEON" | "ROLE_MANAGER";
}

export interface AdminPatchUserPayload {
  firstname?: string;
  lastname?: string;
  phone?: string;
}
