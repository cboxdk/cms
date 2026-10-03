// A test-only addon module (PRD 13.4): a component with state, rendered by the probe host, and the
// internals of the React it imported, which the host compares with those of the panel's renderer.
// Its `react` is the panel's shared module, through the page's import map.

import * as React from 'react';
import { createElement, useId, useState, type ReactElement } from 'react';

/** The name React gives the object a renderer reads its hooks' dispatcher from. */
const INTERNALS = '__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE';

export const reactInternals: unknown = (React as unknown as Record<string, unknown>)[INTERNALS];

export default function Counter(): ReactElement {
  const [count, setCount] = useState(0);
  const id = useId();

  return createElement(
    'button',
    {
      id: 'probe-counter',
      type: 'button',
      'data-react-id': id,
      onClick: () => {
        setCount((current) => current + 1);
      },
    },
    `count ${String(count)}`,
  );
}
