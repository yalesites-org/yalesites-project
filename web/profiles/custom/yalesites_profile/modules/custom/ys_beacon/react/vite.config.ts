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
  // must not ship. Stripping at build time (rather than deleting the calls)
  // keeps console logging available under `npm run dev`. Note this drops ALL
  // console.* from production, the two console.error calls in Chat.tsx
  // included - a deliberate trade of live-devtools diagnosability for a clean
  // visitor console.
  esbuild: command === "build" ? { drop: ["console", "debugger"] } : {},
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
