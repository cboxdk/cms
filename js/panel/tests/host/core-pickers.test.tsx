// @vitest-environment jsdom

// The core's pickers of a command form's fields (section 8 of the panel extension architecture):
// the cms replacements at command.form.field@1 of a member bound to NodeId, ActorId or RoleId,
// each over the kit's picker with the kernel's list as its data, read as the viewer; while the list
// loads the picker says so, and when the viewer may not read it the field takes the id typed by
// hand, so no form is a dead end. The lists are read into what the kit's pickers take.

import type { FieldInputProps } from '@cboxdk/cms-panel/experimental';
import { screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test } from 'vitest';

import en from '../../src/i18n/catalogues/en.json';
import { PointHost } from '../../src/host';
import { CORE_CONTRIBUTIONS } from '../../src/host/core';
import { nodeTree, pickerActors, pickerRoles } from '../../src/host/core/lists';
import { contributions, fill, point, renderHost } from './harness';

const NODE = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01';
const CHILD = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02';
const ACTOR = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03';
const ROLE = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04';

const CLASSES = {
  node: 'Cbox\\Cms\\Contracts\\Ids\\NodeId',
  actor: 'Cbox\\Cms\\Contracts\\Ids\\ActorId',
  role: 'Cbox\\Cms\\Contracts\\Ids\\RoleId',
} as const;

/** The texts the test's own default input and fields show, which no catalogue holds. */
const OWN = {
  fallback: 'Default input',
  pick: 'The one to pick.',
  node: 'Node',
  actor: 'Actor',
  role: 'Role',
} as const;

const PICKERS = {
  node: 'cms.node-picker',
  actor: 'cms.actor-picker',
  role: 'cms.role-picker',
} as const;

function fieldProps(path: string, value: string | null = null): FieldInputProps {
  return {
    command: 'grant.assign',
    version: 1,
    path,
    id: `form-${path}`,
    label: path === 'node' ? OWN.node : path === 'actor' ? OWN.actor : OWN.role,
    description: OWN.pick,
    schema: { type: 'string' },
    value,
    errors: [],
    read_only: false,
    locale: 'en',
    presence: 'required',
    onChange: () => undefined,
  };
}

/** The field as the form holds it: the picker's value in state, changed through onChange. */
function Field({ target }: { readonly target: keyof typeof CLASSES }) {
  const [value, setValue] = useState<string | null>(null);

  return (
    <PointHost
      point="command.form.field@1"
      target={CLASSES[target]}
      props={{ ...fieldProps(target, value), onChange: setValue }}
      fallback={<p>{OWN.fallback}</p>}
    />
  );
}

function render(target: keyof typeof CLASSES, ext: unknown) {
  return renderHost(<Field target={target} />, {
    contributions: contributions(
      [
        point(
          'command.form.field@1',
          Object.entries(PICKERS).map(([key, id]) =>
            fill('cms', id, 100, {
              kind: 'replacement',
              props: null,
              data: true,
              replacement: { key: CLASSES[key as keyof typeof CLASSES] },
            }),
          ),
          { kind: 'replacement', region: null, multiplicity: 'exclusive' },
        ),
      ],
      { cms: Object.values(PICKERS) },
    ),
    registrations: { cms: CORE_CONTRIBUTIONS },
    texts: en,
    ext,
  });
}

describe('the core pickers', () => {
  test('offer the nodes of node.list as a tree, labelled by the last segment of their path', async () => {
    const { user } = render('node', {
      cms: {
        [PICKERS.node]: {
          nodes: [
            {
              id: NODE,
              kind: 'site',
              label: 'north',
              parent: null,
              site: NODE,
              site_handle: 'north',
            },
            {
              id: CHILD,
              kind: 'section',
              label: 'north/news',
              parent: NODE,
              site: NODE,
              site_handle: 'north',
            },
          ],
          next: null,
        },
      },
    });

    expect(await screen.findByRole('button', { name: /Choose/ })).toBeTruthy();
    expect(screen.queryByText(OWN.fallback)).toBeNull();
    expect(screen.getByText(/Node/)).toBeTruthy();

    await user.click(screen.getByRole('button', { name: /Choose/ }));

    expect(await screen.findByRole('dialog')).toBeTruthy();
    expect(screen.getByText('north')).toBeTruthy();
    expect(screen.queryByText(en['panel.pickers.nodes_empty'])).toBeNull();
  });

  test('say what they wait for while the list loads, in the list', async () => {
    const { user } = render('actor', undefined);

    await user.type(await screen.findByRole('combobox', { name: /Actor/ }), 'n');

    expect(await screen.findByText(en['panel.pickers.actors_loading'])).toBeTruthy();
    expect(screen.queryByText(OWN.fallback)).toBeNull();
  });

  test('take the id typed by hand when the viewer may not read the list', async () => {
    const { user } = render('role', { cms: {} });

    const input = await screen.findByLabelText(/Role/);
    await user.type(input, ROLE);
    expect(input).toHaveProperty('value', ROLE);
    expect(screen.getByText(/Give the id/)).toBeTruthy();
  });

  test('read the lists into what the pickers take', () => {
    expect(
      nodeTree({
        nodes: [
          { id: NODE, label: 'north', parent: null },
          { id: CHILD, label: 'north/news', parent: NODE },
          { id: ROLE, label: 'orphan/leaf', parent: ACTOR },
        ],
        next: null,
      }),
    ).toEqual([
      { id: NODE, label: 'north', children: [{ id: CHILD, label: 'news' }] },
      { id: ROLE, label: 'leaf' },
    ]);
    expect(
      pickerActors({
        actors: [
          {
            id: ACTOR,
            profile: { display_name: 'Nina Bruun', email: 'nina@example.com' },
            state: 'active',
            version: 1,
          },
          { id: NODE, profile: null, state: 'active', version: 1 },
        ],
        next: null,
      }),
    ).toEqual([
      { id: ACTOR, name: 'Nina Bruun', email: 'nina@example.com' },
      { id: NODE, name: NODE },
    ]);
    expect(
      pickerRoles({
        roles: [{ id: ROLE, handle: 'editor', ceiling: 'internal', permissions: [], version: 1 }],
        next: null,
      }),
    ).toEqual([{ id: ROLE, handle: 'editor', description: 'internal' }]);
    expect(pickerRoles('not a list')).toEqual([]);
  });
});
