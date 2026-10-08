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
