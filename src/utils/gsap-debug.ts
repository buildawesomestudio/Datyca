import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

interface GSAPDebugInfo {
  scrollTriggers: {
    id: string | undefined;
    trigger: string;
    start: number;
    end: number;
    isActive: boolean;
  }[];
  tweens: number;
  timelines: number;
}

/**
 * Debug utility to inspect GSAP state
 * Call from browser console: debugGSAP()
 */
export function debugGSAP(): GSAPDebugInfo {
  const triggers = ScrollTrigger.getAll();

  const info: GSAPDebugInfo = {
    scrollTriggers: triggers.map((st) => ({
      id: st.vars.id,
      trigger: st.trigger?.tagName || 'unknown',
      start: st.start,
      end: st.end,
      isActive: st.isActive,
    })),
    tweens: gsap.globalTimeline.getChildren(false, true, false).length,
    timelines: gsap.globalTimeline.getChildren(false, false, true).length,
  };

  console.group('🎬 GSAP Debug Info');
  console.log(`ScrollTriggers: ${info.scrollTriggers.length}`);
  console.table(info.scrollTriggers);
  console.log(`Active Tweens: ${info.tweens}`);
  console.log(`Active Timelines: ${info.timelines}`);
  console.groupEnd();

  return info;
}

/**
 * Find ScrollTrigger conflicts (multiple triggers on same element)
 */
export function findConflicts(): void {
  const triggers = ScrollTrigger.getAll();
  const triggerMap = new Map<Element, string[]>();

  triggers.forEach((st) => {
    if (st.trigger) {
      const existing = triggerMap.get(st.trigger) || [];
      existing.push(st.vars.id || 'unnamed');
      triggerMap.set(st.trigger, existing);
    }
  });

  console.group('🔍 ScrollTrigger Conflicts');
  let hasConflicts = false;

  triggerMap.forEach((ids, element) => {
    if (ids.length > 1) {
      hasConflicts = true;
      console.warn(`Element has ${ids.length} ScrollTriggers:`, element);
      console.log('  IDs:', ids);
    }
  });

  if (!hasConflicts) {
    console.log('✅ No conflicts found');
  }
  console.groupEnd();
}

/**
 * Kill all ScrollTriggers for a specific component
 */
export function killComponent(componentId: string): void {
  const triggers = ScrollTrigger.getAll();
  let killed = 0;

  triggers.forEach((st) => {
    if (st.vars.id?.startsWith(componentId)) {
      st.kill();
      killed++;
    }
  });

  console.log(`🗑️ Killed ${killed} ScrollTriggers for ${componentId}`);
}

/**
 * Refresh all ScrollTriggers (useful after DOM changes)
 */
export function refreshAll(): void {
  ScrollTrigger.refresh();
  console.log('🔄 ScrollTrigger.refresh() called');
}

// Expose to window for console access
if (typeof window !== 'undefined') {
  (window as unknown as Record<string, unknown>).debugGSAP = debugGSAP;
  (window as unknown as Record<string, unknown>).findConflicts = findConflicts;
  (window as unknown as Record<string, unknown>).killComponent = killComponent;
  (window as unknown as Record<string, unknown>).refreshAll = refreshAll;
}
