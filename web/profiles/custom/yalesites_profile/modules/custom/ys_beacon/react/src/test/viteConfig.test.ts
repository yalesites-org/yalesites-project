// @vitest-environment node
import { describe, it, expect, afterEach, beforeEach } from "vitest";
import type { ConfigEnv, UserConfig } from "vite";
import viteConfig from "../../vite.config";

// The Beacon chat bundle is committed to the repo and served to every site
// visitor, so the build config is part of the shipped product rather than local
// tooling: a stray console call reaches real visitors' consoles, and a
// hardcoded proxy host makes `npm run dev` work on exactly one developer's
// machine. These tests pin both (yalesites-org/YaleSites-Internal#1691).
// Shipping the source map is a separate, already-recorded decision - see
// "Source map decision" in ../../../README.md.

const PROXY_PATH = "/api/ys-beacon";

// The config is a function so it can branch on `command` and read the
// environment per call.
const resolve = (command: ConfigEnv["command"]): UserConfig =>
  viteConfig({
    command,
    mode: command === "build" ? "production" : "development",
  });

const proxyTarget = (config: UserConfig): string | undefined => {
  const entry = config.server?.proxy?.[PROXY_PATH];
  return typeof entry === "string" ? entry : entry?.target;
};

const esbuildOptions = (config: UserConfig) => {
  const esbuild = config.esbuild;
  return !esbuild || typeof esbuild === "boolean" ? {} : esbuild;
};

const dropped = (config: UserConfig): string[] =>
  esbuildOptions(config).drop ?? [];

const pured = (config: UserConfig): string[] =>
  esbuildOptions(config).pure ?? [];

// Cleared both before and after: before, so an ambient YS_BEACON_PROXY_TARGET in
// the developer's shell cannot fail the fallback test (and a `-t`-filtered run
// behaves like a full one); after, so this file does not leak a value into
// whatever suite shares the worker next.
const clearProxyTarget = () => {
  delete process.env.YS_BEACON_PROXY_TARGET;
};
beforeEach(clearProxyTarget);
afterEach(clearProxyTarget);

describe("Beacon vite config — production bundle hygiene (#1691)", () => {
  it("marks the noisy console levels pure so minification removes them", () => {
    expect(pured(resolve("build"))).toEqual(
      expect.arrayContaining([
        "console.log",
        "console.debug",
        "console.info",
        "console.warn",
        "console.trace",
      ])
    );
  });

  // Deliberate: some failure paths return without telling the visitor
  // anything, so console.error is the only trace they leave.
  it("keeps console.error in the production bundle", () => {
    expect(pured(resolve("build"))).not.toContain("console.error");
    expect(dropped(resolve("build"))).not.toContain("console");
  });

  it("drops debugger statements from the production bundle", () => {
    expect(dropped(resolve("build"))).toContain("debugger");
  });

  it("leaves every console call working in the dev server", () => {
    expect(dropped(resolve("serve"))).not.toContain("console");
    expect(pured(resolve("serve"))).toEqual([]);
  });
});

describe("Beacon vite config — dev server proxy target (#1691)", () => {
  it("reads the proxy target from YS_BEACON_PROXY_TARGET", () => {
    process.env.YS_BEACON_PROXY_TARGET = "https://example.lndo.site";
    expect(proxyTarget(resolve("serve"))).toBe("https://example.lndo.site");
  });

  it("falls back to the host documented in .lando.local.example.yml", () => {
    expect(proxyTarget(resolve("serve"))).toBe(
      "https://yalesites-platform.lndo.site"
    );
  });

  // `||`, not `??`: an exported-but-empty value means unset, not "proxy to "".
  it("treats an empty YS_BEACON_PROXY_TARGET as unset", () => {
    process.env.YS_BEACON_PROXY_TARGET = "";
    expect(proxyTarget(resolve("serve"))).toBe(
      "https://yalesites-platform.lndo.site"
    );
  });
});
