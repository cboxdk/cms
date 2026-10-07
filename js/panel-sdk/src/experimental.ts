// @cboxdk/cms-panel/experimental: the experimental API (section 6 of the panel extension
// architecture), which may change in a minor version of the panel's API: the props of the
// experimental points, the kit's experimental components, and the experimental kinds of
// contribution. Importing it is half of an addon's opt-in to an experimental point; the other half
// is the point in its manifest's acceptsExperimental. Every point and component of block B1 is
// experimental (decision D4).

export type { ActionContext, ActionHandler, AsyncFormCheck } from './experimental/contributions';
export type { FieldInput, FieldInputData, FieldInputProps } from './experimental/field-input';
// A JSON document of another contract a point's props hold, as that contract's codec writes it,
// such as the receipt of command.form.receipt@1 or the JSON Schema node of command.form.field@1:
// an object whose keys the contract's own validator checks.
export type { JsonObject as JsonDocument } from './generated/validation';
export * from './kit/experimental';
export * from './generated/experimental';
