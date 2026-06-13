/**
 * Petit bus d'événements pour déclencher un sync immédiat (polling intelligent)
 * après une action locale (claim / submit / declare), sans dépendre d'un contexte React.
 */
type Listener = () => void;

const listeners = new Set<Listener>();

export function requestMissionSync(): void {
  listeners.forEach((listener) => listener());
}

export function onMissionSyncRequest(listener: Listener): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
