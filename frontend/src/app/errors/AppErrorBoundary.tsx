import * as Sentry from "@sentry/react";
import React from "react";

type Props  = { children: React.ReactNode };
type State  = { hasError: boolean; error?: unknown };

export class AppErrorBoundary extends React.Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(error: unknown): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: unknown) {
    console.error("App crashed:", error);
    Sentry.captureException(error);
  }

  render() {
    if (this.state.hasError) {
      const msg =
        this.state.error instanceof Error
          ? this.state.error.message
          : String(this.state.error ?? "Erreur inconnue");

      return (
        <div style={{
          display: "flex", alignItems: "center", justifyContent: "center",
          minHeight: "100vh", background: "#F8FAFC",
          fontFamily: "'Inter', 'Roboto', sans-serif",
        }}>
          <div style={{
            background: "#fff", borderRadius: 12,
            boxShadow: "0 4px 24px rgba(0,0,0,.10)",
            padding: "40px 48px", maxWidth: 460, width: "100%",
            textAlign: "center",
          }}>
            {/* Icon */}
            <div style={{
              width: 56, height: 56, borderRadius: "50%",
              background: "#FEE2E2", margin: "0 auto 20px",
              display: "flex", alignItems: "center", justifyContent: "center",
            }}>
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"
                  stroke="#DC2626" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </div>

            <h2 style={{ margin: "0 0 8px", fontSize: 20, fontWeight: 700, color: "#111827" }}>
              Une erreur est survenue
            </h2>
            <p style={{ margin: "0 0 20px", fontSize: 14, color: "#6B7280", lineHeight: 1.6 }}>
              L'application a rencontré un problème inattendu.
              Rechargez la page — si le problème persiste, contactez le support.
            </p>

            {/* Error detail (dev only) */}
            {process.env.NODE_ENV !== "production" && (
              <pre style={{
                background: "#FEF2F2", color: "#991B1B",
                borderRadius: 6, padding: "10px 14px",
                fontSize: 12, textAlign: "left",
                overflowX: "auto", margin: "0 0 20px",
                whiteSpace: "pre-wrap", wordBreak: "break-word",
              }}>
                {msg}
              </pre>
            )}

            <button
              onClick={() => window.location.reload()}
              style={{
                background: "#2563EB", color: "#fff",
                border: "none", borderRadius: 8,
                padding: "10px 24px", fontSize: 14, fontWeight: 600,
                cursor: "pointer", transition: "background .15s",
              }}
              onMouseOver={(e) => (e.currentTarget.style.background = "#1D4ED8")}
              onMouseOut={(e)  => (e.currentTarget.style.background = "#2563EB")}
            >
              Recharger la page
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
