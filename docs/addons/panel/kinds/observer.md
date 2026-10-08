---
title: Observer
weight: 29
description: "An observer: a function the host calls with a frozen copy of a point's event after it happened, which cannot change what happened."
---

# Observer

An observer is told of an event after it happened, such as a command a page ran. It cannot affect the answer.

| | |
|---|---|
| Manifest | `new ObserverContribution($id, '<point>@<version>')` |
| Bundle | an `Observer<P>` of `@cboxdk/cms-panel/extend`, registered as the function itself |
| Receives | the point's event, frozen |
| Scaffold | none; register the function by hand |
| Test | `expectObserverContract()` |
| Points | [`panel.observe.command@1`](../points/panel-observe-command.md) |

## Rules

- The host calls every observer in order; one that throws is reported with its addon, and the others still run.
- An observer gives nothing back.

## Example

The addon `acme/cms-notes` counts the commits of its own commands:

<!-- example: examples/Vitest/Panel/Kinds/observer.test.ts -->
```ts
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
```
