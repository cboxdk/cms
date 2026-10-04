// expectRegistration() (section 3.1 of the panel extension architecture): the host renders none of
// an addon's contributions when the ids its bundle registers are not the ids cms:build compiled
// from its manifest, so a test holds the registration to the manifest's ids before the panel does.

import type { ContributionMap, PanelAddon } from '../addon';

/**
 * Thrown by expectRegistration() with the ids the registration lacks and the ids the manifest
 * does not declare.
 *
 * @stable
 */
export class RegistrationMismatch extends Error {
  public constructor(
    public readonly missing: readonly string[],
    public readonly extra: readonly string[],
  ) {
    super(
      [
        "The registration is not the manifest's contributions that run code, so the panel would render none of them:",
        ...(missing.length === 0 ? [] : [`registered by no key: ${missing.join(', ')}`]),
        ...(extra.length === 0 ? [] : [`not in the manifest: ${extra.join(', ')}`]),
      ].join('\n'),
    );
    this.name = 'RegistrationMismatch';
  }
}

/**
 * Holds a registration to the ids of the manifest's contributions that run code, as cms:build
 * compiled them: exactly those, each once. Throws RegistrationMismatch otherwise.
 *
 * @stable
 */
export function expectRegistration<C extends ContributionMap<C>>(
  addon: PanelAddon<C>,
  ids: readonly string[],
): void {
  const expected = [...new Set(ids)].sort();
  const registered = new Set(addon.ids);
  const missing = expected.filter((id) => !registered.has(id));
  const extra = addon.ids.filter((id) => !expected.includes(id));

  if (missing.length > 0 || extra.length > 0) {
    throw new RegistrationMismatch(missing, extra);
  }
}
