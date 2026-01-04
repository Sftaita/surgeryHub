import { PropsWithChildren } from "react";
import { QueryClientProvider } from "@tanstack/react-query";
import { CssBaseline, ThemeProvider, createTheme } from "@mui/material";
import { queryClient } from "./queryClient";
import { AppErrorBoundary } from "../errors/AppErrorBoundary";
import { AuthProvider } from "../auth/AuthContext";

const theme = createTheme();

export function AppProviders({ children }: PropsWithChildren) {
  return (
    <AppErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <ThemeProvider theme={theme}>
          <CssBaseline />
          <AuthProvider>{children}</AuthProvider>
        </ThemeProvider>
      </QueryClientProvider>
    </AppErrorBoundary>
  );
}
