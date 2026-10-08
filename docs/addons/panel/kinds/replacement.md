---
title: Replacement
weight: 26
description: "A replacement: a component that takes the place of a default for a key the addon owns, with exactly the point's props, and gives the default back when it throws."
---

# Replacement

A replacement takes the place of a default for one key, such as the input of a value class in a command form. One contribution wins each key.

| | |
|---|---|
| Manifest | `new ReplacementContribution($id, '<point>@<version>', <key>, data: <query class>)` |
| Bundle | a `Replacement<P, D>` of `@cboxdk/cms-panel/extend`, at `command.form.field@1` the `FieldInput` of `@cboxdk/cms-panel/experimental`, registered as `() => import('./Module')` |
| Receives | exactly the point's props, frozen, the data of its query when it names one, and what the host adds in the browser, such as `onChange` |
| Scaffold | none; register the module by hand |
| Test | `expectReplacementContract()` and `expectNoA11yViolations()` |
| Points | [`command.form.field@1`](../points/command-form-field.md) |

## Rules

- A point keys its replacements by `ReplacementKey`: a field type, a command `<name>@<version>` or a value class.
- At a point with `Ownership::Own` an addon replaces only what it owns: a field type its manifest contributes, or a command or class a scan root of its package declares (`registry_panel_unowned_target`). A point with `Ownership::Any` takes any key.
- Two replacements of one key fail the build with `registry_panel_replacement_conflict` unless `cbox-cms.panel.replacements` names the winner.
- A replacement that throws gives the default back, with a notice that names its addon.

## Example

The addon `acme/cms-notes` replaces the input of its own value class `NoteColour`:

<!-- example: examples/Vitest/Panel/Kinds/replacement.test.tsx -->
```tsx
// @vitest-environment jsdom

// A replacement contribution: the addon acme/cms-notes takes the place of the default input of every
// member a command binds to its own value class NoteColour, at a replacement point keyed by value
// class with Ownership::Own, so it replaces nothing it does not own. The component gets exactly the
// point's props, and onChange from the host; it keeps the default input's id and name, so the
// form's error summary still links to it and the value is submitted under the member's path, and
// it uses the kit's components, never markup of the panel's own. expectReplacementContract()
// renders it with exactly those props.

import { definePanelAddon, usePanelHost } from '@cboxdk/cms-panel/extend';
import { Select, type FieldInputProps } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectReplacementContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

const COLOURS = ['yellow', 'green', 'blue'] as const;

/** The input of a NoteColour: a choice of the colours a note may have. */
function NoteColourInput(props: FieldInputProps) {
  const { t } = usePanelHost();

  return (
    <Select
      id={props.id}
      name={props.path}
      label={props.label}
      error={props.errors[0]}
      required={props.presence === 'required'}
      disabled={props.read_only}
      value={props.value}
      options={COLOURS.map((colour) => ({ id: colour, label: t(`notes.colour.${colour}`) }))}
      onChange={(colour) => {
        props.onChange(colour);
      }}
    />
  );
}

const addon = definePanelAddon({
  'notes.colour-input': () => Promise.resolve({ default: NoteColourInput }),
});

test('notes.colour-input keeps the replacement contract and the default input s id', async () => {
  const rendered = await expectReplacementContract({
    addon,
    id: 'notes.colour-input',
    props: {
      command: 'notes.paint',
      version: 1,
      path: 'colour',
      id: 'command-colour',
      label: 'Colour',
      description: null,
      schema: { type: 'string', enum: ['yellow', 'green', 'blue'] },
      value: 'green',
      errors: [],
      read_only: false,
      locale: 'en',
      presence: 'required',
      onChange: () => undefined,
    } satisfies FieldInputProps,
    host: {
      namespace: 'notes',
      texts: {
        'notes.colour.yellow': 'Yellow',
        'notes.colour.green': 'Green',
        'notes.colour.blue': 'Blue',
      },
    },
  });

  expect(rendered.container.querySelector('#command-colour')).not.toBeNull();
  expect(rendered.container.textContent).toContain('Green');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
