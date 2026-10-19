// @vitest-environment node
import { existsSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, it, expect } from "vitest";

// viteConfig.test.ts pins the `esbuild.pure` list, but pure annotations only
// strip calls when the build is minified: with `build.minify: false` every
// noisy console call ships and that test stays green. So this reads the built
// bundle itself - a fresh build in CI, the committed one locally
// (yalesites-org/YaleSites-Internal#1739).

const BUNDLE = fileURLToPath(
  new URL("../../static/assets/index.js", import.meta.url)
);

// Dot, optional-chain, and bracket forms: console.log, console?.log,
// console["log"]. The minifier rewrites brackets to dots today, but matching
// both costs nothing.
const CONSOLE_CALL =
  /\bconsole\s*(?:\??\.\s*([A-Za-z_$][\w$]*)|\[\s*["'`]([^"'`]+)["'`]\s*\])/g;

const consoleMethods = (source: string): string[] =>
  [...source.matchAll(CONSOLE_CALL)].map((m) => m[1] ?? m[2]);

describe("Beacon built bundle — console output (#1739)", () => {
  it("exists", () => {
    expect(
      existsSync(BUNDLE),
      `${BUNDLE} is missing - run \`npm run build\` first`
    ).toBe(true);
  });

  const methods = existsSync(BUNDLE)
    ? consoleMethods(readFileSync(BUNDLE, "utf8"))
    : [];

  it("contains no console call other than console.error", () => {
    const stray = [...new Set(methods.filter((m) => m !== "error"))].sort();
    expect(stray, `stray console methods: ${stray.join(", ")}`).toEqual([]);
  });

  // See the console.error note in vite.config.ts.
  it("still contains console.error", () => {
    expect(methods).toContain("error");
  });
});
