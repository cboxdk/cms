// The host API (section 3.12 of the panel extension architecture): the one way a contribution
// reaches the panel. It has texts and formatting in the panel's locale, notices, navigation to the
// panel's own pages, the commands the addon may issue, and dialogs; there is no fetch helper, no
// router, no page props, no document handle and no nonce. A contribution's data comes from its
// data query on the server, never from the browser.

import { createContext, useContext } from 'react';

import type { IssuedCommands, NoCommands } from './contributions';
import type { ProblemV1 } from './generated/protocol/ProblemV1';
import type { ReceiptV1 } from './generated/protocol/ReceiptV1';

/**
 * A key of the addon's catalogue, in its namespace, such as `approvals.heading`.
 *
 * @stable
 */
export type TranslationKey = string;

/**
 * The values a text names as `{name}`.
 *
 * @stable
 */
export type TranslationParameters = Readonly<Record<string, string | number>>;

/**
 * The tone of a notice.
 *
 * @stable
 */
export type NoticeTone = 'info' | 'success' | 'warning' | 'danger';

/**
 * A notice the panel shows the viewer, with a text of the addon's catalogue.
 *
 * @stable
 */
export interface Notice {
  readonly tone: NoticeTone;
  readonly message: TranslationKey;
  readonly parameters?: TranslationParameters;
}

/**
 * The wait level and dry run of a command the addon issues; the panel builds the rest of the
 * envelope, the idempotency key and the provenance `addon:<namespace>:<contribution>` included.
 *
 * @stable
 */
export interface CommandOptions {
  readonly dryRun?: boolean;
  readonly waitLevel?: ReceiptV1['wait_level'];
}

/**
 * What a command the addon issued answered: the receipt, for every outcome, and the problem
 * details of a rejection, or null.
 *
 * @stable
 */
export interface CommandAnswer {
  readonly receipt: ReceiptV1;
  readonly problem: ProblemV1 | null;
}

/**
 * A dialog that asks the viewer to confirm, with texts of the addon's catalogue.
 *
 * @stable
 */
export interface DialogRequest {
  readonly title: TranslationKey;
  readonly body: TranslationKey;
  readonly confirm: TranslationKey;
  readonly tone?: 'neutral' | 'danger';
  readonly parameters?: TranslationParameters;
}

/**
 * The panel as a contribution reaches it. I is the commands the addon may issue, so runCommand()
 * takes only those, each with its own document; `cms:panel:types` writes the addon's as Issues.
 *
 * @stable
 */
export interface PanelHost<I extends IssuedCommands<I> = NoCommands> {
  /** The panel's locale. */
  readonly locale: string;
  /** The text of a key of the addon's catalogue in the panel's locale, with its parameters filled in. */
  readonly t: (key: TranslationKey, parameters?: TranslationParameters) => string;
  readonly formatDate: (value: Date, options?: Intl.DateTimeFormatOptions) => string;
  readonly formatNumber: (value: number, options?: Intl.NumberFormatOptions) => string;
  readonly formatList: (values: readonly string[], options?: Intl.ListFormatOptions) => string;
  readonly notify: (notice: Notice) => void;
  /** Goes to a page of the panel's registry, by its page id, such as `account.me`. */
  readonly navigate: (page: string, parameters?: Readonly<Record<string, string>>) => void;
  /**
   * Runs a command the addon may issue through the panel's Inertia profile, as the viewer, and
   * answers with its receipt; `command` is the command's name and version, such as
   * `approvals.request@1`.
   */
  readonly runCommand: <K extends keyof I & string>(
    command: K,
    document: I[K],
    options?: CommandOptions,
  ) => Promise<CommandAnswer>;
  /** Asks the viewer to confirm, and answers whether they did. */
  readonly openDialog: (dialog: DialogRequest) => Promise<boolean>;
}

/**
 * The commands of a host as the context holds it, before usePanelHost() names the addon's.
 */
export type AnyCommands = Readonly<Record<string, object>>;

/**
 * The context the panel provides its host in. It holds the host as the panel built it, for any
 * command; usePanelHost() narrows it to the addon's commands.
 */
export const PanelHostContext = createContext<PanelHost<AnyCommands> | null>(null);

/**
 * Thrown by usePanelHost() outside the panel, such as in a component rendered without a host.
 *
 * @stable
 */
export class PanelHostMissing extends Error {
  public constructor() {
    super(
      'usePanelHost() was called outside the panel: a contribution reaches the host only while the panel renders it. In a test, render it inside PanelHostProvider from @cboxdk/cms-panel/testing.',
    );
    this.name = 'PanelHostMissing';
  }
}

/**
 * The panel's host, for the component of a contribution. I is the commands the addon may issue,
 * as `cms:panel:types` writes them: `usePanelHost<Issues>()`.
 *
 * @stable
 */
export function usePanelHost<I extends IssuedCommands<I> = NoCommands>(): PanelHost<I> {
  const host = useContext(PanelHostContext);

  if (host === null) {
    throw new PanelHostMissing();
  }

  // The panel built the host for any command it lets the addon issue: those of the addon's
  // manifest, which cms:build checked, and which the type parameter names.
  return host;
}
