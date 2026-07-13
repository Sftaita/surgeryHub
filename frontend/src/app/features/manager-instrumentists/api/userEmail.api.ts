import { apiClient } from "../../../api/apiClient";

export type UserEmailChangeWarningDTO = {
  code: string;
  recipient: "old" | "new";
  message: string;
};

export type UserEmailChangeResponseDTO = {
  user: {
    id: number;
    email: string;
    firstname: string | null;
    lastname: string | null;
    displayName: string;
    profilePicturePath: string | null;
  };
  warnings: UserEmailChangeWarningDTO[];
};

/**
 * PATCH /api/users/{id}/email — generic User-level endpoint, shared by the
 * instrumentist and surgeon drawers (the email belongs to the same User aggregate
 * regardless of role, so this is intentionally not duplicated per feature).
 */
export const patchUserEmail = async (
  userId: number,
  email: string,
): Promise<UserEmailChangeResponseDTO> => {
  const res = await apiClient.patch(`/api/users/${userId}/email`, { email });
  return res.data;
};
