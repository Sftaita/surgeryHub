/**
 * `100vh` doesn't account for iOS Safari's dynamic address bar (or the split between
 * a browser tab and an installed standalone PWA), causing content to be clipped or an
 * unwanted scroll gap — `100dvh` is the fix, but needs a `vh` fallback for browsers
 * without dynamic-viewport-unit support (audit PWA/mobile/admin 2026-07-29, Lot 4).
 * `@supports` feature-detection is used instead of relying on declaration order,
 * since MUI `sx` objects can't hold the same CSS property twice.
 */
export function dvh(prop: "minHeight" | "height" | "maxHeight", vhExpr: string) {
  return {
    [prop]: vhExpr,
    "@supports (height: 100dvh)": { [prop]: vhExpr.replaceAll("vh", "dvh") },
  } as const;
}
