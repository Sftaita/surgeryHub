import { PropsWithChildren } from "react";
import { QueryClientProvider } from "@tanstack/react-query";
import { CssBaseline, ThemeProvider, createTheme } from "@mui/material";
import { queryClient } from "./queryClient";
import { AppErrorBoundary } from "../errors/AppErrorBoundary";
import { AuthProvider } from "../auth/AuthContext";

// Toast (global)
import { ToastProvider } from "../ui/toast/ToastProvider";

// MUI X Date Pickers
import { LocalizationProvider } from "@mui/x-date-pickers/LocalizationProvider";
import { AdapterDayjs } from "@mui/x-date-pickers/AdapterDayjs";

// Dayjs + timezone
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import "dayjs/locale/fr";

dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.tz.setDefault("Europe/Brussels");
dayjs.locale("fr");

const theme = createTheme({
  palette: {
    primary: {
      light:        "#63C9A3",
      main:         "#42A882",
      dark:         "#2E7A5E",
      contrastText: "#ffffff",
    },
    secondary: {
      main:         "#2563EB",
      contrastText: "#ffffff",
    },
    background: {
      default: "#F5F7FA",
      paper:   "#FFFFFF",
    },
    error:   { main: "#EF4444" },
    warning: { main: "#F59E0B" },
    success: { main: "#42A882" },
  },
  shape: { borderRadius: 12 },
  typography: {
    fontFamily: "'Inter', system-ui, sans-serif",
    h5: { fontWeight: 800, letterSpacing: -0.5 },
    h6: { fontWeight: 700 },
    subtitle1: { fontWeight: 700 },
    subtitle2: { fontWeight: 600 },
    button: { textTransform: "none" as const, fontWeight: 600 },
  },
  components: {
    MuiAppBar: {
      styleOverrides: {
        root: { boxShadow: "0 1px 8px rgba(0,0,0,0.06)" },
      },
    },
    MuiButton: {
      styleOverrides: {
        root: { borderRadius: 999, fontWeight: 600 },
        containedPrimary: {
          boxShadow: "0 4px 14px rgba(66,168,130,.35)",
          "&:hover": { boxShadow: "0 6px 20px rgba(66,168,130,.45)" },
        },
      },
    },
    MuiChip: {
      styleOverrides: { root: { fontWeight: 600 } },
    },
    MuiCard: {
      styleOverrides: { root: { boxShadow: "0 2px 12px rgba(0,0,0,.07)" } },
    },
    MuiTextField: {
      defaultProps: { variant: "outlined" as const, size: "small" as const },
    },
  },
});

export function AppProviders({ children }: PropsWithChildren) {
  return (
    <AppErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <ThemeProvider theme={theme}>
          <CssBaseline />
          <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale="fr">
            <ToastProvider>
              <AuthProvider>{children}</AuthProvider>
            </ToastProvider>
          </LocalizationProvider>
        </ThemeProvider>
      </QueryClientProvider>
    </AppErrorBoundary>
  );
}
