// An observer contribution: the addon acme/cms-notes is told of each event of an observer point
// after it happened, here every command a page ran as the viewer. An observer is a function of a
// frozen copy of the event that gives nothing back; it cannot change what happened, and one that
// throws is reported in its addon's name while the others still run. This one counts the commits
// of the addon's own commands in memory. expectObserverContract() calls it as the host does.

import { definePanelAddon, type Observer } from '@cboxdk/cms-panel/extend';
import { expectObserverContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface CommandEvent {
  readonly command: string;
  readonly version: number;
  readonly outcome: string;
  readonly changeset: string | null;
}

const committed: string[] = [];

/** Remembers each committed command of the addon. */
const countCommits: Observer<CommandEvent> = (event) => {
  if (event.outcome === 'committed' && event.command.startsWith('notes.')) {
    committed.push(event.command);
  }
};

const addon = definePanelAddon({ 'notes.commits': countCommits });

test('notes.commits keeps the observer contract and counts only the addon s commits', () => {
  expectObserverContract({
    addon,
    id: 'notes.commits',
    events: [
      {
        command: 'notes.create',
        version: 1,
        outcome: 'committed',
        changeset: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41',
      },
      { command: 'notes.create', version: 1, outcome: 'rejected', changeset: null },
      {
        command: 'entry.create',
        version: 1,
        outcome: 'committed',
        changeset: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42',
      },
    ],
  });

  expect(committed).toEqual(['notes.create']);
});
