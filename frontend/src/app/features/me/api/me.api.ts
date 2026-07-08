import { apiClient } from "../../../api/apiClient";

export type MeResponse = {
  id: number;
  email: string;
  firstname: string | null;
  lastname: string | null;
  profilePictureUrl: string | null;
  role: string;
  instrumentistProfile: {
    id: number;
    email: string;
    firstname: string | null;
    lastname: string | null;
    displayName: string;
    active: boolean;
    employmentType: string | null;
    defaultCurrency: string | null;
    hourlyRate: string | null;
    consultationFee: string | null;
    profilePicturePath: string | null;
    siteMemberships: unknown[];
    specialties: string[];
  } | null;
  sites: Array<{ id: number; name: string; timezone: string }>;
  activeSiteId: number | null;
};

/**
 * GET /api/me
 */
export async function fetchMe(): Promise<MeResponse> {
  const res = await apiClient.get<MeResponse>("/api/me");
  return res.data;
}

/**
 * POST /api/me/profile-picture
 * multipart/form-data, champ "profilePicture". Retourne le MeResponse à jour.
 */
export async function uploadProfilePicture(file: File): Promise<MeResponse> {
  const formData = new FormData();
  formData.append("profilePicture", file);

  const res = await apiClient.post<MeResponse>("/api/me/profile-picture", formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data;
}
