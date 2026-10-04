// The host API a contribution reaches the panel through (section 3.12 of the panel extension
// architecture), built per contribution: texts of the addon's own catalogue in the panel's locale,
// formatting in it, notices, navigation to the panel's own pages, the commands the addon may issue,
// each sent with the provenance `addon:<namespace>:<contribution>` of the contribution that issued
// it, and dialogs. A contribution gets nothing else: no fetch helper, no router, no page props, no
// document handle and no nonce.

import type {
  CommandAnswer,
  DialogRequest,
  Notice,
  PanelHost,
  TranslationParameters,
} from '@cboxdk/cms-panel/extend';

import { fill } from '../i18n/translations';
import { CORE_NAMESPACE } from './addons';
import { provenanceOf, type CommandTransport } from './commands';
import type { AddonEntry, Contributions } from './model';
import type { HostReporter } from './reports';

/** The commands of a host as the panel builds it, before a contribution names its own. */
export type AnyCommands = Readonly<Record<string, object>>;

/** Thrown to a contribution that issues a command its addon may not issue. */
export class PanelCommandRefused extends Error {
  public constructor(addon: string, command: string) {
    super(
      `The addon ${addon} may not issue ${command}: list its command class in AddonCapabilities::$issues, and run cms:build again.`,
    );
    this.name = 'PanelCommandRefused';
  }
}

/** What the panel gives every contribution's host. */
export interface HostServices {
  readonly locale: string;
  /** The text of a key in the addon's catalogue, or undefined when the catalogue has none. */
  readonly text: (addon: string, key: string) => string | undefined;
  /** Shows a notice of the addon, its message already in the panel's locale. */
  readonly notify: (addon: string, notice: Notice) => void;
  /** Goes to an address on the panel's origin. */
  readonly visit: (url: string) => void;
  readonly runCommand: CommandTransport;
  /** Asks the viewer to confirm, with the texts already in the panel's locale. */
  readonly confirm: (dialog: {
    readonly title: string;
    readonly body: string;
    readonly confirm: string;
    readonly tone: 'neutral' | 'danger';
  }) => Promise<boolean>;
  readonly report: HostReporter;
}

/**
 * The texts of an addon: a key of its catalogue in the panel's locale with its parameters filled
 * in, a key outside the addon's namespace or missing from its catalogue showing as the key. The
 * core's own contributions, in the namespace cms, read the panel's catalogue.
 */
export function addonTexts(
  services: Pick<HostServices, 'text'>,
  namespace: string,
): (key: string, parameters?: TranslationParameters) => string {
  return (key, parameters) => {
    const own = namespace === CORE_NAMESPACE || key.startsWith(`${namespace}.`);

    return fill((own ? services.text(namespace, key) : undefined) ?? key, parameters);
  };
}

/**
 * The host of one contribution of an addon on a point. Its texts are those of the addon's
 * catalogue, a key outside the addon's namespace or missing from its catalogue showing as the key;
 * the commands it issues are those of the addon's entry, any for the core's own.
 */
export function createPanelHost(
  services: HostServices,
  contributions: Contributions,
  addon: AddonEntry | undefined,
  namespace: string,
  point: string,
  contribution: string,
): PanelHost<AnyCommands> {
  const t = addonTexts(services, namespace);

  return Object.freeze({
    locale: services.locale,
    t,
    formatDate: (value: Date, options?: Intl.DateTimeFormatOptions) =>
      new Intl.DateTimeFormat(services.locale, options).format(value),
    formatNumber: (value: number, options?: Intl.NumberFormatOptions) =>
      new Intl.NumberFormat(services.locale, options).format(value),
    formatList: (values: readonly string[], options?: Intl.ListFormatOptions) =>
      new Intl.ListFormat(services.locale, options).format(values),
    notify: (notice: Notice) => {
      services.notify(namespace, {
        tone: notice.tone,
        message: t(notice.message, notice.parameters),
      });
    },
    navigate: (page: string, parameters: Readonly<Record<string, string>> = {}) => {
      const link = contributions.pages.find((candidate) => candidate.page === page);

      if (link === undefined) {
        services.report({
          code: 'panel_navigation_refused',
          addon: namespace,
          point,
          contribution,
        });

        return;
      }

      const query = new URLSearchParams(parameters).toString();
      services.visit(query === '' ? link.url : `${link.url}?${query}`);
    },
    runCommand: (command: string, document: object, options = {}): Promise<CommandAnswer> => {
      if (addon === undefined || (!addon.any_command && !addon.issues.includes(command))) {
        services.report({ code: 'panel_command_refused', addon: namespace, point, contribution });

        return Promise.reject(new PanelCommandRefused(namespace, command));
      }

      return services.runCommand({
        command,
        document,
        options,
        ...(namespace === CORE_NAMESPACE
          ? {}
          : { provenance: provenanceOf(namespace, contribution) }),
      });
    },
    openDialog: (dialog: DialogRequest) =>
      services.confirm({
        title: t(dialog.title, dialog.parameters),
        body: t(dialog.body, dialog.parameters),
        confirm: t(dialog.confirm, dialog.parameters),
        tone: dialog.tone ?? 'neutral',
      }),
  });
}
