// What the access pages are built from (PRD 5.10, 13.4): the ids they make for new roles and
// grants, the reads they show, the refusals they explain, the pages of a keyset list, what the
// viewer may do by the prop `palette`, and the content tree a node picker shows.

import { describe, expect, test } from 'vitest';

import { UUID7, uuid7 } from '../../src/access/ids';
import { afterOf, back, forward, pageUrl } from '../../src/access/list-pages';
import { mayRun, permissionOptions } from '../../src/access/permissions';
import { explanationOf, fieldErrorOf } from '../../src/access/problems';
import { readOf } from '../../src/access/reads';
import type { ProblemV1 } from '../../src/generated/protocol/ProblemV1';
import { validateRoleListV1 } from '../../src/generated/protocol/RoleListV1';
import { translator } from '../../src/i18n/translations';
import { nodeTree } from '../../src/pages/Access/Grants';
import type { PaletteState } from '../../src/shell/palette';

const PROBLEM: ProblemV1 = {
  code: 'validation_failed',
  detail: 'The handle is taken.',
  errors: [
    { code: 'validation_failed', detail: 'Another role has the handle editors.', field: 'handle' },
    { code: 'validation_failed', detail: 'Not a name of the registry.', field: 'permissions[1]' },
    { code: 'validation_failed', detail: 'Named twice.', field: 'command.locales[0]' },
  ],
  instance: null,
  retryable: false,
  status: 422,
  title: 'The command was rejected.',
  type: 'docs/reference/errors.md#validation_failed',
};

const PALETTE: PaletteState = {
  status: 'ready',
  list: {
    actions: [
      {
        description: 'Lists.',
        kind: 'query',
        name: 'role.list',
        title: 'role.list, contract version 1',
        version: 1,
      },
      {
        description: 'Creates a role.',
        kind: 'command',
        name: 'role.create',
        title: 'role.create, contract version 1',
        version: 1,
      },
      {
        description: 'Revises.',
        kind: 'command',
        name: 'entry.revise',
        title: 'Revise an entry',
        version: 1,
      },
    ],
    navigation: [],
  },
};

describe('uuid7', () => {
  test('makes a UUIDv7 in lowercase from the time and the random bytes', () => {
    const id = uuid7(
      0x018f_1234_5678,
      new Uint8Array([0xab, 0xcd, 0xef, 0x01, 0x23, 0x45, 0x67, 0x89, 0xab, 0xcd]),
    );

    expect(id).toBe('018f1234-5678-7bcd-af01-23456789abcd');
    expect(UUID7.test(id)).toBe(true);
  });

  test('every id of the browser is of the form the kernel takes, and no two are the same', () => {
    const ids = new Set(Array.from({ length: 50 }, () => uuid7()));

    expect(ids.size).toBe(50);

    for (const id of ids) {
      expect(id).toMatch(UUID7);
    }
  });

  test('refuses too few random bytes', () => {
    expect(() => uuid7(1, new Uint8Array(4))).toThrow(RangeError);
  });
});

describe('readOf', () => {
  const list = { roles: [], next: null };

  test('gives the result the validator accepts', () => {
    expect(readOf(list, null, validateRoleListV1)).toEqual({ status: 'ready', value: list });
  });

  test('gives the problem of a rejection', () => {
    expect(readOf(null, PROBLEM, validateRoleListV1)).toEqual({
      status: 'rejected',
      problem: PROBLEM,
    });
  });

  test('is unreadable for a result the validator refuses, a rejection that is no problem, and neither', () => {
    expect(readOf({ roles: 'none' }, null, validateRoleListV1)).toEqual({ status: 'unreadable' });
    expect(readOf(null, { code: 1 }, validateRoleListV1)).toEqual({ status: 'unreadable' });
    expect(readOf(null, null, validateRoleListV1)).toEqual({ status: 'unreadable' });
    expect(readOf(undefined, undefined, validateRoleListV1)).toEqual({ status: 'unreadable' });
  });
});

describe('problems', () => {
  const t = translator('en');

  test("explains a code the catalogue has a text for, in the panel's locale, and falls back for another", () => {
    const escalation = { ...PROBLEM, code: 'grant_escalation_refused' as const };
    const unknown = { ...PROBLEM, code: 'hook_change_refused' as const };

    expect(explanationOf('en', t, escalation, 'panel.grants.command_refused')).toBe(
      t('panel.problem.grant_escalation_refused'),
    );
    expect(
      explanationOf('da', translator('da'), escalation, 'panel.grants.command_refused'),
    ).toContain('Du kan kun give');
    expect(explanationOf('en', t, unknown, 'panel.grants.command_refused')).toBe(
      t('panel.grants.command_refused'),
    );
  });

  test("finds the error at a field by its path, below it, in its list and behind the document's name", () => {
    expect(fieldErrorOf(PROBLEM, 'handle')).toBe('Another role has the handle editors.');
    expect(fieldErrorOf(PROBLEM, 'permissions')).toBe('Not a name of the registry.');
    expect(fieldErrorOf(PROBLEM, 'locales')).toBe('Named twice.');
    expect(fieldErrorOf(PROBLEM, 'ceiling')).toBeUndefined();
    expect(fieldErrorOf(PROBLEM, 'hand')).toBeUndefined();
    expect(fieldErrorOf(null, 'handle')).toBeUndefined();
  });
});

describe('list pages', () => {
  test('reads the id a page starts after from its address, and writes the address of a page', () => {
    expect(afterOf('/cms/access/roles')).toBeNull();
    expect(afterOf('/cms/access/roles?after=')).toBeNull();
    expect(afterOf('/cms/access/roles?after=0192a0c0-0000-7000-8000-000000000331')).toBe(
      '0192a0c0-0000-7000-8000-000000000331',
    );
    expect(pageUrl('/cms/access/roles?after=x', null)).toBe('/cms/access/roles');
    expect(pageUrl('/cms/access/roles', 'abc')).toBe('/cms/access/roles?after=abc');
  });

  test('remembers the pages visited, so Previous goes back through them', () => {
    const visited = forward(forward([], null), 'a');

    expect(visited).toEqual([null, 'a']);
    expect(back(visited)).toEqual({ cursors: [null], after: 'a' });
    expect(back([null])).toEqual({ cursors: [], after: null });
    expect(back([])).toEqual({ cursors: [], after: null });
  });
});

describe('permissions', () => {
  test('says whether the list names an action, and nothing for a prop that is not ready', () => {
    expect(mayRun(PALETTE, 'role.create')).toBe(true);
    expect(mayRun(PALETTE, 'grant.assign')).toBe(false);
    expect(mayRun({ status: 'unreadable' }, 'role.create')).toBe(false);
  });

  test('offers every action of the list and the names held, each once, sorted, titled by the caller', () => {
    const options = permissionOptions(PALETTE, ['role.create', 'actor.register'], (action) =>
      action.name === 'role.create' ? 'Create a role' : action.title,
    );

    expect(options).toEqual([
      { name: 'actor.register', title: 'actor.register' },
      { name: 'entry.revise', title: 'Revise an entry' },
      { name: 'role.create', title: 'Create a role' },
      { name: 'role.list', title: 'role.list, contract version 1' },
    ]);
    expect(permissionOptions({ status: 'unreadable' }, ['a.b'])).toEqual([
      { name: 'a.b', title: 'a.b' },
    ]);
  });
});

describe('nodeTree', () => {
  test('builds the tree from the parents, names each node by the last segment of its label, and roots a node whose parent is unknown', () => {
    const tree = nodeTree([
      { id: 'root', kind: 'site', label: 'north', parent: null, site: 's', site_handle: 'north' },
      {
        id: 'news',
        kind: 'section',
        label: 'north/nyheder',
        parent: 'root',
        site: 's',
        site_handle: 'north',
      },
      {
        id: 'sport',
        kind: 'section',
        label: 'north/nyheder/sport',
        parent: 'news',
        site: 's',
        site_handle: 'north',
      },
      {
        id: 'lost',
        kind: 'list',
        label: 'north/elsewhere/list',
        parent: 'gone',
        site: 's',
        site_handle: 'north',
      },
    ]);

    expect(tree).toEqual([
      {
        id: 'root',
        label: 'north',
        children: [{ id: 'news', label: 'nyheder', children: [{ id: 'sport', label: 'sport' }] }],
      },
      { id: 'lost', label: 'list' },
    ]);
  });
});
