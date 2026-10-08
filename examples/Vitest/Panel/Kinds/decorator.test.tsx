// @vitest-environment jsdom

// A decorator contribution: the addon acme/cms-notes decorates the submit of a command form. It is a
// function of the point's props that never receives the default, so it cannot remove it: it adds a
// badge and tightens the one prop its manifest declares, the disabled reason, while a note is
// locked. A disabled reason blocks the run, so the manifest's DecoratorContribution mirrors the
// addon's hook that refuses the same command on the server. expectDecoratorKeepsDefault() renders
// the default once with the decoration and refuses a tightening the manifest does not declare.

import { definePanelAddon, type Decorator } from '@cboxdk/cms-panel/extend';
import { expectDecoratorKeepsDefault } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface SubmitProps {
  readonly command: string;
  readonly version: number;
  readonly title: string;
}

/** Disables the run of notes.lock.release while the note is locked, as the hook does. */
const lockedNote: Decorator<SubmitProps, 'disabled_reason'> = (props) =>
  props.command === 'notes.lock.release'
    ? {
        badge: { tone: 'warning', label: 'notes.locked.badge' },
        tighten: { disabled_reason: 'notes.locked.reason' },
      }
    : {};

const addon = definePanelAddon({ 'notes.locked': lockedNote });

test('notes.locked keeps the default and tightens only the disabled reason', async () => {
  const rendered = await expectDecoratorKeepsDefault({
    addon,
    id: 'notes.locked',
    props: { command: 'notes.lock.release', version: 1, title: 'Release the lock' },
    tightens: ['disabled_reason'],
    host: {
      namespace: 'notes',
      texts: { 'notes.locked.reason': 'The note is locked by another editor.' },
    },
  });

  expect(rendered.tightened.disabled).toBe(true);
  expect(rendered.tightened.disabledReasons).toEqual(['The note is locked by another editor.']);
  expect(rendered.badges).toEqual([{ tone: 'warning', label: 'notes.locked.badge' }]);
  await rendered.unmount();
});
