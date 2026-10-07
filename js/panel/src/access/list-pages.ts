// What the access list pages share (PRD 5.10, 13.4): their lists are read a keyset page at a
// time, after the id of the last row shown, which the page's address carries as `?after=<id>`,
// so the address is the state (cbox-ui PATTERNS: URL is state) and a page can be opened again.
// Next visits the address of the next page; Previous goes back through the pages visited in this
// session, which the component remembers while Inertia preserves its state, and is disabled on a
// page opened afresh, where the first page is one address away.

import { router } from '@inertiajs/react';

/** The query parameter of the id a list page starts after. */
export const AFTER = 'after';

/** The id the page at the address starts after, or null for the first page. */
export function afterOf(url: string): string | null {
  const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
  const after = new URLSearchParams(query).get(AFTER);

  return after === null || after === '' ? null : after;
}

/** The address of the page that starts after the id, or of the first page for null. */
export function pageUrl(url: string, after: string | null): string {
  const path = url.includes('?') ? url.slice(0, url.indexOf('?')) : url;

  return after === null ? path : `${path}?${AFTER}=${encodeURIComponent(after)}`;
}

/** What a visit of another page of the list tells the component. */
export interface ListVisit {
  readonly onStart: () => void;
  readonly onFinish: () => void;
}

/** Visits the page of the list that starts after the id, keeping the component's state. */
export function visitPage(url: string, after: string | null, visit: ListVisit): void {
  router.visit(pageUrl(url, after), {
    preserveState: true,
    preserveScroll: true,
    onStart: visit.onStart,
    onFinish: visit.onFinish,
  });
}

/**
 * The pages visited before the one shown, as ids started after, newest last; moving on pushes the
 * page shown, going back pops.
 */
export type Cursors = readonly (string | null)[];

export function forward(cursors: Cursors, shown: string | null): Cursors {
  return [...cursors, shown];
}

export function back(cursors: Cursors): {
  readonly cursors: Cursors;
  readonly after: string | null;
} {
  const previous = cursors.at(-1) ?? null;

  return { cursors: cursors.slice(0, -1), after: previous };
}
