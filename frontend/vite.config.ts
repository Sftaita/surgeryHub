import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

console.log("VITE CONFIG LOADED - SURGICALHUB");

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    // Empêche Vite de bundler plusieurs instances runtime (cause classique des "Invalid hook call")
    dedupe: ["react", "react-dom", "@emotion/react", "@emotion/styled"],
  },
  optimizeDeps: {
    // Stabilise l'optimisation des deps MUI (DataGrid / X Date Pickers) sous Vite
    include: [
      "@mui/material",
      "@mui/system",
      "@mui/x-date-pickers",
      "@mui/x-data-grid",
      "dayjs",
      "dayjs/plugin/utc",
      "dayjs/plugin/timezone",
      "dayjs/locale/fr",
    ],
  },
  server: {
    proxy: {
      "/api": {
        target: "https://localhost:8000",
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
