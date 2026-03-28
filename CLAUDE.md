# Project Instructions

## Golden Rule

**All animations MUST use GSAP.** No CSS `animation`/`@keyframes`/`transition`, no Web Animations API, no `requestAnimationFrame` for visuals.

## Init Queue

Components push init functions into the global queue. Layout.astro drains it synchronously — no yielding.

```ts
((window as any).__initQueue ??= []).push(initMyComponent);
```

Never call `init()` directly. Never use events to trigger init.

## GSAP Transform Rule

IMPORTANT: Never set CSS `transform`, `opacity`, or `scale` on elements GSAP will animate. GSAP overwrites the entire `transform` property. Use `gsap.set()` for initial states. For centering: `xPercent: -50`, not CSS `translateX(-50%)`.

## gsap.context()

Wrap every init function body in `gsap.context(() => { ... })` for cleanup on rebuild.

## ScrollTrigger IDs

Every ScrollTrigger MUST have a unique `id` string for debugging.

## iOS Mobile (critical)

- `normalizeScroll: true` is required — without it, pins jitter on iOS (WebKit bug #181954, unfixed since 2017)
- Never `scrub: true` — always numeric (`scrub: 0.5` to `scrub: 1`)
- Never `e.preventDefault()` on touch — all touch listeners: `{ passive: true }`
- No `backdrop-filter: blur()` on mobile — desktop only via `@media (min-width: 768px)`
- No pinned horizontal carousels on mobile — use swipe navigation, pin only on desktop
- MANDATORY visibility gate on ALL `repeat: -1` tweens — pause off-screen, resume on re-enter
- Don't create tweens for `display: none` elements on mobile
- SVG is CPU-only on iOS — no continuous `repeat: -1` MorphSVG on mobile
- No `rotation` in `repeat: -1` tweens on mobile — jagged edges on iOS Safari
- Known issue: `normalizeScroll` momentum is truncated at page boundaries (iOS) — architectural in GSAP, no fix

## Resize Rebuild

Layout.astro dispatches `app-rebuild` on resize (width-only guard, ignores address bar height changes). Components listen and rebuild. Never call `ScrollTrigger.refresh()` inside component `build()` — the coordinator handles it centrally.

## Build

```bash
npm run dev    # localhost:4321
npm run build  # zero errors required
```
