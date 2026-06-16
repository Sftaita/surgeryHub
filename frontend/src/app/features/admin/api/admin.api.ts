import { apiClient } from "../../../api/apiClient";
import type {
  AdminUsersListResponse,
  AdminUserDetail,
  AdminInvitationsListResponse,
  AdminAuditListResponse,
  AdminCreateUserPayload,
  AdminChangeRolePayload,
  AdminPatchUserPayload,
  AdminSiteMembership,
} from "./admin.types";

// ── Users ─────────────────────────────────────────────────────────────────────

export const getAdminUsers = async (params?: {
  search?: string;
  role?: string;
  active?: boolean;
  siteId?: number;
}): Promise<AdminUsersListResponse> => {
  const res = await apiClient.get("/api/admin/users", { params });
  return res.data;
};

export const getAdminUser = async (id: number): Promise<AdminUserDetail> => {
  const res = await apiClient.get(`/api/admin/users/${id}`);
  return res.data;
};

export const createAdminUser = async (
  body: AdminCreateUserPayload,
): Promise<{ user: AdminUserDetail; warnings: Array<{ code: string; message: string }> }> => {
  const res = await apiClient.post("/api/admin/users", body);
  return res.data;
};

export const patchAdminUser = async (
  id: number,
  body: AdminPatchUserPayload,
): Promise<AdminUserDetail> => {
  const res = await apiClient.patch(`/api/admin/users/${id}`, body);
  return res.data;
};

export const suspendAdminUser = async (id: number): Promise<{ id: number; active: boolean }> => {
  const res = await apiClient.post(`/api/admin/users/${id}/suspend`);
  return res.data;
};

export const activateAdminUser = async (id: number): Promise<{ id: number; active: boolean }> => {
  const res = await apiClient.post(`/api/admin/users/${id}/activate`);
  return res.data;
};

export const changeAdminUserRole = async (
  id: number,
  body: AdminChangeRolePayload,
): Promise<AdminUserDetail> => {
  const res = await apiClient.post(`/api/admin/users/${id}/change-role`, body);
  return res.data;
};

export const resendAdminInvitation = async (id: number): Promise<{
  id: number;
  invitationStatus: string;
  invitationExpiresAt: string | null;
  invitationLastSentAt: string | null;
}> => {
  const res = await apiClient.post(`/api/admin/users/${id}/resend-invitation`);
  return res.data;
};

export const addAdminSiteMembership = async (
  userId: number,
  siteId: number,
): Promise<AdminSiteMembership> => {
  const res = await apiClient.post(`/api/admin/users/${userId}/site-memberships`, { siteId });
  return res.data;
};

export const removeAdminSiteMembership = async (
  userId: number,
  membershipId: number,
): Promise<{ id: number; deleted: boolean }> => {
  const res = await apiClient.delete(`/api/admin/users/${userId}/site-memberships/${membershipId}`);
  return res.data;
};

// ── Invitations ───────────────────────────────────────────────────────────────

export const getAdminInvitations = async (params?: {
  status?: string | string[];
}): Promise<AdminInvitationsListResponse> => {
  const res = await apiClient.get("/api/admin/invitations", { params });
  return res.data;
};

// ── Audit ─────────────────────────────────────────────────────────────────────

export const getAdminAudit = async (params?: {
  from?: string;
  to?: string;
  targetUserId?: number;
  eventType?: string;
  limit?: number;
  offset?: number;
}): Promise<AdminAuditListResponse> => {
  const res = await apiClient.get("/api/admin/audit", { params });
  return res.data;
};
