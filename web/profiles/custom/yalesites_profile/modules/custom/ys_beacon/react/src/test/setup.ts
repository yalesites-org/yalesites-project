import "@testing-library/jest-dom";
import { vi } from "vitest";

// FluentUI components query matchMedia/ResizeObserver, which jsdom does not
// implement. Provide inert stubs so components render in the test environment.
// The `typeof` guards are for the suites that opt into the `node` environment
// instead (esbuild cannot run under jsdom), where there is no DOM to stub.
if (typeof window !== "undefined" && !window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }));
}

if (typeof window !== "undefined" && !window.ResizeObserver) {
  window.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
}

// jsdom does not implement scrollIntoView, which the chat calls to keep the
// latest message in view.
if (typeof Element !== "undefined" && !Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = vi.fn();
}
