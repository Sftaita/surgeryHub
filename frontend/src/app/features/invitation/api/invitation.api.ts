import { apiClient } from "../../../api/apiClient";
import type {
  CompleteAccountResponseDTO,
  InvitationCheckDTO,
} from "./invitation.types";

/**
 * GET /api/invitations/{token}
 * Public — pas d'authentification requise
 */
export const checkInvitation = async (
  token: string,
): Promise<InvitationCheckDTO> => {
  const res = await apiClient.get(`/api/invitations/${token}`);
  return res.data;
};

/**
 * POST /api/invitations/complete
 * Public — multipart/form-data (photo de profil optionnelle)
 */
export const completeInvitation = async (
  formData: FormData,
): Promise<CompleteAccountResponseDTO> => {
  const res = await apiClient.post("/api/invitations/complete", formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data;
};
