let refreshPromise: Promise<{
  accessToken: string;
  refreshToken: string;
}> | null = null;

export function getRefreshPromise() {
  return refreshPromise;
}

export function setRefreshPromise(
  p: Promise<{ accessToken: string; refreshToken: string }> | null
) {
  refreshPromise = p;
}
