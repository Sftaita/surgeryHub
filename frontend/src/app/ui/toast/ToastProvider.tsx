import * as React from "react";
import { Alert, Snackbar } from "@mui/material";

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
  open: boolean;
  message: string;
  severity: ToastSeverity;
  autoHideDuration: number;
};

const DEFAULT_DURATION = 2500;

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = React.useState<ToastState>({
    open: false,
    message: "",
    severity: "info",
    autoHideDuration: DEFAULT_DURATION,
  });

  const close = React.useCallback(() => {
    setState((s) => ({ ...s, open: false }));
  }, []);

  const show = React.useCallback((options: ToastOptions) => {
    setState({
      open: true,
      message: options.message,
      severity: options.severity ?? "info",
      autoHideDuration: options.autoHideDuration ?? DEFAULT_DURATION,
    });
  }, []);

  const api = React.useMemo<ToastApi>(
    () => ({
      show,
      success: (message, autoHideDuration) =>
        show({ message, severity: "success", autoHideDuration }),
      info: (message, autoHideDuration) =>
        show({ message, severity: "info", autoHideDuration }),
      warning: (message, autoHideDuration) =>
        show({ message, severity: "warning", autoHideDuration }),
      error: (message, autoHideDuration) =>
        show({ message, severity: "error", autoHideDuration }),
    }),
    [show]
  );

  return (
    <ToastContext.Provider value={api}>
      {children}

      <Snackbar
        open={state.open}
        autoHideDuration={state.autoHideDuration}
        onClose={close}
        anchorOrigin={{ vertical: "bottom", horizontal: "center" }}
      >
        <Alert
          onClose={close}
          severity={state.severity}
          variant="filled"
          sx={{ width: "100%" }}
        >
          {state.message}
        </Alert>
      </Snackbar>
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
