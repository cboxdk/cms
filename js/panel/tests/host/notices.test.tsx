// @vitest-environment jsdom

// The host of a data point's notices (section 3.11 of the panel extension architecture): the
// login page holds the notices the server resolved for login.notice@1 and hands them to the host,
// which renders each as a callout in the order given, in the scope element of its contribution,
// with the key of its message shown as the addon's text, and loads no addon: a credential page
// has no contributions prop. A neutral tone is shown as information.

import { screen } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { PointHost } from '../../src/host';
import { contributions, renderHost } from './harness';

describe('the notices host', () => {
  test('renders the notices the page holds in their order, each in its contribution scope, and reports nothing', () => {
    const { container, recorded } = renderHost(
      <PointHost
        point="login.notice@1"
        notices={[
          {
            addon: 'approvals',
            id: 'approvals.maintenance',
            message: 'approvals.maintenance.message',
            tone: 'warning',
          },
          { addon: 'desk', id: 'desk.welcome', message: 'desk.welcome.message', tone: 'neutral' },
        ]}
      />,
      { contributions: contributions([], {}), registrations: {} },
    );

    const scopes = [...container.querySelectorAll('[data-cms-point="login.notice@1"]')];

    expect(scopes.map((scope) => scope.getAttribute('data-cms-contribution'))).toEqual([
      'approvals.maintenance',
      'desk.welcome',
    ]);
    expect(scopes.map((scope) => scope.getAttribute('data-cms-addon'))).toEqual([
      'approvals',
      'desk',
    ]);
    expect(
      [...container.querySelectorAll('[data-tone]')].map((callout) =>
        callout.getAttribute('data-tone'),
      ),
    ).toEqual(['warning', 'info']);
    expect(screen.getByText('approvals.maintenance.message')).toBeTruthy();
    expect(screen.getByText('desk.welcome.message')).toBeTruthy();
    expect(recorded.reports).toEqual([]);
  });

  test('renders nothing for a page without notices', () => {
    const { container } = renderHost(<PointHost point="login.notice@1" notices={[]} />, {
      contributions: contributions([], {}),
      registrations: {},
    });

    expect(container.querySelectorAll('[data-cms-point]')).toHaveLength(0);
  });
});
