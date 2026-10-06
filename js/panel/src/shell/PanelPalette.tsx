import { Button, CommandPalette, ErrorState, KeyboardShortcut } from '@cboxdk/cms-ui-kit';
import { useMemo, useState } from 'react';

import { usePointHost } from '../host';
import { useHostRuntime } from '../host/runtime';
import { useTranslation } from '../i18n/translations';
import { paletteCommands, paletteOf, palettePages, urlOf } from './palette';

/** The props of PanelPalette. */
export interface PanelPaletteProps {
  /** The prop `palette` of the page, as the server shared it. */
  readonly palette: unknown;
}

/**
 * The command palette of the shell (GUARDRAILS 8, keyboard first; PRD 13.4): Ctrl+K, or Command+K
 * on a Mac, opens it from every page behind the login, and so does its button in the shell's top
 * bar. Its entries come from the prop `palette`, the read of action.list as the person who signed
 * in: the pages the shell's navigation knows, and the commands the person may run, labelled by
 * the catalogue or the command's schema. Choosing a page entry opens the page; choosing a command
 * entry opens the command's form page. A prop the panel cannot read, or a rejected read, shows
 * why in the palette instead of entries, so the palette still opens and closes.
 */
export function PanelPalette({ palette }: PanelPaletteProps) {
  const { t, locale } = useTranslation();
  const { nav } = usePointHost('shell.nav@1');
  const runtime = useHostRuntime();
  const [open, setOpen] = useState(false);
  const state = useMemo(() => paletteOf(palette), [palette]);
  const commands = runtime.contributions.commands;
  const items = useMemo(
    () =>
      state.status === 'ready'
        ? [...palettePages(state.list, nav), ...paletteCommands(state.list, commands, locale)]
        : [],
    [state, nav, commands, locale],
  );
  const sections = useMemo(
    () =>
      state.status === 'ready'
        ? [
            { id: 'pages', title: t('panel.palette.pages'), items: palettePages(state.list, nav) },
            {
              id: 'commands',
              title: t('panel.palette.commands'),
              items: paletteCommands(state.list, commands, locale),
            },
          ].filter((section) => section.items.length > 0)
        : [],
    [state, nav, commands, locale, t],
  );

  let error;

  if (state.status === 'rejected') {
    error = (
      <ErrorState
        title={t('panel.palette.refused_title')}
        description={t('panel.palette.refused_body')}
        code={state.problem.code}
        headingLevel={2}
      />
    );
  } else if (state.status === 'unreadable') {
    error = (
      <ErrorState
        title={t('panel.palette.unavailable_title')}
        description={t('panel.palette.unavailable_body')}
        headingLevel={2}
      />
    );
  }

  return (
    <>
      <Button
        variant="quiet"
        icon="search"
        onClick={() => {
          setOpen(true);
        }}
      >
        {t('panel.palette.open')} <KeyboardShortcut keys={['Mod', 'K']} />
      </Button>
      <CommandPalette
        label={t('panel.palette.label')}
        searchLabel={t('panel.palette.search')}
        sections={sections}
        open={open}
        onOpenChange={setOpen}
        onAction={(id) => {
          const url = urlOf(items, id);

          if (url !== undefined) {
            runtime.services.visit(url);
          }
        }}
        emptyLabel={t('panel.palette.empty')}
        error={error}
      />
    </>
  );
}
