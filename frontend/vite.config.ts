import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import basicSsl from "@vitejs/plugin-basic-ssl";

const backendUrl = process.env.BACKEND_URL ?? "https://localhost:8000";

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), basicSsl()],
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
  build: {
    chunkSizeWarningLimit: 700,
    rollupOptions: {
      output: {
        manualChunks: {
          "vendor-react": ["react", "react-dom", "react-router-dom"],
          "vendor-mui": ["@mui/material", "@mui/system", "@emotion/react", "@emotion/styled"],
          "vendor-mui-icons": ["@mui/icons-material"],
          "vendor-mui-pickers": ["@mui/x-date-pickers"],
          "vendor-mui-grid": ["@mui/x-data-grid"],
          "vendor-query": ["@tanstack/react-query"],
          "vendor-calendar": [
            "@fullcalendar/react",
            "@fullcalendar/daygrid",
            "@fullcalendar/timegrid",
            "@fullcalendar/core",
          ],
          "vendor-dayjs": ["dayjs"],
          "vendor-axios": ["axios"],
        },
      },
    },
  },
  server: {
    host: true,
    proxy: {
      "/api": {
        target: backendUrl,
        changeOrigin: true,
        secure: false,
      },
      "/uploads": {
        target: backendUrl,
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
