// The shell every page behind the login renders around its content (PRD 13.4, section 8 of the
// panel extension architecture): the kit's AppShell, with the installation's brand and the actions
// of the viewer's menu, which addons contribute to shell.user-menu@1, in the top bar, an action
// whose manifest asks for the command's form opening that form, the
// navigation beside the content, the nav entries of shell.nav@1 that the server left for the
// viewer, each opening one of the panel's own pages, such as the who-am-I page the core contributes
// the entry of, or a page of an addon below /x/<namespace>/, and the page in the main landmark the
// skip link leads to. The command palette opens from every page with Ctrl+K or Command+K and from
// its button in the top bar, built from the prop `palette` every page behind the login shares; the
// nav entries are its pages too. Below the pages, the Content section opens the content list across
// every type and of each type, one entry per type of the prop `types`, the read of type.list, so
// the navigation offers the types the person may see and names no type itself. Each page keeps its
// sign-out in its own content.

import { AppShell, Brand, SideNav } from '@cboxdk/cms-ui-kit';
import { router, usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';

import { useBrand } from '../brand';
import { typeListUrl, typesOf } from '../content/types';
import { PointHost, usePointHost, type HostAction } from '../host';
import { useHostRuntime } from '../host/runtime';
import { useTranslation } from '../i18n/translations';
import { PanelPalette } from './PanelPalette';

/**
 * The address of the form of the command an action opens, `<commands>/<name>/v<version>`, the
 * form page of the Inertia profile's address, as the palette opens it.
 */
export function commandFormUrl(commands: string, command: string): string {
  const at = command.lastIndexOf('@');

  return at === -1
    ? `${commands}/${command}/v1`
    : `${commands}/${command.slice(0, at)}/v${command.slice(at + 1)}`;
}

/** The props of PanelShell. */
export interface PanelShellProps {
  /** The id of the page shown, which its nav entry is marked as; undefined for a page without an entry. */
  readonly page?: string | undefined;
  /**
   * On the content list, the name of the type it lists, or null for the list across every type,
   * which its entry in the Content section is marked as; undefined on every other page.
   */
  readonly contentType?: string | null | undefined;
  readonly children: ReactNode;
}

/** The shell around a page behind the login: the brand, the viewer's menu, the navigation and the main landmark. */
export function PanelShell({ page, contentType, children }: PanelShellProps) {
  const { t } = useTranslation();
  const brand = useBrand();
  const { nav } = usePointHost('shell.nav@1');
  const { contributions } = useHostRuntime();
  const shared = usePage().props;
  const palette = shared.palette;
  const types = useMemo(() => typesOf(shared['types']), [shared]);
  const content =
    types.status === 'ready'
      ? [
          {
            id: 'content',
            label: t('panel.shell.content'),
            items: [
              {
                id: 'content.all',
                label: t('panel.nav.content_all'),
                href: types.content,
                current: contentType === null,
              },
              ...types.types.map((type) => ({
                id: `content.${type.name}`,
                label: type.label,
                href: typeListUrl(types.content, type.name),
                current: contentType === type.name,
              })),
            ],
          },
        ]
      : [];

  // An action of the viewer's menu whose manifest asks for the command's form opens that form.
  function openForm(action: HostAction): void {
    router.visit(commandFormUrl(contributions.commands, action.command));
  }

  return (
    <AppShell
      brand={<Brand name={brand.name} logo={brand.logo} />}
      actions={
        <>
          <PanelPalette palette={palette} />
          <PointHost
            point="shell.user-menu@1"
            label={t('panel.shell.user_menu')}
            onAction={openForm}
          />
        </>
      }
      navigation={
        nav.length === 0 && content.length === 0 ? null : (
          <SideNav
            label={t('panel.shell.nav')}
            groups={[
              ...(nav.length === 0
                ? []
                : [
                    {
                      id: 'pages',
                      items: nav.map((entry) => ({
                        id: entry.id,
                        label: entry.label,
                        href: entry.url,
                        current: entry.page === page,
                      })),
                    },
                  ]),
              ...content,
            ]}
          />
        )
      }
    >
      {children}
    </AppShell>
  );
}
