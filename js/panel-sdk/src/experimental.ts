// @cboxdk/cms-panel/experimental: the experimental API (section 6 of the panel extension
// architecture), which may change in a minor version of the panel's API: the props of the
// experimental points, the kit's experimental components, and the experimental kinds of
// contribution. Importing it is half of an addon's opt-in to an experimental point; the other half
// is the point in its manifest's acceptsExperimental. Every point and component of block B1 is
// experimental (decision D4).

export type { ActionContext, ActionHandler, AsyncFormCheck } from './experimental/contributions';
export * from './kit/experimental';
export * from './generated/experimental';
