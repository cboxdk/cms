// The core's own contributions to the panel's points (PRD 13.4), in the namespace cms: what the
// panel's pages give their own points, registered here as an addon registers its code, so the host
// renders them through the same order, boundaries and overrides as an addon's, and holds this
// registration to cms:build's list of the core's contributions
// (Cbox\Cms\Panel\Contributions\Domain\CoreContributions) as it holds an addon's bundle to its
// manifest. A page that renders a point adds the core's contribution to it here and there.
//
// The generic command form's pickers (core/pickers.tsx): the replacements at command.form.field@1
// of every member a command binds to NodeId, ActorId or RoleId, each reading the kernel's list as
// its data.

import {
  definePanelAddon,
  type JsonValue,
  type Lazy,
  type Replacement,
} from '@cboxdk/cms-panel/extend';
import type { FieldInputProps } from '@cboxdk/cms-panel/experimental';

/** A picker: a replacement of a field's input with the kernel's list, a JSON document, as its data. */
type Picker = Lazy<Replacement<FieldInputProps, JsonValue>>;

/** The core's contributions that run code, by id, as CoreContributions lists them. */
export interface CoreContributionMap {
  readonly 'cms.node-picker': Picker;
  readonly 'cms.actor-picker': Picker;
  readonly 'cms.role-picker': Picker;
}

/** The registration of the core's own contributions, part of the panel's build. */
export const CORE_CONTRIBUTIONS = definePanelAddon<CoreContributionMap>({
  'cms.node-picker': () => import('./core/pickers'),
  'cms.actor-picker': () =>
    import('./core/pickers').then((module) => ({ default: module.ActorPickerInput })),
  'cms.role-picker': () =>
    import('./core/pickers').then((module) => ({ default: module.RolePickerInput })),
});
