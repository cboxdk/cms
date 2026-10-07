// How the access pages show a refusal (GUARDRAILS 8, decision of 3 October 2026: no dead ends): a
// problem details document names its catalog code, and the panel's catalogue has, for the codes
// the roles and grants commands refuse with, what the code means and what to do next in the
// reader's language, `panel.problem.<code>`; a code the catalogue has no text for gets the page's
// own explanation. A field error of a rejected command is shown at the field it is about too.

import type { ProblemV1 } from '../generated/protocol/ProblemV1';
import { lookup, type Locale, type Translate, type TranslationKey } from '../i18n/translations';

/** The key of the panel's text for a catalog code, when it has one. */
export function problemKey(code: string): string {
  return `panel.problem.${code}`;
}

/**
 * What the problem's code means and what to do, in the panel's locale: the catalogue's text for
 * the code, or the fallback of the page.
 */
export function explanationOf(
  locale: Locale,
  t: Translate,
  problem: ProblemV1,
  fallback: TranslationKey,
): string {
  return lookup(locale, problemKey(problem.code)) ?? t(fallback);
}

/**
 * The text of the first error of a rejected command at the field, or undefined when the problem
 * has none there: the kernel names the field by its path in the command's document, such as
 * `handle`, `permissions[1]` or `locales[0]`, and the surface may put the document's own name in
 * front of it, such as `command.handle`.
 */
export function fieldErrorOf(problem: ProblemV1 | null, field: string): string | undefined {
  if (problem === null) {
    return undefined;
  }

  return problem.errors.find((error) => error.field !== null && atField(error.field, field))
    ?.detail;
}

function atField(path: string, field: string): boolean {
  const own = path.startsWith('command.') ? path.slice('command.'.length) : path;

  return own === field || own.startsWith(`${field}.`) || own.startsWith(`${field}[`);
}
