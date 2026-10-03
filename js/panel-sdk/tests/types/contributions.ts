// A map of contributions as `cms:panel:types` writes one, for the type tests of
// definePanelAddon(): a slot fill with a data query, a slot fill without one, a form check and a
// decorator, on props and documents of their own.

import type { Decorator, FormCheck, Lazy, SlotComponent } from '@cboxdk/cms-panel/extend';

export interface NoteCardV1 {
  readonly owner: string;
  readonly title: string;
}

export interface NotesPendingResultV1 {
  readonly count: number;
}

export interface NoteCreateV1 {
  readonly title: string;
}

export interface Contributions {
  readonly 'reviews.badge': Lazy<SlotComponent<NoteCardV1, NotesPendingResultV1>>;
  readonly 'reviews.summary': Lazy<SlotComponent<NoteCardV1>>;
  readonly 'reviews.title-check': FormCheck<NoteCreateV1>;
  readonly 'reviews.submit': Decorator<NoteCardV1, 'disabled_reason'>;
}
