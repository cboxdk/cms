// The type test of the subpaths' stability (section 6 of the panel extension architecture): the
// experimental API is exported only from @cboxdk/cms-panel/experimental, so importing it from
// /extend fails tsc. js/panel-sdk/tests/experimental-import.test.ts names the error.

import type { ActionHandler as Experimental } from '@cboxdk/cms-panel/experimental';
// @ts-expect-error ActionHandler is experimental: /extend does not export it.
import type { ActionHandler } from '@cboxdk/cms-panel/extend';
// @ts-expect-error The kit's components are experimental in block B1 (decision D4).
import type { ButtonProps } from '@cboxdk/cms-panel/extend';

export type Handlers = readonly [Experimental<object>, ActionHandler<object>, ButtonProps];
