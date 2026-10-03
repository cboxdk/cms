// Decorator composition (section 3.5 of the panel extension architecture): a decorator is a
// function of its target's props that never receives the target's default, so it cannot drop it;
// the host renders the default once, with what the decorators add before and after it, their
// badges, and the props they tighten. Tightening only tightens, combined most restrictively across
// the decorators: a disabled reason can only disable, a description is only appended, and a tone
// only moves towards warning or danger. A decorator tightens only what its manifest declares; the
// host passes over anything else, and reports it.

import type { BadgeDescriptor, Decoration, Tightening } from '@cboxdk/cms-panel/extend';
import type { ReactNode } from 'react';

import type { HostReport } from './reports';

/** A tone of a decorated default. */
export type DefaultTone = 'neutral' | 'info' | 'warning' | 'danger';

const TONE_RANK: Readonly<Record<DefaultTone, number>> = {
  neutral: 0,
  info: 0,
  warning: 1,
  danger: 2,
};

/** What a reason or a description says, and which addon said it. */
export interface Attributed {
  readonly addon: string;
  readonly text: string;
}

/** The props of a default after every decorator has tightened them. */
export interface Tightened {
  /** Whether a decorator disabled the default. */
  readonly disabled: boolean;
  /** Why, in the decorators' order, each with its addon. */
  readonly disabledReasons: readonly Attributed[];
  /** The descriptions the decorators appended, in their order. */
  readonly descriptions: readonly Attributed[];
  /** The default's tone, moved towards danger by the decorators. */
  readonly tone: DefaultTone;
}

/** A badge a decorator put on the default, with its addon. */
export interface AttributedBadge extends BadgeDescriptor {
  readonly addon: string;
  readonly contribution: string;
}

/** What one decorator gave, with whose it is. */
export interface AppliedDecoration {
  readonly addon: string;
  readonly contribution: string;
  /** The tightenings its manifest declares. */
  readonly tightens: readonly (keyof Tightening)[];
  readonly decoration: Decoration<keyof Tightening>;
}

/** Content a decorator renders before or after the default, with whose it is. */
export interface DecoratorContent {
  readonly addon: string;
  readonly contribution: string;
  readonly node: ReactNode;
}

/** The decorators combined: the tightened props, the content around the default and the badges. */
export interface Composition {
  readonly tightened: Tightened;
  /** Before the default, the first decorator's outermost. */
  readonly before: readonly DecoratorContent[];
  /** After the default, the first decorator's outermost, so last. */
  readonly after: readonly DecoratorContent[];
  readonly badges: readonly AttributedBadge[];
}

/** Translates a key of an addon's catalogue. */
export type AddonText = (addon: string, key: string) => string;

/**
 * Combines the decorations in the decorators' render order onto a default of the tone given.
 */
export function compose(
  tone: DefaultTone,
  decorations: readonly AppliedDecoration[],
  text: AddonText,
  report: (report: Omit<HostReport, 'point'>) => void,
): Composition {
  const disabledReasons: Attributed[] = [];
  const descriptions: Attributed[] = [];
  const before: DecoratorContent[] = [];
  const after: DecoratorContent[] = [];
  const badges: AttributedBadge[] = [];
  let tightenedTone = tone;

  for (const applied of decorations) {
    const { addon, contribution, decoration } = applied;
    const tighten: Readonly<Record<string, unknown>> = decoration.tighten ?? {};

    for (const [key, value] of Object.entries(tighten)) {
      if (value === undefined) {
        continue;
      }

      if (!(applied.tightens as readonly string[]).includes(key)) {
        report({ code: 'panel_decorator_tightening_refused', addon, contribution });

        continue;
      }

      if (key === 'disabled_reason' && typeof value === 'string') {
        disabledReasons.push({ addon, text: text(addon, value) });
      } else if (key === 'description' && typeof value === 'string') {
        descriptions.push({ addon, text: text(addon, value) });
      } else if (key === 'tone_towards_danger' && (value === 'warning' || value === 'danger')) {
        tightenedTone = TONE_RANK[value] > TONE_RANK[tightenedTone] ? value : tightenedTone;
      } else {
        report({ code: 'panel_decorator_tightening_refused', addon, contribution });
      }
    }

    if (decoration.before !== undefined && decoration.before !== null) {
      before.push({ addon, contribution, node: decoration.before });
    }

    if (decoration.after !== undefined && decoration.after !== null) {
      after.unshift({ addon, contribution, node: decoration.after });
    }

    if (decoration.badge !== undefined) {
      badges.push({
        ...decoration.badge,
        label: text(addon, decoration.badge.label),
        addon,
        contribution,
      });
    }
  }

  return {
    tightened: {
      disabled: disabledReasons.length > 0,
      disabledReasons,
      descriptions,
      tone: tightenedTone,
    },
    before,
    after,
    badges,
  };
}
