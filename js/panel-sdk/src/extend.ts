// @cboxdk/cms-panel/extend: the stable panel API for an addon's UI (PRD 13.4, sections 3 and 6 of
// the panel extension architecture): definePanelAddon() to register the addon's contributions,
// usePanelHost() to reach the panel, the types of each kind of contribution, the JSON values they
// exchange, the kernel contracts the host answers with, and the props of the stable points. It
// follows the panel API's version: a minor version only adds. Experimental API is exported only from
// @cboxdk/cms-panel/experimental, and internal API not at all.

export { definePanelAddon, InvalidPanelAddon } from './addon';
export type { ContributionMap, PanelAddon } from './addon';
export type {
  BadgeDescriptor,
  CheckContext,
  ContributionImplementation,
  DataState,
  Decoration,
  Decorator,
  FlowStep,
  FormCheck,
  Issue,
  IssuedCommands,
  IssueSeverity,
  Lazy,
  NoCommands,
  NoContributions,
  Observer,
  PageComponent,
  PageProps,
  Provider,
  ProviderProps,
  Replacement,
  SlotComponent,
  SlotProps,
  StepProps,
  TighterTone,
  Tightening,
} from './contributions';
export { PanelHostMissing, usePanelHost } from './host';
export type {
  CommandAnswer,
  CommandOptions,
  DialogRequest,
  Notice,
  NoticeTone,
  PanelHost,
  TranslationKey,
  TranslationParameters,
} from './host';
export type { JsonArray, JsonObject, JsonValue } from './json';
export { PANEL_API_VERSION } from './version';
export type { PanelApiVersion } from './version';
export * from './generated/stable';
