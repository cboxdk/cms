// What the tests of the panel's host share: a page's cms.contributions built fill by fill, the
// registrations of addons as definePanelAddon() gives them with the digest the server would send,
// and a render of a node inside the host runtime with services that record what they were asked.

import {
  definePanelAddon,
  type CommandAnswer,
  type ContributionImplementation,
} from '@cboxdk/cms-panel/extend';
import { KitI18nProvider } from '@cboxdk/cms-ui-kit';
import { cleanup, render, type RenderResult } from '@testing-library/react';
import userEvent, { type UserEvent } from '@testing-library/user-event';
import { createHash } from 'node:crypto';
import type { ReactNode } from 'react';
import { afterEach } from 'vitest';

import type { FillPropV1, PointFillsPropV1 } from '../../src/generated/pages/ContributionsV1';
import { TranslationProvider } from '../../src/i18n/translations';
import { AddonSource, type AddonLoader } from '../../src/host/addons';
import type { CommandCall } from '../../src/host/commands';
import type { Contributions } from '../../src/host/model';
import type { HostServices } from '../../src/host/panel-host';
import type { HostReport } from '../../src/host/reports';
import { HostRuntimeProvider } from '../../src/host/runtime';

// React asserts its test environment for act(); Testing Library sets the flag for React 18 and 19.
Reflect.set(globalThis, 'IS_REACT_ACT_ENVIRONMENT', true);

afterEach(() => {
  cleanup();
});

/** A fill of a slot, with what a test gives; every kind's descriptor is null unless given. */
export function fill(
  addon: string,
  id: string,
  priority: number,
  extra: Partial<FillPropV1> = {},
): FillPropV1 {
  return {
    action: null,
    addon,
    check: null,
    data: false,
    decorator: null,
    id,
    kind: 'slot',
    nav: null,
    priority,
    props: { note: 'Weekly desk' },
    replacement: null,
    step: null,
    ...extra,
  };
}

/** A point with its fills, a slot in the sections region unless the test says otherwise. */
export function point(
  id: string,
  fills: readonly FillPropV1[],
  extra: Partial<PointFillsPropV1> = {},
): PointFillsPropV1 {
  return {
    fills,
    kind: 'slot',
    max: null,
    multiplicity: 'many',
    point: id,
    region: 'sections',
    ...extra,
  };
}

/** The digest of the ids, as the server takes it: SHA-256 of the sorted ids joined by line feeds. */
export function serverDigest(ids: readonly string[]): string {
  return createHash('sha256')
    .update([...ids].sort().join('\n'))
    .digest('hex');
}

/**
 * The contributions of a page with the points, and an entry for each addon of the registrations
 * with the digest of the ids it registers, unless `registrations` gives others.
 */
export function contributions(
  points: readonly PointFillsPropV1[],
  addons: Readonly<Record<string, readonly string[]>>,
  extra: Partial<Contributions> = {},
): Contributions {
  return {
    addons: Object.entries(addons)
      .sort(([a], [b]) => (a < b ? -1 : 1))
      .map(([addon, ids]) => ({
        addon,
        any_command: addon === 'cms',
        issues: addon === 'cms' ? [] : [`${addon}.request@1`],
        registration: serverDigest(ids),
      })),
    commands: '/cms/commands',
    details: false,
    pages: [{ page: 'home', url: '/cms' }],
    points,
    texts: [],
    viewer: null,
    ...extra,
  };
}

/** A module whose default export is the value, as an import of it gives. */
export function lazy(value: unknown): () => Promise<{ readonly default: unknown }> {
  return () => Promise.resolve({ default: value });
}

/** An addon's registration of the implementations, as definePanelAddon() gives it. */
export function registration(
  implementations: Readonly<Record<string, ContributionImplementation>>,
): unknown {
  return definePanelAddon(implementations);
}

/** What the services were asked. */
export interface Recorded {
  readonly reports: HostReport[];
  readonly commands: CommandCall[];
  readonly visits: string[];
  readonly notices: string[];
}

/** How a test's services answer: a command's answer by its call, and whether a confirmation is given. */
export interface Answering {
  readonly runCommand?: ((call: CommandCall) => Promise<CommandAnswer>) | undefined;
  readonly confirm?: (() => Promise<boolean>) | undefined;
}

/**
 * Services that record what they are asked, with the catalogue's texts; a command is refused
 * unless the test answers it, and a confirmation is given unless the test says otherwise.
 */
export function services(
  texts: Readonly<Record<string, string>> = {},
  answering: Answering = {},
): {
  readonly services: HostServices;
  readonly recorded: Recorded;
} {
  const recorded: Recorded = { reports: [], commands: [], visits: [], notices: [] };

  return {
    recorded,
    services: {
      locale: 'en',
      text: (_addon, key) => texts[key],
      notify: (_addon, notice) => {
        recorded.notices.push(notice.message);
      },
      visit: (url) => {
        recorded.visits.push(url);
      },
      runCommand: (call) => {
        recorded.commands.push(call);

        return answering.runCommand === undefined
          ? Promise.reject(new Error('No command runs in this test.'))
          : answering.runCommand(call);
      },
      confirm: () =>
        answering.confirm === undefined ? Promise.resolve(true) : answering.confirm(),
      report: (report) => {
        recorded.reports.push(report);
      },
    },
  };
}

/** The options of renderHost. */
export interface HostOptions {
  readonly contributions: Contributions;
  /** The registrations of the addons, by namespace; an addon without one cannot be loaded. */
  readonly registrations: Readonly<Record<string, unknown>>;
  readonly ext?: unknown;
  readonly texts?: Readonly<Record<string, string>>;
  readonly details?: boolean;
  /** How the services answer commands and confirmations. */
  readonly answering?: Answering;
}

/** Renders the node inside the host runtime, and returns the screen, a user and what was recorded. */
export function renderHost(
  node: ReactNode,
  options: HostOptions,
): RenderResult & {
  readonly user: UserEvent;
  readonly recorded: Recorded;
  readonly source: AddonSource;
} {
  const user = userEvent.setup();
  const { services: built, recorded } = services(options.texts, options.answering);
  const load: AddonLoader = (addon) => {
    const known = options.registrations[addon];

    return known === undefined
      ? Promise.reject(new Error(`No bundle for ${addon}.`))
      : Promise.resolve({ default: known });
  };
  const source = new AddonSource(load, {}, built.report);
  const result = render(
    <KitI18nProvider locale="en">
      <TranslationProvider locale="en">
        <HostRuntimeProvider
          contributions={options.contributions}
          ext={options.ext}
          source={source}
          services={built}
        >
          {node}
        </HostRuntimeProvider>
      </TranslationProvider>
    </KitI18nProvider>,
  );

  return { ...result, user, recorded, source };
}

/** The codes of the reports, in order. */
export function codes(recorded: Recorded): string[] {
  return recorded.reports.map(
    (report) =>
      `${report.code} ${report.addon}${report.contribution === undefined ? '' : ` ${report.contribution}`}`,
  );
}
