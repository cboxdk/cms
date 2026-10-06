import { useEffect, type ReactNode } from 'react';
import { useFilter } from 'react-aria';
import { Autocomplete } from 'react-aria-components/Autocomplete';
import { Collection } from 'react-aria-components/Collection';
import { Dialog as AriaDialog } from 'react-aria-components/Dialog';
import { Header } from 'react-aria-components/Header';
import { Input } from 'react-aria-components/Input';
import { ListBox, ListBoxItem, ListBoxSection } from 'react-aria-components/ListBox';
import { SearchField } from 'react-aria-components/SearchField';
import { Text } from 'react-aria-components/Text';
import type { Key } from 'react-stately';

import { Icon } from './Icon';
import { ModalFrame } from './internal/ModalFrame';
import { ProgressLabel } from './ProgressLabel';

import './command-palette.css';
import './dialog.css';

/**
 * An entry of the CommandPalette: a page to open or a command to run.
 *
 * @experimental
 */
export interface CommandPaletteItem {
  /** An id unique in the palette, which onAction gives back. */
  readonly id: string;
  /** What the entry opens or runs, from the caller's translations. */
  readonly label: string;
  /** A second line, such as the command's description, from the caller's translations. */
  readonly description?: string | undefined;
  /** More words the entry is found by, such as the command's name. */
  readonly keywords?: readonly string[] | undefined;
}

/**
 * A section of the CommandPalette's entries, such as the pages.
 *
 * @experimental
 */
export interface CommandPaletteSection {
  /** An id unique among its siblings, which the component gives back. */
  readonly id: string;
  /** The section's heading, such as "Pages" or "Commands", from the caller's translations. */
  readonly title: string;
  /** The section's entries, in the order they are shown. */
  readonly items: readonly CommandPaletteItem[];
}

/**
 * The props of CommandPalette.
 *
 * @experimental
 */
export interface CommandPaletteProps {
  /** The palette's name, such as "Command palette", from the caller's translations. */
  readonly label: string;
  /** The search field's name, such as "Find a page or a command". */
  readonly searchLabel: string;
  /** The entries in their sections, in the order they are shown. */
  readonly sections: readonly CommandPaletteSection[];
  /** Called with the id of the entry chosen; the palette then closes. */
  readonly onAction: (id: string) => void;
  /** Whether the palette is open; the palette is controlled. */
  readonly open: boolean;
  /** Called with whether the palette is open, when the shortcut, Escape or an entry opens or closes it. */
  readonly onOpenChange: (open: boolean) => void;
  /** What the palette says when no entry matches, from the caller's translations. */
  readonly emptyLabel: string;
  /** What the palette waits for while its entries load, from the caller's translations. */
  readonly loading?: string | undefined;
  /** Why the entries could not be loaded and what to do, shown in place of them. */
  readonly error?: ReactNode;
  /** Whether Ctrl+K, or Command+K on a Mac, opens and closes it anywhere on the page; true. */
  readonly shortcut?: boolean;
}

/**
 * The panel's command palette (GUARDRAILS 8, keyboard first): a dialog with a search field and the
 * pages and commands that match what is typed, in sections, built as the combobox pattern of WAI-ARIA:
 * the search field controls a listbox of options and names the option in focus through
 * aria-activedescendant, so a screen reader hears each entry as it is reached. Ctrl+K, or Command+K
 * on a Mac, opens it from anywhere on the page and closes it again. Focus starts in the search
 * field and stays inside the dialog; typing filters the entries, ignoring case and accents, and Up
 * and Down move through them while focus stays in the field. Enter runs the entry in focus, and
 * Escape clears what was typed and then closes the palette, returning focus to where it was.
 *
 * @experimental
 */
export function CommandPalette({
  label,
  searchLabel,
  sections,
  onAction,
  open,
  onOpenChange,
  emptyLabel,
  loading,
  error,
  shortcut = true,
}: CommandPaletteProps) {
  const { contains } = useFilter({ sensitivity: 'base' });

  useEffect(() => {
    if (!shortcut) {
      return undefined;
    }

    const toggle = (event: KeyboardEvent) => {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && !event.altKey) {
        event.preventDefault();
        onOpenChange(!open);
      }
    };
    document.addEventListener('keydown', toggle);

    return () => {
      document.removeEventListener('keydown', toggle);
    };
  }, [shortcut, open, onOpenChange]);

  let body: ReactNode;

  if (loading !== undefined) {
    body = (
      <div className="cms-palette__message">
        <ProgressLabel>{loading}</ProgressLabel>
      </div>
    );
  } else if (error !== undefined) {
    body = <div className="cms-palette__message">{error}</div>;
  } else {
    body = (
      <ListBox
        aria-label={label}
        items={sections}
        onAction={(key: Key) => {
          onAction(String(key));
          onOpenChange(false);
        }}
        renderEmptyState={() => <p className="cms-palette__empty">{emptyLabel}</p>}
        className="cms-palette__list"
      >
        {(section) => (
          <ListBoxSection id={section.id} className="cms-palette__section">
            <Header className="cms-palette__heading">{section.title}</Header>
            <Collection items={section.items}>
              {(item) => (
                <ListBoxItem
                  id={item.id}
                  textValue={[item.label, item.description ?? '', ...(item.keywords ?? [])].join(
                    ' ',
                  )}
                  className="cms-palette__item"
                >
                  <Text slot="label" className="cms-palette__label">
                    {item.label}
                  </Text>
                  {item.description === undefined ? null : (
                    <Text slot="description" className="cms-palette__description">
                      {item.description}
                    </Text>
                  )}
                </ListBoxItem>
              )}
            </Collection>
          </ListBoxSection>
        )}
      </ListBox>
    );
  }

  return (
    <ModalFrame open={open} onOpenChange={onOpenChange} kind="palette" dismissable>
      <AriaDialog aria-label={label} className="cms-dialog">
        <Autocomplete filter={contains}>
          <SearchField aria-label={searchLabel} autoFocus className="cms-palette__search">
            <Icon name="search" />
            <Input className="cms-palette__input" placeholder={searchLabel} />
          </SearchField>
          {body}
        </Autocomplete>
      </AriaDialog>
    </ModalFrame>
  );
}
