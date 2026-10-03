// The registration of an addon's UI (section 3.1 of the panel extension architecture): the default
// export of the bundle's entry module. It maps each contribution of the addon's manifest that runs
// code, by its id, to what the addon gives for it.
//
//     import { definePanelAddon } from '@cboxdk/cms-panel/extend';
//     import type { Contributions } from '../resources/panel/generated/contributions';
//
//     export default definePanelAddon<Contributions>({
//       'approvals.badge': () => import('./ApprovalsBadge'),
//       'approvals.reason': reasonCheck,
//     });
//
// Contributions comes from `cms:panel:types <namespace>`, which writes it from the manifest and the
// points' schemas, so tsc in the addon's repository fails on a missing key, an extra key and a
// component or function with other props. At run time the host compares the keys with what
// cms:build compiled for the addon, and renders none of the addon's contributions on a mismatch.

import type { ContributionImplementation } from './contributions';
import { PANEL_API_VERSION, type PanelApiVersion } from './version';

/**
 * The contributions of an addon, by contribution id, as `cms:panel:types` writes them as the
 * interface Contributions: C with a function for each of its keys.
 *
 * @stable
 */
export type ContributionMap<C> = { readonly [K in keyof C]: ContributionImplementation };

/**
 * An addon's registration, the default export of its bundle's entry module: its contributions, the
 * ids sorted, which the host compares with the registry, and the version of the panel's API the
 * SDK it was built with has.
 *
 * @stable
 */
export interface PanelAddon<C extends ContributionMap<C>> {
  readonly contributions: C;
  readonly ids: readonly string[];
  readonly sdk: PanelApiVersion;
}

/**
 * The form of a contribution's id, as Cbox\Cms\Contracts\PanelPoints\ContributionId::PATTERN has
 * it: the addon's namespace and one or more dotted names; a test holds the two equal.
 */
export const CONTRIBUTION_ID =
  /^(?<namespace>[a-z][a-z0-9]{0,19})(?:\.[a-z][a-z0-9_]*(?:-[a-z0-9_]+)*)+$/;

/** The longest id of a contribution, ContributionId::MAX_LENGTH. */
export const CONTRIBUTION_ID_MAX_LENGTH = 96;

/**
 * Thrown by definePanelAddon() for a registration that cannot be the addon's, such as an id that
 * is not `<namespace>.<name>` or a value that is not a function; tsc refuses both already when
 * Contributions is generated.
 *
 * @stable
 */
export class InvalidPanelAddon extends Error {
  public constructor(message: string) {
    super(message);
    this.name = 'InvalidPanelAddon';
  }
}

/**
 * Registers an addon's contributions: exactly the keys of C, each with what its kind needs.
 *
 * @stable
 */
export function definePanelAddon<C extends ContributionMap<C>>(contributions: C): PanelAddon<C> {
  // Read as a record of unknown values: at run time a registration can hold anything.
  const values: Readonly<Record<string, unknown>> = contributions;
  const ids = Object.keys(values).sort();
  const namespaces = new Set<string>();

  for (const id of ids) {
    if (id.length > CONTRIBUTION_ID_MAX_LENGTH || !CONTRIBUTION_ID.test(id)) {
      throw new InvalidPanelAddon(
        `The contribution id "${id}" is not the addon's namespace, a dot and a name of at most ${String(CONTRIBUTION_ID_MAX_LENGTH)} characters, such as "approvals.badge".`,
      );
    }

    if (typeof values[id] !== 'function') {
      throw new InvalidPanelAddon(
        `The contribution "${id}" is not a function: give a component as () => import('./Module'), and a check, decorator or observer as the function itself.`,
      );
    }

    namespaces.add(id.slice(0, id.indexOf('.')));
  }

  if (namespaces.size > 1) {
    throw new InvalidPanelAddon(
      `The contributions name the namespaces ${[...namespaces].sort().join(', ')}; an addon registers only its own.`,
    );
  }

  return Object.freeze({
    contributions: Object.freeze({ ...contributions }),
    ids: Object.freeze(ids),
    sdk: PANEL_API_VERSION,
  });
}
