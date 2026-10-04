// @cboxdk/cms-panel/testing: what an addon's tests render and check its contributions with
// (section 7 of the panel extension architecture). A contribution reaches the panel only through
// usePanelHost(), so a test renders it inside PanelHostProvider with a host it builds, or with the
// fake host, which does what the panel's host does and records what it was asked. renderPoint()
// and the renderers per kind render a contribution the way the host renders it; the conformance
// helpers per kind fail on what the host would refuse; checkParity() holds a mirrored form check
// to its hook; expectNoA11yViolations() runs axe; and expectRegistration() holds a registration
// to the manifest's ids.

export { A11yViolations, expectNoA11yViolations, WCAG_22_AA } from './testing/a11y';
export type { A11yOptions, A11yViolation } from './testing/a11y';
export {
  CHECK_BUDGET_MILLISECONDS,
  expectDecoratorKeepsDefault,
  expectFlowStepContract,
  expectFormCheckContract,
  expectObserverContract,
  expectPageContract,
  expectProviderContract,
  expectReplacementContract,
  expectSlotContract,
} from './testing/conformance';
export type {
  DecoratorContractOptions,
  FlowStepContractOptions,
  FormCheckContractOptions,
  ObserverContractOptions,
  PageContractOptions,
  ProviderContractOptions,
  ReplacementContractOptions,
  SlotContractOptions,
} from './testing/conformance';
export { committedReceipt, dryRunReceipt, rejectedProblem } from './testing/fixtures';
export type { RejectionError } from './testing/fixtures';
export { createFakeHost, fillText, PanelCommandRefused, PanelHostProvider } from './testing/host';
export type {
  AnyIssuedCommands,
  FakeHost,
  FakeHostOptions,
  HostRecord,
  HostRefusal,
  PanelHostProviderProps,
  RecordedCommand,
  RecordedDialog,
  RecordedNotice,
} from './testing/host';
export { checkParity, ParityBroken } from './testing/parity';
export type { ParityDisagreement, ParityOptions } from './testing/parity';
export { expectRegistration, RegistrationMismatch } from './testing/registration';
export {
  ContributionContractBroken,
  renderDecorator,
  renderPage,
  renderPoint,
  renderProvider,
  renderReplacement,
  renderSlot,
  renderStep,
} from './testing/render';
export type {
  ContributionOptions,
  RenderDecoratorOptions,
  Rendered,
  RenderedDecorator,
  RenderedSlot,
  RenderedStep,
  RenderPageOptions,
  RenderPointOptions,
  RenderProviderOptions,
  RenderReplacementOptions,
  RenderSlotOptions,
  RenderStepOptions,
  SlotRegion,
  StepRecord,
  TightenedProps,
} from './testing/render';
