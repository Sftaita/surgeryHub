import * as React from "react";

type ReplayContextType = {
  /** Rouvre le tutoriel depuis Paramètres, sans jamais toucher l'état serveur. */
  requestReplay: () => void;
};

const ReplayContext = React.createContext<ReplayContextType | null>(null);

export function InstrumentistOnboardingReplayProvider({
  children,
  onReplayRequested,
}: {
  children: React.ReactNode;
  onReplayRequested: () => void;
}) {
  const value = React.useMemo(() => ({ requestReplay: onReplayRequested }), [onReplayRequested]);
  return <ReplayContext.Provider value={value}>{children}</ReplayContext.Provider>;
}

/** Utilisé par Paramètres ("Revoir la présentation de SurgicalHub") pour relancer le tutoriel. */
export function useInstrumentistOnboardingReplay(): ReplayContextType {
  const ctx = React.useContext(ReplayContext);
  if (!ctx) {
    throw new Error("useInstrumentistOnboardingReplay must be used inside InstrumentistOnboardingReplayProvider");
  }
  return ctx;
}
