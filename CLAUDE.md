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

**CRITICAL — clear GSAP transform cache between callbacks**: matchMedia revert kills tweens but does NOT clean GSAP's internal transform cache (`xPercent`, `yPercent`, `x`, `y`, `rotation`, `scale` stored on `el._gsap`). The next `gsap.set()` in the new callback reads the stale cache and re-applies leftover values. **Symptom**: an element animated in one breakpoint shows up at a wrong position in the other breakpoint, even though no inline `style` is visible. **Fix**: at the start of each matchMedia callback that shares elements with another callback, do `gsap.set([...elements], { clearProps: 'all' })` BEFORE any other gsap.set / tween. See `.claude/rules/cookbook.md` → "matchMedia cache cleanup" for the full pattern.

## Use clientWidth, not innerWidth

For layout calculations (function-based GSAP values, inline width on dynamic elements, breakpoint checks inside JS), always use `document.documentElement.clientWidth` and `clientHeight`. **Never** `window.innerWidth`/`innerHeight`.

`innerWidth` is unreliable in several real-world scenarios — Chrome DevTools responsive mode after rapid resize, Safari fullscreen + sidebar transitions — where it reports values that don't match the actual layout viewport. `clientWidth` is the layout viewport the browser uses for CSS media queries and rendering, so JS calculations stay consistent with CSS.

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

**No centralized rebuild. Components are autonomous via `gsap.matchMedia()`.** Layout.astro has no resize handler — it sets `ScrollTrigger.config({ ignoreMobileResize: true })` once and lets ScrollTrigger's built-in auto-refresh handle everything.

- **Within a breakpoint** → ScrollTrigger's built-in auto-refresh (debounced ~200ms) handles fluid resize. Use **function-based values** for anything viewport-dependent (`top: () => getHeaderH()`, `end: () => '+=' + clientWidth`, `x: () => clientWidth * 0.3`) + `invalidateOnRefresh: true` on the ScrollTrigger. Do NOT write manual `window.addEventListener('resize', ...)` that touches pin-spacers or calls `invalidate()` — that duplicates ScrollTrigger's work and races with its refresh cycle.
- **At breakpoint crossing** → `gsap.matchMedia()` auto-reverts and re-runs the component setup callback. **MUST** clear GSAP's transform cache at the start of each callback (see "gsap.matchMedia()" rule above) — otherwise leftover yPercent/xPercent from the previous callback bleeds into the new one.
- **Pinned ScrollTriggers across breakpoints** → assign `refreshPriority` (decreasing top-down by DOM order) on every pinned trigger. When matchMedia adds/removes pins dynamically, new triggers go to the END of the global array, breaking refresh cascade order. `refreshPriority` forces correct order regardless of creation time.
- **iOS address-bar vertical resizes** → filtered globally by `ScrollTrigger.config({ ignoreMobileResize: true })` in Layout.astro. No custom width-only guard needed.

Fluid resize principle: CSS fluid units (`clamp()`, `vw`, `%`) for real-time layout. GSAP function-based values + `invalidateOnRefresh: true` for animation values recalculated on auto-refresh. Always read `document.documentElement.clientWidth/clientHeight`, never `innerWidth/innerHeight` (see "Use clientWidth" rule above).

## Build

```bash
npm run dev    # localhost:4321
npm run build  # zero errors required
```
