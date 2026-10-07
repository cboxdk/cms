// checkParity() (section 3.7 of the panel extension architecture): a form check of severity error
// blocks the submit only when it mirrors a hook of its addon on the same command, and the hook is
// the rule; the check is a courtesy to the viewer. The two must agree, so a test runs the check
// and the hook's verdict on the same documents and fails when they disagree. The hook runs in
// PHP, so its verdicts come from the addon's own PHP tests, recorded as JSON: the paths the hook
// refuses in each document, or none when it accepts.

import type { CheckContext, FormCheck } from '../contributions';
import { detail, frozenCopy } from './render';

/**
 * One document the check and the hook disagree on.
 *
 * @stable
 */
export interface ParityDisagreement {
  /** The document's index in the fixtures. */
  readonly index: number;
  /** The paths the check blocks: its issues of severity error. */
  readonly check: readonly string[];
  /** The paths the hook refuses. */
  readonly hook: readonly string[];
}

/**
 * Thrown by checkParity() when the check and the hook disagree, naming each document they
 * disagree on with both verdicts.
 *
 * @stable
 */
export class ParityBroken extends Error {
  public constructor(public readonly disagreements: readonly ParityDisagreement[]) {
    super(
      [
        'The form check and the hook it mirrors disagree:',
        ...disagreements.map(
          (disagreement) =>
            `document ${String(disagreement.index)}: the check blocks [${disagreement.check.join(', ')}], the hook refuses [${disagreement.hook.join(', ')}]`,
        ),
      ].join('\n'),
    );
    this.name = 'ParityBroken';
  }
}

/**
 * What checkParity() takes besides the check and the hook.
 *
 * @stable
 */
export interface ParityOptions {
  readonly locale?: string;
  /** The viewer the check's context names on every document; none unless given. */
  readonly viewer?: string | null;
}

/**
 * Runs the check and the hook's verdict on each document and throws ParityBroken when, on any of
 * them, the paths the check blocks with an error are not the paths the hook refuses. Issues of a
 * lighter severity are the check's own and do not count. Gives the number of documents that agreed.
 *
 * @stable
 */
export async function checkParity<D>(
  check: FormCheck<D>,
  hook: (document: D) => readonly string[] | Promise<readonly string[]>,
  documents: readonly D[],
  options: ParityOptions = {},
): Promise<number> {
  const context: CheckContext = { locale: options.locale ?? 'en', viewer: options.viewer ?? null };
  const disagreements: ParityDisagreement[] = [];

  for (const [index, document] of documents.entries()) {
    const frozen = frozenCopy(document);
    let issues;

    try {
      issues = check(frozen, context);
    } catch (failure) {
      throw new ParityBroken([
        { index, check: [`the check threw: ${detail(failure)}`], hook: await hook(frozen) },
      ]);
    }

    const blocked = unique(
      issues.filter((issue) => issue.severity === 'error').map((issue) => issue.path),
    );
    const refused = unique(await hook(frozen));

    if (blocked.join('\n') !== refused.join('\n')) {
      disagreements.push({ index, check: blocked, hook: refused });
    }
  }

  if (disagreements.length > 0) {
    throw new ParityBroken(disagreements);
  }

  return documents.length;
}

function unique(paths: readonly string[]): readonly string[] {
  return [...new Set(paths)].sort();
}
