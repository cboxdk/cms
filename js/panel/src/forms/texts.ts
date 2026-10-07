// The texts of a command form's fields (GUARDRAILS 8): the panel's catalogue when it has a text for
// the command's field, and the schema's own title and description otherwise, so a kernel command
// reads in the person's language and an addon's command reads as its schema describes it. The keys
// are `panel.action.<command>.field.<path>.label`, `.description` and `.option.<value>`, with the
// path the keys of the member from the document to it joined by dots, without list indexes, such
// as `panel.action.placement.set_window.field.window.live_from.label`.

import type { SchemaFormTexts } from '@cboxdk/cms-ui-kit';

import { lookup, type Locale } from '../i18n/translations';

/** The texts of the fields of the command's form in the locale. */
export function fieldTexts(locale: Locale, command: string): SchemaFormTexts {
  const key = (keys: readonly string[], part: string): string =>
    `panel.action.${command}.field.${keys.join('.')}.${part}`;

  return {
    label: (keys, fallback) => lookup(locale, key(keys, 'label')) ?? fallback,
    description: (keys, fallback) => lookup(locale, key(keys, 'description')) ?? fallback,
    option: (keys, value) => lookup(locale, key(keys, `option.${value}`)) ?? value,
  };
}
