# Project Instructions

## Golden Rule

**All animations MUST use GSAP.** No CSS `animation`/`@keyframes`/`transition`, no Web Animations API, no `requestAnimationFrame` for visuals.

## Default: no ScrollSmoother

Do NOT add ScrollSmoother unless explicitly requested. Native scroll + `ScrollTrigger.normalizeScroll(true)` covers iOS pin stability. ScrollSmoother is rarely essential, breaks with Astro ClientRouter (body caching), and is often added out of habit. Only use it when the user asks for "smooth/silky scrolling" as a specific UX requirement.

## Init Queue

Components push init functions into the global queue. Layout.astro drains it synchronously — no yielding.

```ts
((window as any).__initQueue ??= []).push(initMyComponent);
```

Never call `init()` directly. Never use events to trigger init.

## GSAP Transform Rule

IMPORTANT: Never set CSS `transform`, `opacity`, or `scale` on elements GSAP will animate. GSAP overwrites the entire `transform` property. Use `gsap.set()` for initial states. For centering: `xPercent: -50`, not CSS `translateX(-50%)`.

Corollary: if GSAP does NOT animate a property, keep it in CSS — `gsap.set()` converts all values (including `%`, `vw`) to pixels, freezing them. Example: `top: 50%` in CSS + `gsap.set(el, { yPercent: -50 })` for the animated part.

## gsap.matchMedia()

Components use `gsap.matchMedia()` for animation setup. Each breakpoint gets its own callback — matchMedia auto-reverts all GSAP objects when the condition stops matching, and re-runs the callback when it matches again. State that must survive breakpoint crossing (e.g., `hasRevealed`, `accentRevealed`) lives outside matchMedia. DOM listeners use AbortController, aborted in the cleanup function returned from the callback.

## ScrollTrigger IDs

Every ScrollTrigger MUST have a unique `id` string for debugging.

## iOS Mobile (critical)

- iOS pin stability: `ScrollTrigger.normalizeScroll(true)` (static method — ScrollSmoother plugin NOT required). Enable **conditionally per page**: only when the page has pinned ScrollTriggers. On pin-less pages it intercepts scroll events with no benefit, making scroll feel slower. WebKit bug #181954, unfixed since 2017.
- Never `scrub: true` — always numeric (`scrub: 0.5` to `scrub: 1`)
- Never `e.preventDefault()` on touch — all touch listeners: `{ passive: true }`
- No `backdrop-filter: blur()` on mobile — desktop only via `@media (min-width: 768px)`
- No pinned horizontal carousels on mobile — use swipe navigation, pin only on desktop
- MANDATORY visibility gate on ALL `repeat: -1` tweens — pause off-screen, resume on re-enter
- Don't create tweens for `display: none` elements on mobile
- SVG is CPU-only on iOS — no continuous `repeat: -1` MorphSVG on mobile
- No `rotation` in `repeat: -1` tweens on mobile — jagged edges on iOS Safari
- Known issue: `normalizeScroll` momentum is truncated at page boundaries (iOS) — architectural in GSAP, no fix

## No Markup Duplication

Never repeat the same markup. Apply the right deduplication strategy:
- **SVG icons**: `<symbol>` + `<use href="#id">`, `fill="currentColor"`, `aria-hidden="true"`. **NEVER `<img>` for icons.**
- **SVG non interattivi** (loghi, illustrazioni): `<img src="file.svg">`.
- **Repeated HTML blocks** (cards, list items, links with same structure): extract into Astro components immediately — never copy-paste the same HTML more than once.
- **Repeated style patterns**: extract into Tailwind utility classes or CSS custom properties.

## Resize Strategy

**No centralized rebuild.** Layout.astro has no resize handler. Components are autonomous.

Two-tier resize:
1. **Within a breakpoint** (every frame, non-debounced): pin-spacer width fix + `timeline.invalidate()` + `timeline.progress(timeline.progress())`. GSAP's built-in auto-refresh (200ms debounce) handles `ScrollTrigger.refresh()`.
2. **At breakpoint crossing** (768px): `gsap.matchMedia()` auto-reverts and re-runs the callback — SplitText re-splits, pins are recreated, values recalculated.

Pinned sections MUST have a non-debounced resize listener that updates pin-spacer and section `width`/`maxWidth` to `innerWidth + 'px'`.

Fluid resize principle: CSS fluid units (`clamp()`, `vw`, `%`) for real-time layout. GSAP function-based values + `invalidateOnRefresh: true` for animation values recalculated on auto-refresh.

## Build

```bash
npm run dev    # localhost:4321
npm run build  # zero errors required
```
