export type InvitationStatus = "valid" | "invalid" | "used" | "expired";

export type InvitationCheckDTO = {
  status: InvitationStatus;
  valid: boolean;
  invitation?: {
    email: string;
    firstname: string | null;
    lastname: string | null;
    displayName: string;
    expiresAt: string;
  };
};

export type CompleteAccountResponseDTO = {
  status: "account_completed";
};
