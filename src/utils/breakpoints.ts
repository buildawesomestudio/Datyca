// Centralized breakpoints for gsap.matchMedia() and JS layout checks.
// Must stay aligned with Tailwind's default md (768) and lg (1024).

export const BP = {
  tablet: 768,
  desktop: 1024,
} as const;

export const MQ = {
  tabletUp: `(min-width: ${BP.tablet}px)`,
  desktopUp: `(min-width: ${BP.desktop}px)`,
  belowTablet: `(max-width: ${BP.tablet - 1}px)`,
  belowDesktop: `(max-width: ${BP.desktop - 1}px)`,
} as const;
