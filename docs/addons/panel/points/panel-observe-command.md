---
title: "panel.observe.command@1"
weight: 44
description: "The observer of the commands a page ran as the viewer: told after each command answered, with how it ended, and unable to change the answer."
---

# panel.observe.command@1

<!-- extension-point: Cbox\Cms\Panel\Shell\Domain\Dto\CommandCompletedV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/panel.observe.command.v1.json -->

Told of every command a page ran as the viewer, after it answered: the generic command form's, the roles and grants pages', and an action's alike.

| | |
|---|---|
| Kind | [observer](../kinds/observer.md) |
| Page | `shell`, so it is active on every page behind the login |
| Props | `CommandCompletedV1`: the `command` and its contract `version`, the `outcome` its receipt says, `rejected`, `committed`, `committed_wait_timeout` or `dry_run`, and the `changeset` it committed, or null for a rejection or a dry run |
| TypeScript | `CommandCompletedV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.activity`, which records the last command in the browser's session storage for its section of the who-am-I page |

A contribution is an `ObserverContribution` and a function in the addon's bundle. The event exists only in the browser, so the page builds it from the receipt, and the point's schema describes what the host builds. The host calls each observer with a frozen copy, in render order; one that throws is reported with its addon and the others still run.

## Example

The fixture addon's observer:

<!-- example: examples/Vitest/Panel/Points/panel-observe-command.test.ts -->
```ts
// @vitest-environment jsdom

// An observer contribution to panel.observe.command@1, told of every command a page ran as the
// viewer: the fixture addon's fixtureaddon.activity is a function of the event that gives nothing
// back and does not throw, as the host needs, and records the command in the browser's session
// storage for the addon's section of the who-am-I page. The event is the sample of the point's JSON
// Schema; the host builds it in the browser from the command's receipt.

import type { CommandCompletedV1 } from '@cboxdk/cms-panel/experimental';
import { expectObserverContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import { readActivity } from '../../../../workbench/addons/fixtureaddon/resources/panel/src/activity';
import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const event: CommandCompletedV1 = {
  changeset: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01',
  command: 'entry.create',
  outcome: 'committed',
  version: 1,
};

test('fixtureaddon.activity keeps the observer contract and records the command', () => {
  window.sessionStorage.clear();

  expectObserverContract({ addon, id: 'fixtureaddon.activity', events: [event] });

  expect(readActivity()).toEqual({
    command: 'entry.create',
    version: 1,
    outcome: 'committed',
    changeset: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01',
  });
});
```
