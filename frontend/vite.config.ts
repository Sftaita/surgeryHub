import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

console.log("VITE CONFIG LOADED - SURGICALHUB");

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
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
