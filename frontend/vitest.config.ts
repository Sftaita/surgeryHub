/// <reference types="vitest" />
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./src/test/setup.ts"],
    // Capped at ~25% of logical cores — measured to eliminate timeout flakiness
    // entirely (10/10 clean runs) while also running faster than the
    // unrestricted default. See frontend/CONTRIBUTING.md "Test infrastructure".
    maxWorkers: "25%",
  },
});
