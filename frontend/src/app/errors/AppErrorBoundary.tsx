import React from "react";

type Props = { children: React.ReactNode };
type State = { hasError: boolean; error?: unknown };

export class AppErrorBoundary extends React.Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(error: unknown): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: unknown) {
    // Plus tard: Sentry, logging, etc.
    console.error("App crashed:", error);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div style={{ padding: 16 }}>
          <h2>Une erreur est survenue</h2>
          <p>Recharge la page. Si le problème persiste, contacte le support.</p>
        </div>
      );
    }
    return this.props.children;
  }
}
