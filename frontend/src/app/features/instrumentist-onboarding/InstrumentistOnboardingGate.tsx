import * as React from "react";
import { useMutation } from "@tanstack/react-query";
import { useAuth } from "../../auth/AuthContext";
import { useToast } from "../../ui/toast/useToast";
import { completeInstrumentistOnboarding } from "./api/onboarding.api";
import { InstrumentistOnboardingModal } from "./InstrumentistOnboardingModal";
import { InstrumentistOnboardingReplayProvider } from "./InstrumentistOnboardingReplayContext";

/**
 * Monté une fois dans RequireAppAccess, au-dessus de toutes les routes
 * authentifiées — même patron que ProfilePhotoPromptGate.tsx. S'affiche
 * automatiquement pour un instrumentiste dont l'onboarding n'est pas encore
 * marqué terminé côté serveur (première connexion, ou connexions suivantes
 * tant qu'il n'a pas été mené à son terme) ; jamais pour un autre rôle.
 * Expose aussi `requestReplay()` (via contexte) pour Paramètres.
 */
export function InstrumentistOnboardingGate({ children }: { children: React.ReactNode }) {
  const { state, refreshUser } = useAuth();
  const toast = useToast();
  const [forceOpen, setForceOpen] = React.useState(false);
  // Une fermeture anticipée (X) ne doit jamais compléter l'onboarding côté serveur, mais
  // doit tout de même fermer la modale pour le reste de cette session — sinon, tant que
  // serverCompleted reste false, `open` resterait vrai indéfiniment et le bouton de
  // fermeture n'aurait aucun effet observable. Réinitialisé à chaque nouveau montage du
  // Gate (rechargement de page, nouvelle connexion) : l'onboarding réapparaît alors, ce
  // qui est le comportement voulu tant qu'il n'est pas réellement terminé.
  const [dismissedThisSession, setDismissedThisSession] = React.useState(false);

  const isAuthenticated = state.status === "authenticated";
  const isInstrumentist = isAuthenticated && state.user.role === "INSTRUMENTIST";
  const serverCompleted = isAuthenticated ? !!state.user.instrumentistOnboardingCompleted : true;

  const mutation = useMutation({
    mutationFn: completeInstrumentistOnboarding,
    onSuccess: async () => {
      await refreshUser();
      setForceOpen(false);
    },
    onError: () => toast.error("Une erreur est survenue, réessayez."),
  });

  const open = isInstrumentist && ((!serverCompleted && !dismissedThisSession) || forceOpen);

  function handleDismiss() {
    setDismissedThisSession(true);
    setForceOpen(false);
  }

  return (
    <InstrumentistOnboardingReplayProvider onReplayRequested={() => setForceOpen(true)}>
      {children}
      {isInstrumentist && (
        <InstrumentistOnboardingModal
          open={open}
          onDismiss={handleDismiss}
          onFinish={() => mutation.mutate()}
          finishing={mutation.isPending}
        />
      )}
    </InstrumentistOnboardingReplayProvider>
  );
}
