/// <reference types="vitest" />
import { defineConfig } from "vitest/config";

// Test-only config. Kept separate from vite.config.ts so the production build
// (and the committed bundle the CI verify step rebuilds) is untouched by the
// test toolchain. The react fast-refresh plugin is intentionally omitted (it
// is unused in tests and its preamble is incompatible with Vitest); esbuild's
// automatic JSX runtime handles the transform instead.
export default defineConfig({
  esbuild: { jsx: "automatic" },
  test: {
    globals: true,
    environment: "jsdom",
    setupFiles: ["./src/test/setup.ts"],
    css: true,
    include: ["src/**/*.test.{ts,tsx}"],
  },
});
