// The host an addon's tests render its contributions with (section 7 of the panel extension
// architecture): PanelHostProvider provides any host, and createFakeHost() builds one that does
// what the panel's own host does, only against what the test gives it, and records every notice,
// navigation, command and dialog a contribution asked it for. A behaviour test in the panel's own
// workspace holds the fake to the real host.

import type { ReactNode } from 'react';

import type { IssuedCommands } from '../contributions';
import {
  PanelHostContext,
  type AnyCommands,
  type CommandAnswer,
  type CommandOptions,
  type DialogRequest,
  type Notice,
  type NoticeTone,
  type PanelHost,
  type TranslationParameters,
} from '../host';
import { committedReceipt } from './fixtures';

/**
 * The props of PanelHostProvider.
 *
 * @stable
 */
export interface PanelHostProviderProps<I extends IssuedCommands<I>> {
  readonly host: PanelHost<I>;
  readonly children: ReactNode;
}

/**
 * Provides a host to the contributions it renders, as the panel provides its own.
 *
 * @stable
 */
export function PanelHostProvider<I extends IssuedCommands<I>>(
  input: PanelHostProviderProps<I>,
): ReactNode {
  return (
    <PanelHostContext value={input.host as unknown as PanelHost<AnyCommands>}>
      {input.children}
    </PanelHostContext>
  );
}

/** The namespace of the core's own contributions, which read the panel's whole catalogue. */
const CORE_NAMESPACE = 'cms';

/** A parameter of a text, `{name}`. */
const PARAMETER = /\{([a-z_][a-z0-9_]*)\}/g;

/**
 * Thrown to a contribution that issues a command its addon may not issue, as the panel's host
 * throws it: the manifest's `issues` lists what an addon may issue, and cms:build checked it.
 *
 * @stable
 */
export class PanelCommandRefused extends Error {
  public constructor(addon: string, command: string) {
    super(
      `The addon ${addon} may not issue ${command}: list its command class in AddonCapabilities::$issues, and run cms:build again.`,
    );
    this.name = 'PanelCommandRefused';
  }
}

/**
 * A command a contribution asked the host to run, as the host would send it to the panel's
 * Inertia profile.
 *
 * @stable
 */
export interface RecordedCommand {
  /** The command's name and version, such as `approvals.request@1`. */
  readonly command: string;
  readonly document: object;
  readonly options: CommandOptions;
}

/**
 * A notice a contribution asked the host to show, its message already in the host's locale.
 *
 * @stable
 */
export interface RecordedNotice {
  readonly tone: NoticeTone;
  readonly message: string;
}

/**
 * A dialog a contribution asked the host to open, its texts already in the host's locale.
 *
 * @stable
 */
export interface RecordedDialog {
  readonly title: string;
  readonly body: string;
  readonly confirm: string;
  readonly tone: 'neutral' | 'danger';
}

/**
 * What the host refused a contribution: a command its addon may not issue, or a page the panel
 * has not. The panel's host reports the same codes.
 *
 * @stable
 */
export interface HostRefusal {
  readonly code: 'panel_command_refused' | 'panel_navigation_refused';
  /** The command, or the page, that was refused. */
  readonly subject: string;
}

/**
 * What a fake host was asked, in order.
 *
 * @stable
 */
export interface HostRecord {
  readonly commands: readonly RecordedCommand[];
  readonly notices: readonly RecordedNotice[];
  /** The addresses navigate() went to, each the page's address with the parameters as its query. */
  readonly visits: readonly string[];
  readonly dialogs: readonly RecordedDialog[];
  readonly refusals: readonly HostRefusal[];
}

/**
 * What a fake host is built from. Everything has a default, so `createFakeHost()` alone gives a
 * host of an addon that issues no command and has no texts.
 *
 * @stable
 */
export interface FakeHostOptions {
  /** The addon whose contribution the host serves; its texts are those of its catalogue. */
  readonly namespace?: string;
  /** The panel's locale; `en` unless given. */
  readonly locale?: string;
  /** The addon's catalogue in the locale, by key. A key it lacks shows as the key. */
  readonly texts?: Readonly<Record<string, string>>;
  /** The commands the addon may issue, each `<name>@<version>`, as its manifest lists them. */
  readonly issues?: readonly string[];
  /** The pages of the panel navigate() may go to, by page id, each its address. */
  readonly pages?: Readonly<Record<string, string>>;
  /** What a command answers; a committed receipt unless given. */
  readonly answer?: (command: RecordedCommand) => CommandAnswer | Promise<CommandAnswer>;
  /** What the viewer answers a dialog; yes unless given. */
  readonly confirm?: boolean | ((dialog: RecordedDialog) => boolean);
}

/**
 * The commands a fake host serves unless a test names the addon's: any, each with any document.
 *
 * @stable
 */
export type AnyIssuedCommands = Readonly<Record<string, object>>;

/**
 * A fake host: the panel's host API, with what it was asked.
 *
 * @stable
 */
export interface FakeHost<I extends IssuedCommands<I> = AnyIssuedCommands> extends PanelHost<I> {
  readonly record: HostRecord;
}

/**
 * A text with its parameters filled in, as the panel fills them: `{name}` becomes the parameter's
 * value, and a parameter the text does not name is left out.
 *
 * @stable
 */
export function fillText(text: string, parameters: TranslationParameters = {}): string {
  return text.replace(PARAMETER, (match, name: string) => {
    const value = parameters[name];

    return value === undefined ? match : String(value);
  });
}

/**
 * Builds a fake host of an addon's contribution, which does what the panel's host does: texts of
 * the addon's own catalogue only, a key outside its namespace or missing from the catalogue
 * showing as the key; formatting in the locale through Intl; notices and dialogs with their texts
 * in the locale; navigation only to the pages given, and only the commands given, each recorded;
 * a command the addon may not issue is refused with PanelCommandRefused, as the panel refuses it.
 *
 * @stable
 */
export function createFakeHost<I extends IssuedCommands<I> = AnyIssuedCommands>(
  options: FakeHostOptions = {},
): FakeHost<I> {
  const namespace = options.namespace ?? 'addon';
  const locale = options.locale ?? 'en';
  const texts = options.texts ?? {};
  const issues = options.issues ?? [];
  const pages = options.pages ?? {};
  const answer = options.answer ?? (() => committedReceipt());
  const confirm = options.confirm ?? true;
  const commands: RecordedCommand[] = [];
  const notices: RecordedNotice[] = [];
  const visits: string[] = [];
  const dialogs: RecordedDialog[] = [];
  const refusals: HostRefusal[] = [];

  const t = (key: string, parameters?: TranslationParameters): string => {
    const own = namespace === CORE_NAMESPACE || key.startsWith(`${namespace}.`);

    return fillText((own ? texts[key] : undefined) ?? key, parameters);
  };

  const host: FakeHost = Object.freeze({
    locale,
    t,
    formatDate: (value: Date, formatOptions?: Intl.DateTimeFormatOptions) =>
      new Intl.DateTimeFormat(locale, formatOptions).format(value),
    formatNumber: (value: number, formatOptions?: Intl.NumberFormatOptions) =>
      new Intl.NumberFormat(locale, formatOptions).format(value),
    formatList: (values: readonly string[], formatOptions?: Intl.ListFormatOptions) =>
      new Intl.ListFormat(locale, formatOptions).format(values),
    notify: (notice: Notice) => {
      notices.push({ tone: notice.tone, message: t(notice.message, notice.parameters) });
    },
    navigate: (page: string, parameters: Readonly<Record<string, string>> = {}) => {
      const url = pages[page];

      if (url === undefined) {
        refusals.push({ code: 'panel_navigation_refused', subject: page });

        return;
      }

      const query = new URLSearchParams(parameters).toString();
      visits.push(query === '' ? url : `${url}?${query}`);
    },
    runCommand: (command: string, document: object, commandOptions: CommandOptions = {}) => {
      if (namespace !== CORE_NAMESPACE && !issues.includes(command)) {
        refusals.push({ code: 'panel_command_refused', subject: command });

        return Promise.reject(new PanelCommandRefused(namespace, command));
      }

      const recorded: RecordedCommand = { command, document, options: commandOptions };
      commands.push(recorded);

      return Promise.resolve(answer(recorded));
    },
    openDialog: (dialog: DialogRequest) => {
      const recorded: RecordedDialog = {
        title: t(dialog.title, dialog.parameters),
        body: t(dialog.body, dialog.parameters),
        confirm: t(dialog.confirm, dialog.parameters),
        tone: dialog.tone ?? 'neutral',
      };
      dialogs.push(recorded);

      return Promise.resolve(typeof confirm === 'function' ? confirm(recorded) : confirm);
    },
    record: { commands, notices, visits, dialogs, refusals },
  });

  // The host serves any command the test lists; the type parameter names the addon's.
  return host;
}
