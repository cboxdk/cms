// The type test of definePanelAddon() (section 3.1 of the panel extension architecture), checked
// by `npm run typecheck`: a registration with exactly the keys of Contributions, each with the
// props its kind gives, compiles, and each line after a @ts-expect-error must fail, or tsc reports
// the directive as unused. js/panel-sdk/tests/define-panel-addon.test.ts compiles the same cases
// and names the error each one gives.

import { definePanelAddon, type SlotProps } from '@cboxdk/cms-panel/extend';

import type { Contributions, NoteCardV1, NotesPendingResultV1 } from './contributions';

function Badge(input: SlotProps<NoteCardV1, NotesPendingResultV1>): string {
  return input.data.status === 'ready' ? String(input.data.value.count) : input.props.title;
}

function Summary(input: SlotProps<NoteCardV1>): string {
  return input.props.owner;
}

function WrongProps(input: { readonly props: { readonly title: number } }): string {
  return String(input.props.title);
}

const complete = {
  'reviews.badge': () => Promise.resolve({ default: Badge }),
  'reviews.summary': () => Promise.resolve({ default: Summary }),
  'reviews.title-check': () => [],
  'reviews.submit': () => ({ tighten: { disabled_reason: 'reviews.locked' } }),
} as const;

export const registered = definePanelAddon<Contributions>(complete);

// A missing key.
// @ts-expect-error The registration lacks reviews.submit.
export const missing = definePanelAddon<Contributions>({
  'reviews.badge': complete['reviews.badge'],
  'reviews.summary': complete['reviews.summary'],
  'reviews.title-check': complete['reviews.title-check'],
});

export const extra = definePanelAddon<Contributions>({
  ...complete,
  // @ts-expect-error The manifest declares no reviews.unknown.
  'reviews.unknown': () => [],
});

export const wrongProps = definePanelAddon<Contributions>({
  ...complete,
  // @ts-expect-error The component takes a number as the title, which the point does not give.
  'reviews.summary': () => Promise.resolve({ default: WrongProps }),
});

export const wrongTightening = definePanelAddon<Contributions>({
  ...complete,
  // @ts-expect-error The manifest lets the decorator tighten only the reason it is disabled.
  'reviews.submit': () => ({ tighten: { description: 'reviews.note' } }),
});
