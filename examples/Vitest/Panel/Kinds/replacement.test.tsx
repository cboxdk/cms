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
