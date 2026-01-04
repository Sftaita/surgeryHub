import { useQuery } from "@tanstack/react-query";
import { fetchMe } from "./authApi";
import { authStore } from "./authStore";

export function useMe() {
  const token = authStore.getAccessToken();

  return useQuery({
    queryKey: ["me"],
    queryFn: fetchMe,
    enabled: !!token,
    retry: false,
  });
}
