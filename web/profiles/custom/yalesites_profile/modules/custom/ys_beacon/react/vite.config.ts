import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// The repo-wide default Lando host, as shipped in .lando.local.example.yml.
// Used only when YS_BEACON_PROXY_TARGET is unset; see the README.
const DEFAULT_PROXY_TARGET = "https://yalesites-platform.lndo.site";

// https://vitejs.dev/config/
export default defineConfig(({ command }) => ({
  base: "/profiles/custom/yalesites_profile/modules/custom/ys_beacon/react/static/",
  plugins: [react()],
  // This bundle is committed and served to every site visitor, so debug output
  // must not ship. The noisy levels are marked pure, which lets minification
  // drop them from production while leaving them working under `npm run dev`.
  //
  // console.error is deliberately NOT stripped: Chat.tsx's "Conversation not
  // found" branch returns with no UI feedback at all, so the console is the
  // only trace such a failure leaves, and the source map we ship on purpose
  // (see "Source map decision" in ../README.md) is worth little if nothing
  // ever reaches the console.
  esbuild:
    command === "build"
      ? {
          pure: [
            "console.log",
            "console.debug",
            "console.info",
            "console.warn",
            "console.trace",
          ],
          drop: ["debugger"],
        }
      : {},
  build: {
    outDir: "static",
    emptyOutDir: true,
    // Deliberately shipped - see "Source map decision" in ../README.md before
    // changing this.
    sourcemap: true,
    rollupOptions: {
      output: {
        entryFileNames: `assets/[name].js`,
        chunkFileNames: `assets/[name].js`,
        assetFileNames: `assets/[name].[ext]`,
      },
    },
  },
  server: {
    proxy: {
      "/api/ys-beacon": {
        // Every developer's Lando host differs, so this is read from the
        // environment rather than hardcoded to one machine.
        target: process.env.YS_BEACON_PROXY_TARGET || DEFAULT_PROXY_TARGET,
        changeOrigin: true,
        secure: false,
      },
    },
  },
}));
