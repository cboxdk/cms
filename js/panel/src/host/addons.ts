// Where the host gets an addon's code from (PRD 13.4): the panel's own registration of the core's
// contributions, in the namespace cms, which is part of the panel's build, and the entry module of
// each addon's bundle, which the loader imports. The host checks each registration once
// (registration.ts) before it renders any contribution of the addon, and imports the module of a
// component when a page first needs it, so a page loads an addon's code only for what it shows.

import type { ContributionImplementation } from '@cboxdk/cms-panel/extend';

import type { AddonEntry, Contributions } from './model';
import { addonOf } from './model';
import { checkRegistration, type AddonRegistration } from './registration';
import type { HostReporter } from './reports';

/** Imports the entry module of an addon's bundle by its namespace. */
export type AddonLoader = (addon: string) => Promise<unknown>;

/** What the host has of an addon's code. */
export type LoadedAddon =
  | { readonly status: 'registered'; readonly registration: AddonRegistration }
  | { readonly status: 'mismatch' }
  | { readonly status: 'unavailable' };

/** What the host has of one contribution's module. */
export type LoadedModule =
  | { readonly status: 'loading' }
  | { readonly status: 'loaded'; readonly value: unknown }
  | { readonly status: 'failed'; readonly failure: unknown };

/** The namespace of the core's own contributions. */
export const CORE_NAMESPACE = 'cms';

/**
 * The code of the addons on a page: each addon's registration, checked once and kept while the
 * panel runs, and each component module, imported once.
 */
export class AddonSource {
  readonly #load: AddonLoader;
  readonly #inProcess: Readonly<Record<string, unknown>>;
  readonly #report: HostReporter;
  readonly #addons = new Map<string, Promise<LoadedAddon>>();
  readonly #modules = new Map<string, Promise<unknown>>();
  readonly #settled = new Map<string, LoadedAddon>();
  readonly #moduleStates = new Map<string, LoadedModule>();
  readonly #listeners = new Set<() => void>();
  #version = 0;

  /**
   * @param load imports an addon's entry module
   * @param inProcess the registrations that are part of the panel's build, by namespace: the core's
   * @param report where a mismatch or an addon that cannot be loaded is reported
   */
  public constructor(
    load: AddonLoader,
    inProcess: Readonly<Record<string, unknown>>,
    report: HostReporter,
  ) {
    this.#load = load;
    this.#inProcess = inProcess;
    this.#report = report;
  }

  /**
   * The addon's code as the page's contributions describe it: its registration when it matches
   * what cms:build compiled, else why not. The answer for an addon is the same for every page of
   * the session, and its mismatch is reported once.
   */
  public addon(contributions: Contributions, addon: string): Promise<LoadedAddon> {
    const entry = addonOf(contributions, addon);
    const key = entry === undefined ? `${addon}\n` : `${addon}\n${entry.registration}`;
    const known = this.#addons.get(key);

    if (known !== undefined) {
      return known;
    }

    const loaded = this.#check(entry, addon).then((result) => {
      this.#settled.set(key, result);
      this.#changed();

      return result;
    });
    this.#addons.set(key, loaded);

    return loaded;
  }

  /** The addon's code when its check has finished, without waiting; undefined while it runs. */
  public settled(contributions: Contributions, addon: string): LoadedAddon | undefined {
    const entry = addonOf(contributions, addon);

    return this.#settled.get(
      entry === undefined ? `${addon}\n` : `${addon}\n${entry.registration}`,
    );
  }

  /**
   * What the addon registered for the contribution: a function, such as a check or a decorator,
   * or the import of a component's module.
   */
  public implementation(
    registration: AddonRegistration,
    contribution: string,
  ): ContributionImplementation | undefined {
    return Object.hasOwn(registration.contributions, contribution)
      ? registration.contributions[contribution]
      : undefined;
  }

  /**
   * The default export of the module a contribution's import gives, imported once; the promise
   * rejects when the import fails or the module has no default export.
   */
  public module(registration: AddonRegistration, contribution: string): Promise<unknown> {
    const known = this.#modules.get(contribution);

    if (known !== undefined) {
      return known;
    }

    const implementation = this.implementation(registration, contribution);
    const imported = (async () => {
      if (implementation === undefined) {
        throw new Error(`The addon registered nothing for ${contribution}.`);
      }

      const module: unknown = await implementation();

      if (typeof module !== 'object' || module === null || !('default' in module)) {
        throw new Error(`The module of ${contribution} has no default export.`);
      }

      return module.default;
    })();
    this.#modules.set(contribution, imported);
    this.#moduleStates.set(contribution, { status: 'loading' });
    imported.then(
      (value) => {
        this.#moduleStates.set(contribution, { status: 'loaded', value });
        this.#changed();
      },
      (failure: unknown) => {
        this.#moduleStates.set(contribution, { status: 'failed', failure });
        this.#changed();
      },
    );

    return imported;
  }

  /** What the host has of a contribution's module, without importing it. */
  public moduleState(contribution: string): LoadedModule {
    return this.#moduleStates.get(contribution) ?? { status: 'loading' };
  }

  /** Calls the listener whenever an addon's check or a module's import settles; gives back the function that stops it. */
  public readonly subscribe = (listener: () => void): (() => void) => {
    this.#listeners.add(listener);

    return () => {
      this.#listeners.delete(listener);
    };
  };

  /** A number that changes whenever an addon's check or a module's import settles. */
  public readonly version = (): number => this.#version;

  #changed(): void {
    this.#version += 1;

    for (const listener of this.#listeners) {
      listener();
    }
  }

  async #check(entry: AddonEntry | undefined, addon: string): Promise<LoadedAddon> {
    if (entry === undefined) {
      return { status: 'unavailable' };
    }

    let module: unknown;

    try {
      module = Object.hasOwn(this.#inProcess, addon)
        ? this.#inProcess[addon]
        : await this.#load(addon);
    } catch {
      this.#report({ code: 'panel_addon_unavailable', addon });

      return { status: 'unavailable' };
    }

    const checked = await checkRegistration(entry, module);

    if (checked.status === 'mismatch') {
      this.#report({ code: 'panel_addon_mismatch', addon });

      return { status: 'mismatch' };
    }

    return checked;
  }
}
