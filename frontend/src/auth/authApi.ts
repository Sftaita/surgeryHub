import { http } from "../api/http";
import type { LoginResponseDTO, RefreshResponseDTO, MeDTO } from "../api/types";

export async function login(
  email: string,
  password: string
): Promise<LoginResponseDTO> {
  const { data } = await http.post<LoginResponseDTO>("/api/auth/login", {
    email,
    password,
  });
  return data;
}

export async function refresh(
  refreshToken: string
): Promise<RefreshResponseDTO> {
  const { data } = await http.post<RefreshResponseDTO>("/api/auth/refresh", {
    refreshToken,
  });
  return data;
}

export async function fetchMe(): Promise<MeDTO> {
  const { data } = await http.get<MeDTO>("/api/me");
  return data;
}
