import * as React from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "../../../auth/AuthContext";
import { useToast } from "../../../ui/toast/useToast";
import { fetchInstrumentistMissionSync } from "../api/missions.api";
import { applyMissionSyncToCache } from "./applyMissionSync";
import { onMissionSyncRequest } from "./missionSyncBus";

const POLL_INTERVAL_MS = 30_000;

// IMPORTANT : lastSyncAt provient de `serverTime` (réponse backend), jamais de l'heure locale.
const LAST_SYNC_STORAGE_KEY = "surgicalhub.instrumentist.missionSync.lastSyncAt";

function readLastSyncAt(): string | null {
  try {
    return window.localStorage.getItem(LAST_SYNC_STORAGE_KEY);
  } catch {
    return null;
  }
}

function writeLastSyncAt(value: string): void {
  try {
    window.localStorage.setItem(LAST_SYNC_STORAGE_KEY, value);
  } catch {
    // stockage indisponible (mode privé...) — pas bloquant, le prochain sync repartira de zéro
  }
}

/**
 * V1 "polling intelligent" — synchronise les missions instrumentiste sans Mercure/WebSocket.
 *
 * Le polling n'est actif que si :
 * - l'utilisateur est connecté et a le rôle INSTRUMENTIST
 * - l'onglet est visible
 * - le réseau est online
 *
 * Fréquence : 30s quand actif. Refresh immédiat sur retour online/focus ou après
 * une action locale (claim/submit/declare) via `requestMissionSync()`.
 */
export function useInstrumentistMissionSync(): void {
  const queryClient = useQueryClient();
  const { state } = useAuth();
  const toast = useToast();

  const isInstrumentist =
    state.status === "authenticated" && state.user.role === "INSTRUMENTIST";
  const currentUserId = state.status === "authenticated" ? state.user.id : null;

  const [isOnline, setIsOnline] = React.useState<boolean>(() =>
    typeof navigator === "undefined" ? true : navigator.onLine,
  );
  const [isVisible, setIsVisible] = React.useState<boolean>(() =>
    typeof document === "undefined" ? true : document.visibilityState === "visible",
  );

  const inFlightRef = React.useRef(false);

  const sync = React.useCallback(async () => {
    if (!isInstrumentist) return;
    if (inFlightRef.current) return;

    inFlightRef.current = true;
    try {
      const since = readLastSyncAt();
      const result = await fetchInstrumentistMissionSync(since);
      writeLastSyncAt(result.serverTime);

      if (!result.changed) return;

      const { newOpenOffers } = applyMissionSyncToCache(queryClient, result, currentUserId);

      // Anti-spam : un seul toast groupé, même si plusieurs offres arrivent ensemble.
      if (newOpenOffers.length === 1) {
        toast.info("Nouvelle mission disponible");
      } else if (newOpenOffers.length > 1) {
        toast.info(`${newOpenOffers.length} nouvelles missions disponibles`);
      }
    } catch {
      // best-effort : on retentera au prochain cycle / événement réseau/focus
    } finally {
      inFlightRef.current = false;
    }
  }, [isInstrumentist, queryClient, currentUserId, toast]);

  // Connectivité réseau : pause si offline, refresh immédiat au retour online.
  React.useEffect(() => {
    const handleOnline = () => {
      setIsOnline(true);
      void sync();
    };
    const handleOffline = () => setIsOnline(false);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, [sync]);

  // Visibilité de l'onglet / focus fenêtre : pause si caché, refresh immédiat au retour.
  React.useEffect(() => {
    const handleVisibilityChange = () => {
      const visible = document.visibilityState === "visible";
      setIsVisible(visible);
      if (visible) void sync();
    };
    const handleFocus = () => void sync();

    document.addEventListener("visibilitychange", handleVisibilityChange);
    window.addEventListener("focus", handleFocus);
    return () => {
      document.removeEventListener("visibilitychange", handleVisibilityChange);
      window.removeEventListener("focus", handleFocus);
    };
  }, [sync]);

  // Refresh immédiat demandé explicitement après une action locale (claim/submit/declare).
  React.useEffect(() => onMissionSyncRequest(() => void sync()), [sync]);

  // Polling périodique — actif uniquement si connecté/INSTRUMENTIST + visible + online.
  React.useEffect(() => {
    if (!isInstrumentist || !isVisible || !isOnline) return;

    void sync();
    const id = window.setInterval(() => void sync(), POLL_INTERVAL_MS);
    return () => window.clearInterval(id);
  }, [isInstrumentist, isVisible, isOnline, sync]);
}
