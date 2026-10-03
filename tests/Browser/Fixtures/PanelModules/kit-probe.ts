// A test-only module that renders React Aria Components (D2) with the panel's shared React, under
// the panel's Content-Security-Policy, in Danish: a dialog in a modal, a combo box with its popover
// and a date picker. React Aria sets the styles it needs, such as a popover's position, through
// the CSSOM, so the policy, which has no 'unsafe-inline' for styles, has nothing to refuse.

import { createElement, Fragment } from 'react';
import { createRoot } from 'react-dom/client';
import {
  Button,
  ComboBox,
  DateInput,
  DatePicker,
  DateSegment,
  Dialog,
  DialogTrigger,
  Group,
  Heading,
  I18nProvider,
  Input,
  Label,
  ListBox,
  ListBoxItem,
  Modal,
  Popover,
} from 'react-aria-components';

const DAYS = ['Mandag', 'Tirsdag', 'Onsdag'];

function dialog() {
  return createElement(
    DialogTrigger,
    null,
    createElement(Button, { id: 'kit-open' }, 'Åbn'),
    createElement(
      Modal,
      null,
      createElement(
        Dialog,
        { id: 'kit-dialog' },
        createElement(Heading, { slot: 'title' }, 'Dialog'),
        createElement(Button, { slot: 'close', id: 'kit-close' }, 'Luk'),
      ),
    ),
  );
}

function comboBox() {
  return createElement(
    ComboBox,
    { id: 'kit-combo' },
    createElement(Label, null, 'Dag'),
    createElement(Input, { id: 'kit-combo-input' }),
    createElement(
      Popover,
      null,
      createElement(
        ListBox,
        { id: 'kit-combo-list' },
        ...DAYS.map((day) => createElement(ListBoxItem, { key: day, id: day }, day)),
      ),
    ),
  );
}

function datePicker() {
  return createElement(
    DatePicker,
    { id: 'kit-date' },
    createElement(Label, null, 'Dato'),
    createElement(
      Group,
      { id: 'kit-date-group' },
      createElement(DateInput, {
        children: (segment) => createElement(DateSegment, { segment }),
      }),
    ),
  );
}

export function mount(element: HTMLElement): void {
  createRoot(element).render(
    createElement(I18nProvider, {
      locale: 'da-DK',
      children: createElement(Fragment, null, dialog(), comboBox(), datePicker()),
    }),
  );
}
