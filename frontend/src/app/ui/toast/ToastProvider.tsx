import * as React from "react";
import { Box } from "@mui/material";

type ToastSeverity = "success" | "info" | "warning" | "error";

export type ToastOptions = {
  message: string;
  severity?: ToastSeverity;
  autoHideDuration?: number;
};

export type ToastApi = {
  show: (options: ToastOptions) => void;
  success: (message: string, autoHideDuration?: number) => void;
  info: (message: string, autoHideDuration?: number) => void;
  warning: (message: string, autoHideDuration?: number) => void;
  error: (message: string, autoHideDuration?: number) => void;
};

const ToastContext = React.createContext<ToastApi | null>(null);

type ToastState = {
  message: string;
  autoHideDuration: number;
  /** Forces a fresh mount (and so the entrance animation) even if the message text repeats. */
  key: number;
};

// docs/design/layouts/layouts.md + prototype (line ~1002 showToast/hasToast) — 2800ms,
// no severity kept beyond the API surface: the design has exactly one toast style,
// never colored per success/warning/error.
const DEFAULT_DURATION = 2800;

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toast, setToast] = React.useState<ToastState | null>(null);
  const timerRef = React.useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const nextKeyRef = React.useRef(0);

  const show = React.useCallback((options: ToastOptions) => {
    clearTimeout(timerRef.current);
    const duration = options.autoHideDuration ?? DEFAULT_DURATION;
    nextKeyRef.current += 1;
    setToast({ message: options.message, autoHideDuration: duration, key: nextKeyRef.current });
    timerRef.current = setTimeout(() => setToast(null), duration);
  }, []);

  React.useEffect(() => () => clearTimeout(timerRef.current), []);

  const api = React.useMemo<ToastApi>(
    () => ({
      show,
      // severity kept in the public API (callers pass it, existing call sites don't
      // need to change) but never changes the toast's appearance — docs/design has a
      // single dark pill style regardless of message type.
      success: (message, autoHideDuration) => show({ message, severity: "success", autoHideDuration }),
      info: (message, autoHideDuration) => show({ message, severity: "info", autoHideDuration }),
      warning: (message, autoHideDuration) => show({ message, severity: "warning", autoHideDuration }),
      error: (message, autoHideDuration) => show({ message, severity: "error", autoHideDuration }),
    }),
    [show]
  );

  return (
    <ToastContext.Provider value={api}>
      {children}

      {toast && (
        <Box
          key={toast.key}
          role="status"
          aria-live="polite"
          sx={{
            position: "fixed",
            left: "50%",
            bottom: "98px",
            transform: "translateX(-50%)",
            zIndex: 1000,
            maxWidth: "min(420px, calc(100vw - 40px))",
            background: "#16202B",
            color: "#fff",
            borderRadius: "12px",
            padding: "13px 18px",
            fontSize: 14,
            fontWeight: 600,
            textAlign: "center",
            boxShadow: "0 6px 16px rgba(22,32,43,.08), 0 16px 40px rgba(22,32,43,.12)",
            animation: "shToastPop 220ms cubic-bezier(0.22, 1, 0.36, 1)",
            "@keyframes shToastPop": {
              from: { opacity: 0, transform: "translateX(-50%) translateY(10px) scale(.98)" },
              to: { opacity: 1, transform: "translateX(-50%)" },
            },
          }}
        >
          {toast.message}
        </Box>
      )}
    </ToastContext.Provider>
  );
}

export function useToastContext() {
  const ctx = React.useContext(ToastContext);
  if (!ctx) {
    throw new Error("useToast must be used within <ToastProvider />");
  }
  return ctx;
}
