export type FirmDTO = {
  id: number;
  name: string;
};

export type MaterialItemDTO = {
  id: number;
  firm: FirmDTO | null;
  label: string;
  referenceCode: string;
  unit: string;
  isImplant: boolean;
};

export type MaterialItemsListResponseDTO = {
  items: MaterialItemDTO[];
  total: number;
  page: number;
  limit: number;
};

export type CreateMaterialItemBody = {
  firmId: number;
  label: string;
  unit: string;
  referenceCode?: string;
  isImplant: boolean;
};

export type UpdateMaterialItemBody = {
  firmId?: number;
  label?: string;
  unit?: string;
  referenceCode?: string;
  isImplant?: boolean;
};

export type MaterialRequestStatus = "PENDING" | "RESOLVED" | "IGNORED";

export type MaterialRequestDTO = {
  id: number;
  status: MaterialRequestStatus;
  label: string;
  referenceCode: string | null;
  comment: string | null;
  createdAt: string;
  mission: {
    id: number;
    site: string | null;
  } | null;
  requestedBy: {
    id: number;
    displayName: string;
  } | null;
  materialItem: MaterialItemDTO | null;
};

export type MaterialRequestsListResponseDTO = {
  items: MaterialRequestDTO[];
  total: number;
};
