// The runtime registration check (section 3.1 of the panel extension architecture): an addon's
// code registers its contributions with definePanelAddon(), and the host holds that registration
// to what cms:build compiled for the addon before it renders any of the addon's contributions.
// The server sends, per addon, the SHA-256 of the ids of every contribution of the addon that runs
// code, sorted and joined by line feeds; the host takes the same digest of the registered ids. A
// registration that is no definePanelAddon() result, was built with an SDK of another major
// version of the panel's API or a newer minor, or registers other ids, renders none of the
// addon's contributions and is reported as panel_addon_mismatch.

import {
  PANEL_API_VERSION,
  type ContributionImplementation,
  type PanelApiVersion,
} from '@cboxdk/cms-panel/extend';

import type { AddonEntry } from './model';

/** What an addon registers, as the host reads it: what definePanelAddon() gave its entry. */
export interface AddonRegistration {
  readonly contributions: Readonly<Record<string, ContributionImplementation>>;
  readonly ids: readonly string[];
  readonly sdk: PanelApiVersion;
}

/** The outcome of the check: the registration the host renders the addon's contributions from, or why none. */
export type RegistrationCheck =
  | { readonly status: 'registered'; readonly registration: AddonRegistration }
  | { readonly status: 'mismatch' };

/** The digest of the ids, as the server takes it (Cbox\Cms\Panel\Contributions\Domain\Registrations). */
export async function registrationDigest(ids: readonly string[]): Promise<string> {
  const sorted = [...ids].sort((a, b) => (a === b ? 0 : a < b ? -1 : 1));
  const bytes = new TextEncoder().encode(sorted.join('\n'));
  const digest = await crypto.subtle.digest('SHA-256', bytes);

  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** The registration a module exports as its default, or undefined when it exports none. */
export function registrationOf(module: unknown): AddonRegistration | undefined {
  const exported = isObject(module) && 'default' in module ? module.default : module;

  if (!isObject(exported) || !isObject(exported.contributions) || !isObject(exported.sdk)) {
    return undefined;
  }

  const { contributions, ids, sdk } = exported;

  if (
    !Array.isArray(ids) ||
    !ids.every((id): id is string => typeof id === 'string') ||
    typeof sdk.major !== 'number' ||
    typeof sdk.minor !== 'number'
  ) {
    return undefined;
  }

  const keys = Object.keys(contributions).sort();

  if (
    keys.length !== ids.length ||
    keys.some((key, index) => key !== ids[index] || typeof contributions[key] !== 'function')
  ) {
    return undefined;
  }

  return {
    // Every value was checked to be a function above.
    contributions: contributions as Readonly<Record<string, ContributionImplementation>>,
    ids,
    sdk: { major: sdk.major, minor: sdk.minor },
  };
}

/**
 * Holds what a module exports to the addon's entry: a registration of exactly the ids cms:build
 * compiled for it, made with an SDK of the panel's API that this panel serves.
 */
export async function checkRegistration(
  addon: AddonEntry,
  module: unknown,
): Promise<RegistrationCheck> {
  const registration = registrationOf(module);

  if (
    registration === undefined ||
    registration.sdk.major !== PANEL_API_VERSION.major ||
    registration.sdk.minor > PANEL_API_VERSION.minor ||
    (await registrationDigest(registration.ids)) !== addon.registration
  ) {
    return { status: 'mismatch' };
  }

  return { status: 'registered', registration };
}

function isObject(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null;
}
