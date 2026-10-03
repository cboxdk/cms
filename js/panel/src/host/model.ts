// What the panel's host renders a page's points from (PRD 13.4): the prop cms.contributions,
// contributions.v1.json, which the server resolved for the viewer and the request. Its types are
// generated from the schema; this module names them for the host and finds a point in them.

import type {
  AddonPropV1,
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

/** The kinds of point, as the server names them. */
export type PointKind = PointFillsPropV1['kind'];

/** The contributions of a page that has none: what the host renders before the server sent any. */
export const NO_CONTRIBUTIONS: Contributions = Object.freeze({
  addons: [],
  commands: '',
  details: false,
  pages: [],
  points: [],
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
