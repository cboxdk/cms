// @vitest-environment jsdom

// The section "Panel points" of the Storybook (section 2.7 of the panel extension architecture):
// cms:panel:stories writes a story per point of panel.php, and each renders the point's facts, its
// props schema and the point's host with the contributions compiled for it. The installation's
// generated section renders, and a point of each kind renders as a page renders it.

import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, test } from 'vitest';

import * as golden from './stories-golden/generated/PanelPoints.stories';
import { Overview } from '../../stories/generated/PanelPoints.stories';
import { PANEL_POINTS } from '../../stories/generated/points';
import { overviewStory, pointStory, type PanelPointStoryData } from '../../stories/PanelPointStory';
import { fill, point } from './harness';

afterEach(() => {
  cleanup();
});

const SCHEMA = '{\n  "type": "object"\n}';

function story(
  id: string,
  data: Partial<PanelPointStoryData> & Pick<PanelPointStoryData, 'point'>,
): PanelPointStoryData {
  return {
    id,
    page: 'desk',
    label: 'panel.points.title',
    since: '1.0',
    stability: 'experimental',
    class: 'Acme\\DeskV1',
    schema: SCHEMA,
    ...data,
  };
}

const POINTS: readonly PanelPointStoryData[] = [
  story('desk.actions@1', {
    point: point(
      'desk.actions@1',
      [
        fill('acme', 'acme.request', 1000, {
          kind: 'action',
          action: {
            command: 'acme.request@1',
            confirm: 'none',
            icon: null,
            label: 'acme.request',
            prefill: [],
            tone: 'neutral',
          },
        }),
      ],
      { kind: 'action', region: null },
    ),
  }),
  story('desk.submit@1', {
    schema: null,
    point: point(
      'desk.submit@1',
      [
        fill('acme', 'acme.guard', 1000, {
          kind: 'decorator',
          decorator: { tightens: ['description'] },
        }),
      ],
      { kind: 'decorator', region: null },
    ),
  }),
  story('desk.cards@1', { point: point('desk.cards@1', [fill('acme', 'acme.card', 1000)]) }),
  story('desk.checks@1', {
    point: point('desk.checks@1', [], { kind: 'form_check', region: null }),
  }),
];

describe('the panel points section', () => {
  test('the generated section renders its overview, a card per point of the installation', () => {
    render(<>{Overview.render({}, { globals: { locale: 'en' } })}</>);

    if (PANEL_POINTS.length === 0) {
      expect(screen.getByText('No panel points yet')).toBeTruthy();
    }

    for (const point of PANEL_POINTS) {
      expect(screen.getByText((text) => text.startsWith(`${point.id} · `))).toBeTruthy();
    }
  });

  test('the overview lists every point with its facts, and the order rule', () => {
    render(<>{overviewStory(POINTS).render({}, { globals: { locale: 'da' } })}</>);

    expect(screen.getByText('desk.actions@1 · Panelpunkter')).toBeTruthy();
    expect(
      screen.getAllByText(
        'Laveste prioritet først, derefter tilføjelsens navnerum, derefter bidragets id.',
      ),
    ).toHaveLength(4);
    expect(screen.getAllByText('acme.request (1000)')).toHaveLength(1);
  });

  test('a point s story renders its schema and its host by its kind', async () => {
    render(
      <>
        {pointStory(POINTS, 'desk.actions@1').render({}, { globals: { locale: 'en' } })}
        {pointStory(POINTS, 'desk.submit@1').render({}, { globals: { locale: 'en' } })}
        {pointStory(POINTS, 'desk.cards@1').render({}, { globals: { locale: 'en' } })}
        {pointStory(POINTS, 'desk.checks@1').render({}, { globals: { locale: 'en' } })}
      </>,
    );

    expect(
      screen.getAllByText(SCHEMA.replaceAll('\n', ' ').replace(/\s+/g, ' '), {
        normalizer: (text) => text.replace(/\s+/g, ' '),
      }),
    ).toHaveLength(3);
    expect(screen.getByText('This point has no props.')).toBeTruthy();
    expect(await screen.findByRole('button', { name: 'acme.request' })).toBeTruthy();
    expect(await screen.findByRole('button', { name: 'The default' })).toBeTruthy();
    // The fixture addon serves no bundle in a story, so its slot shows the notice in its place.
    expect(await screen.findByText('acme could not be loaded')).toBeTruthy();
    expect(
      screen.getByText(
        'A page asks this point for its contributions while it works, so it renders nothing of its own here.',
      ),
    ).toBeTruthy();
    await waitFor(() => {
      expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(4);
    });
  });

  test('the golden section of cms:panel:stories renders the overview and a story per point', async () => {
    const stories = [
      golden.NotesDetailCardV1,
      golden.NotesFormSubmitV1,
      golden.NotesListToolbarV1,
      golden.NotesWiringV1,
    ];

    render(
      <>
        {golden.Overview.render({}, { globals: { locale: 'en' } })}
        {stories.map((entry) => (
          <section key={entry.name}>{entry.render({}, { globals: { locale: 'en' } })}</section>
        ))}
      </>,
    );

    expect(golden.default.title).toBe('Panel points');
    expect(screen.getAllByText('notes.list.toolbar@1 · fixture.points.notes_toolbar')).toHaveLength(
      2,
    );
    expect(await screen.findByRole('button', { name: 'approvals.request' })).toBeTruthy();
    expect(await screen.findByRole('button', { name: 'The default' })).toBeTruthy();
    expect(await screen.findByText('approvals could not be loaded')).toBeTruthy();
  });
});
