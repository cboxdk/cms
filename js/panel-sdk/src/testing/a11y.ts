// expectNoA11yViolations() (section 7 of the panel extension architecture): axe on what a
// contribution rendered, with the rules the panel's own pages are held to, WCAG 2.2 at levels A
// and AA with the criteria of 2.0 and 2.1 they keep, so an addon's test fails on what the
// Browser suite of the panel would fail on.

/**
 * The axe tags of WCAG 2.2 at levels A and AA, with the criteria of 2.0 and 2.1 it keeps: what the
 * panel's own pages are held to.
 *
 * @stable
 */
export const WCAG_22_AA: readonly string[] = [
  'wcag2a',
  'wcag2aa',
  'wcag21a',
  'wcag21aa',
  'wcag22a',
  'wcag22aa',
];

/**
 * One violation axe found.
 *
 * @stable
 */
export interface A11yViolation {
  /** The rule's id, such as `button-name`. */
  readonly rule: string;
  readonly impact: string;
  readonly help: string;
  /** The CSS selectors of the elements that break it. */
  readonly targets: readonly string[];
}

/**
 * Thrown by expectNoA11yViolations() with every violation, one line each.
 *
 * @stable
 */
export class A11yViolations extends Error {
  public constructor(public readonly violations: readonly A11yViolation[]) {
    super(
      [
        'The rendered contribution breaks WCAG 2.2 AA:',
        ...violations.map(
          (violation) =>
            `${violation.rule} (${violation.impact}): ${violation.help}: ${violation.targets.join(', ')}`,
        ),
      ].join('\n'),
    );
    this.name = 'A11yViolations';
  }
}

/**
 * What expectNoA11yViolations() takes: the axe tags to run, WCAG_22_AA unless given.
 *
 * @stable
 */
export interface A11yOptions {
  readonly tags?: readonly string[];
}

/** Whether the DOM lays out elements, which jsdom does not. */
function hasLayout(): boolean {
  return typeof navigator === 'undefined' || !navigator.userAgent.includes('jsdom');
}

/**
 * Runs axe on the element and throws A11yViolations when it finds any violation, of any impact,
 * of the rules of the tags.
 *
 * @stable
 */
export async function expectNoA11yViolations(
  element: Element,
  options: A11yOptions = {},
): Promise<void> {
  const axe = await import('axe-core');
  const results = await axe.default.run(element, {
    runOnly: { type: 'tag', values: [...(options.tags ?? WCAG_22_AA)] },
    resultTypes: ['violations'],
    // A DOM without layout, such as jsdom's, cannot compute colours; the panel's Browser and
    // Storybook gates check the contrast of what an addon renders in a real browser.
    rules: hasLayout() ? {} : { 'color-contrast': { enabled: false } },
  });
  const violations: A11yViolation[] = results.violations.map((violation) => ({
    rule: violation.id,
    impact: violation.impact ?? 'unknown',
    help: violation.help,
    targets: violation.nodes.map((node) => node.target.map(String).join(' ')),
  }));

  if (violations.length > 0) {
    throw new A11yViolations(violations);
  }
}
