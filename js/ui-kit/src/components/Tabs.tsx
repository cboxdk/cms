import type { ReactNode } from 'react';
import { Tab, TabList, TabPanel, Tabs as AriaTabs } from 'react-aria-components/Tabs';
import type { Key } from 'react-stately';

import { defined } from './internal/defined';

import './tabs.css';

/**
 * A tab of Tabs and the view it shows.
 *
 * @experimental
 */
export interface TabSpec {
  /** An id unique among the tabs, which onChange gives back. */
  readonly id: string;
  /** The tab's name, from the caller's translations. */
  readonly label: string;
  /** What the tab shows. */
  readonly content: ReactNode;
  /** Whether the tab cannot be shown now; the arrow keys skip it. */
  readonly disabled?: boolean | undefined;
}

/**
 * The props of Tabs.
 *
 * @experimental
 */
export interface TabsProps {
  /** What the tabs divide, such as "Actor", from the caller's translations. */
  readonly label: string;
  /** The tabs, in the order they are shown. */
  readonly tabs: readonly TabSpec[];
  /** The id of the tab shown; with onChange, the tabs are controlled. */
  readonly selected?: string | undefined;
  /** The id of the tab shown at first, when the tabs are not controlled. */
  readonly defaultSelected?: string | undefined;
  /** Called with the id of the tab the reader shows. */
  readonly onChange?: ((id: string) => void) | undefined;
}

/**
 * Views of one thing, one shown at a time, such as an actor's profile and grants. Tab reaches the
 * shown tab, Left and Right move to the others and show them, Home and End go to the first and the
 * last, and Tab again moves into the shown panel.
 *
 * @experimental
 */
export function Tabs({ label, tabs, selected, defaultSelected, onChange }: TabsProps) {
  return (
    <AriaTabs
      {...defined({ selectedKey: selected, defaultSelectedKey: defaultSelected })}
      onSelectionChange={(key: Key) => {
        onChange?.(String(key));
      }}
      disabledKeys={tabs.filter((tab) => tab.disabled === true).map((tab) => tab.id)}
      className="cms-tabs"
    >
      <TabList aria-label={label} className="cms-tabs__list">
        {tabs.map((tab) => (
          <Tab key={tab.id} id={tab.id} className="cms-tabs__tab">
            {tab.label}
          </Tab>
        ))}
      </TabList>
      {tabs.map((tab) => (
        <TabPanel key={tab.id} id={tab.id} className="cms-tabs__panel">
          {tab.content}
        </TabPanel>
      ))}
    </AriaTabs>
  );
}
