// Global init queue drained by Layout.astro on astro:page-load.
// Components register their init fn at module load; Layout collects
// them into a persistent registry and re-runs on every navigation.
// See .claude/rules/astro-viewtransitions.md → "GSAP Lifecycle Pattern".

declare global {
  interface Window {
    __initQueue?: Array<() => void>;
  }
}

export function registerInit(fn: () => void): void {
  (window.__initQueue ??= []).push(fn);
}
