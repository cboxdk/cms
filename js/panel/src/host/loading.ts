// Loading what a point's contributions need without holding up the page: the hooks start the
// addon's registration check and the import of a contribution's module, and render again when it
// settles, through the AddonSource as an external store, so a point shows its default, or nothing,
// until then and never suspends the page.

import { useEffect, useSyncExternalStore } from 'react';

import type { LoadedAddon, LoadedModule } from './addons';
import type { Fill } from './model';
import { useHostRuntime } from './runtime';

/** What the host has of a fill's module, or why it has none. */
export type FillModule = LoadedModule | { readonly status: 'refused'; readonly addon: LoadedAddon };

/** Renders again whenever the runtime's source settles something. */
function useSourceVersion(): number {
  const { source } = useHostRuntime();

  return useSyncExternalStore(source.subscribe, source.version, source.version);
}

/** The addons of the fills, each with its code once checked, or undefined while the check runs. */
export function useLoadedAddons(
  fills: readonly Fill[],
): ReadonlyMap<string, LoadedAddon | undefined> {
  const runtime = useHostRuntime();
  useSourceVersion();
  const addons = [...new Set(fills.map((fill) => fill.addon))].sort();
  const key = addons.join('\n');

  useEffect(() => {
    for (const addon of key === '' ? [] : key.split('\n')) {
      void runtime.source.addon(runtime.contributions, addon);
    }
  }, [key, runtime]);

  return new Map(
    addons.map((addon) => [addon, runtime.source.settled(runtime.contributions, addon)]),
  );
}

/**
 * The default export of each fill's module, by contribution id: imported once the fill's addon
 * passed its check, and refused when it did not.
 */
export function useLoadedModules(fills: readonly Fill[]): ReadonlyMap<string, FillModule> {
  const runtime = useHostRuntime();
  const addons = useLoadedAddons(fills);
  const wanted = fills
    .filter((fill) => addons.get(fill.addon)?.status === 'registered')
    .map((fill) => `${fill.addon} ${fill.id}`)
    .join('\n');

  useEffect(() => {
    for (const line of wanted === '' ? [] : wanted.split('\n')) {
      const [addon = '', id = ''] = line.split(' ');
      const loaded = runtime.source.settled(runtime.contributions, addon);

      if (loaded?.status === 'registered') {
        runtime.source.module(loaded.registration, id).catch(() => undefined);
      }
    }
  }, [wanted, runtime]);

  return new Map(
    fills.map((fill): [string, FillModule] => {
      const addon = addons.get(fill.addon);

      if (addon === undefined) {
        return [fill.id, { status: 'loading' }];
      }

      return addon.status === 'registered'
        ? [fill.id, runtime.source.moduleState(fill.id)]
        : [fill.id, { status: 'refused', addon }];
    }),
  );
}
