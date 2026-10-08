// The host runtime of the panel's pages (PRD 13.4): every page renders inside it, with the
// contributions and data its props carry. It builds the services a contribution's host reaches
// once per session: commands through the Inertia profile, navigation through Inertia's router,
// texts of the panel's own catalogue for the core's contributions and of the page's compiled
// catalogue of the active locale for each addon (section 2.6 of the panel extension architecture),
// notices in the kit's toast region and confirmations in the kit's dialog. A report of a
// contribution that failed is dispatched on the window as the event `cms:panel-report`, whose
// detail names the code, the addon, the point and the contribution and nothing a contribution
// wrote.

import { ConfirmDialog, ToastRegion, createToastQueue } from '@cboxdk/cms-ui-kit';
import { router } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';

import { validateContributionsV1 } from '../generated/pages/ContributionsV1';
import { localeOf, lookup, useTranslation } from '../i18n/translations';
import { AddonSource, CORE_NAMESPACE } from './addons';
import { inertiaCommands, type CommandRouter } from './commands';
import { CORE_CONTRIBUTIONS } from './core';
import { NO_CONTRIBUTIONS, catalogues, type Contributions } from './model';
import type { HostServices } from './panel-host';
import type { HostReport } from './reports';
import { HostRuntimeProvider } from './runtime';

/** The name of the window event a report is dispatched as. */
export const REPORT_EVENT = 'cms:panel-report';

/** Dispatches a report on the window. */
export function dispatchReport(report: HostReport): void {
  window.dispatchEvent(new CustomEvent<HostReport>(REPORT_EVENT, { detail: report }));
}

/** The bare specifier the panel's import map names the entry module of an addon's bundle by. */
export function addonSpecifier(addon: string): string {
  return `cms-addons/${addon}`;
}

/** Imports the entry module of an addon's bundle through the panel's import map. */
export function importAddon(addon: string): Promise<unknown> {
  return import(/* @vite-ignore */ addonSpecifier(addon));
}

/** Inertia's router as the transport of commands uses it. */
const COMMAND_ROUTER: CommandRouter = {
  post: (url, data, options) => {
    // The body is JSON, the envelope and the command document, which Inertia sends as it is.
    router.post(url, data as Parameters<typeof router.post>[1], options);
  },
  on: (event, callback) => router.on(event, callback),
};

/** The page's cms.contributions, or none when the page sends none or a document of another form. */
export function contributionsOf(props: Readonly<Record<string, unknown>>): Contributions {
  const cms = props.cms;
  const document =
    typeof cms === 'object' && cms !== null && 'contributions' in cms
      ? cms.contributions
      : undefined;
  const checked = document === undefined ? undefined : validateContributionsV1(document);

  return checked?.valid === true ? checked.value : NO_CONTRIBUTIONS;
}

interface PendingConfirmation {
  readonly title: string;
  readonly body: string;
  readonly confirm: string;
  readonly tone: 'neutral' | 'danger';
  readonly answer: (confirmed: boolean) => void;
}

/** The props of PanelRuntime. */
export interface PanelRuntimeProps {
  /** The props of the page Inertia renders. */
  readonly props: Readonly<Record<string, unknown>>;
  readonly children: ReactNode;
}

/** Renders a page of the panel inside the host runtime. */
export function PanelRuntime({ props, children }: PanelRuntimeProps) {
  const { t } = useTranslation();
  const locale = localeOf(document.documentElement.lang);
  const contributions = useMemo(() => contributionsOf(props), [props]);
  const texts = useMemo(() => catalogues(contributions), [contributions]);
  const [toasts] = useState(createToastQueue);
  const [source] = useState(
    () => new AddonSource(importAddon, { [CORE_NAMESPACE]: CORE_CONTRIBUTIONS }, dispatchReport),
  );
  const [pending, setPending] = useState<PendingConfirmation | null>(null);
  const commands = contributions.commands;

  const services = useMemo<HostServices>(
    () => ({
      locale,
      text: (addon, key) =>
        addon === CORE_NAMESPACE ? lookup(locale, key) : texts.get(addon)?.get(key),
      notify: (_addon, notice) => {
        toasts.add({
          title: notice.message,
          tone: notice.tone,
        });
      },
      visit: (url) => {
        router.visit(url);
      },
      runCommand: inertiaCommands(COMMAND_ROUTER, commands),
      confirm: (dialog) =>
        new Promise<boolean>((resolve) => {
          setPending({ ...dialog, answer: resolve });
        }),
      report: dispatchReport,
    }),
    [locale, texts, toasts, commands],
  );

  return (
    <HostRuntimeProvider
      contributions={contributions}
      ext={props.ext}
      source={source}
      services={services}
    >
      {children}
      <ToastRegion queue={toasts} />
      {pending === null ? null : (
        <ConfirmDialog
          open
          title={pending.title}
          message={pending.body}
          confirmLabel={pending.confirm}
          cancelLabel={t('panel.host.cancel')}
          tone={pending.tone}
          onConfirm={() => {
            pending.answer(true);
          }}
          onOpenChange={(open) => {
            if (!open) {
              pending.answer(false);
              setPending(null);
            }
          }}
        />
      )}
    </HostRuntimeProvider>
  );
}
