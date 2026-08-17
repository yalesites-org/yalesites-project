import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// https://vitejs.dev/config/
export default defineConfig({
  base: "/profiles/custom/yalesites_profile/modules/custom/ys_beacon/react/static/",
  plugins: [react()],
  build: {
    outDir: "static",
    emptyOutDir: true,
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
        target: "https://yalesites-fable.lndo.site",
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
