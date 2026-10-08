// What the panel's host renders a page's points from (PRD 13.4): the prop cms.contributions,
// contributions.v1.json, which the server resolved for the viewer and the request. Its types are
// generated from the schema; this module names them for the host and finds a point in them.

import type {
  AddonPropV1,
  AddonTextsPropV1,
  ContributionsV1,
  FillPropV1,
  PointFillsPropV1,
} from '../generated/pages/ContributionsV1';

/** The prop cms.contributions of a page. */
export type Contributions = ContributionsV1;

/** A point the page renders, with its active contributions. */
export type ActivePoint = PointFillsPropV1;

/** One active contribution, as the server sent it. */
export type Fill = FillPropV1;

/** An addon whose code runs on the page, with the registration its code must match. */
export type AddonEntry = AddonPropV1;

/** The texts of one addon in the page's locale, as cms:build compiled its catalogue. */
export type AddonCatalogue = AddonTextsPropV1;

/** The kinds of point, as the server names them. */
export type PointKind = PointFillsPropV1['kind'];

/** The contributions of a page that has none: what the host renders before the server sent any. */
export const NO_CONTRIBUTIONS: Contributions = Object.freeze({
  addons: [],
  commands: '',
  details: false,
  pages: [],
  points: [],
  texts: [],
  viewer: null,
});

/** The point with the id, `<name>@<version>`, or undefined when no contribution is active on it. */
export function pointOf(contributions: Contributions, point: string): ActivePoint | undefined {
  return contributions.points.find((candidate) => candidate.point === point);
}

/** The entry of an addon, or undefined when none of its code runs on the page. */
export function addonOf(contributions: Contributions, addon: string): AddonEntry | undefined {
  return contributions.addons.find((candidate) => candidate.addon === addon);
}

/**
 * The texts the page carries for the addons, by namespace and then by key: the catalogue of the
 * page's locale that cms:build compiled for each addon with an active contribution (section 2.6 of
 * the panel extension architecture). An addon the page carries no catalogue for has none, and every
 * key it names shows as the key.
 */
export function catalogues(
  contributions: Contributions,
): ReadonlyMap<string, ReadonlyMap<string, string>> {
  return new Map(
    contributions.texts.map((catalogue) => [
      catalogue.addon,
      new Map(catalogue.entries.map((entry) => [entry.key, entry.text])),
    ]),
  );
}

/**
 * The order the host renders contributions in, the same as cms:build's and the hooks': priority
 * with the lowest first, then the addon's namespace, then the contribution's id, each compared by
 * code unit, as PHP compares strings. The host applies it itself, so the order holds whatever
 * order a document lists them in.
 */
export function renderOrder<F extends Pick<Fill, 'priority' | 'addon' | 'id'>>(
  fills: readonly F[],
): F[] {
  return [...fills].sort(
    (a, b) => a.priority - b.priority || compareText(a.addon, b.addon) || compareText(a.id, b.id),
  );
}

function compareText(a: string, b: string): number {
  if (a === b) {
    return 0;
  }

  return a < b ? -1 : 1;
}

/** A nav entry as the shell shows it: an entry of the navigation that opens a page of its addon. */
export interface NavEntry {
  readonly id: string;
  readonly addon: string;
  /** The entry's text, in the panel's locale. */
  readonly label: string;
  readonly icon: string | null;
  /** The id of the page it opens. */
  readonly page: string;
  /** The page's address on the panel's origin. */
  readonly url: string;
  readonly priority: number;
}

/**
 * The nav entries of a nav point, in render order, each with its text from its addon's catalogue
 * and the address of the page it opens, which `pages` gives; an entry whose page the server did
 * not list is left out, because there is nothing to open.
 */
export function navEntries(
  contributions: Contributions,
  point: string,
  text: (addon: string, key: string) => string,
): NavEntry[] {
  const active = pointOf(contributions, point);

  if (active === undefined || active.kind !== 'nav') {
    return [];
  }

  const entries: NavEntry[] = [];

  for (const fill of renderOrder(active.fills)) {
    const url = contributions.pages.find((page) => page.page === fill.nav?.page)?.url;

    if (fill.nav === null || url === undefined) {
      continue;
    }

    entries.push({
      id: fill.id,
      addon: fill.addon,
      label: text(fill.addon, fill.nav.label),
      icon: fill.nav.icon,
      page: fill.nav.page,
      url,
      priority: fill.priority,
    });
  }

  return entries;
}

/** An entry of the command palette that opens a page: a nav entry, which names the page. */
export interface PaletteEntry {
  readonly id: string;
  readonly kind: 'page';
  /** The entry's text, in the panel's locale. */
  readonly label: string;
  /** The page it opens, by id. */
  readonly page: string;
  readonly url: string;
}

/**
 * The entries of the command palette that open pages (section 3.4 of the panel extension
 * architecture): the nav entries of the nav point, in their order, each opening its page, so the
 * palette offers every page the viewer may open, as the server filtered them.
 */
export function paletteEntries(
  contributions: Contributions,
  point: string,
  text: (addon: string, key: string) => string,
): PaletteEntry[] {
  return navEntries(contributions, point, text).map((entry) => ({
    id: entry.id,
    kind: 'page',
    label: entry.label,
    page: entry.page,
    url: entry.url,
  }));
}
