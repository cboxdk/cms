---
title: Kinds of contribution
weight: 20
description: "The kinds of panel point and of contribution: what each gives the host, which run code, the order contributions render in, and what happens when several addons contribute to one point."
---

# Kinds of contribution

Every panel point has a kind, a case of `Cbox\Cms\Contracts\PanelPoints\PointKind`, and a contribution to it is of the same kind: `cms:build` refuses a contribution of another kind with `registry_panel_kind_mismatch`. The kind says what a contribution gives the host and what the host does with it. Each kind has its class in the manifest, its type in the SDK and its conformance helper in `@cboxdk/cms-panel/testing`.

| Kind | Manifest class | Runs code | SDK type | Conformance helper |
|---|---|---|---|---|
| [slot](slot.md) | `SlotFill` | yes | `SlotComponent<P, D>`, or a descriptor in a structured region | `expectSlotContract` |
| [action](action.md) | `ActionContribution` | no | none | none; `PanelContributionsContract` checks the manifest |
| [nav](nav.md) | `NavContribution` | no | none | none |
| [page](page.md) | `PageContribution` | yes | `PageComponent<D>` | `expectPageContract` |
| [decorator](decorator.md) | `DecoratorContribution` | yes | `Decorator<P, T>` | `expectDecoratorKeepsDefault` |
| [replacement](replacement.md) | `ReplacementContribution` | yes | `Replacement<P, D>` | `expectReplacementContract` |
| [form check](form-check.md) | `FormCheck` | yes | `FormCheck<D>` | `expectFormCheckContract`, `checkParity` |
| [flow step](flow-step.md) | `FlowStep` | yes | `FlowStep<D, Path, I>` | `expectFlowStepContract` |
| [observer](observer.md) | `ObserverContribution` | yes | `Observer<P>` | `expectObserverContract` |
| [provider](provider.md) | `ProviderContribution` | yes | `Provider<P>` | `expectProviderContract` |
| [theme](theme.md) | the manifest's `themes` | no | none | `cms:panel:theme:check` |
| [data](data.md) | `LoginNotice` | no | none | none |

Every contribution has an id `<namespace>.<local>` in its addon's namespace, the point it contributes to as `<name>@<version>`, a priority and a `Scope`. [Panel contributions](../contributions.md#the-kinds-of-contribution) lists every argument of each class.

## Order

On every point, contributions render by priority, the lowest first, then by the addon's namespace, then by the contribution's id, as hooks run. The core's own contributions, in the namespace `cms`, are at 100, 200 and so on, and an addon's default is 1000, so it follows the core unless its manifest asks otherwise. The installation can change any priority, or disable a contribution, in `cbox-cms.panel.contributions`, and turn off a contribution or a whole addon's panel UI at run time in `cbox-cms.panel.disabled` ([order, choices and the kill switch](../contributions.md#order-choices-and-the-kill-switch)).

## Several addons on one point

| Kind | When several contribute |
|---|---|
| slot | all render in order, up to the point's maximum; past it, toolbar buttons overflow into a menu |
| action, nav | all, in order |
| page | each at its own path below `/x/<namespace>/`, so two cannot collide |
| decorator | all, the first outermost; what they tighten combines most restrictively |
| replacement | one wins per key; two claims fail the build unless `cbox-cms.panel.replacements` names the winner |
| form check | all run; the issues are sorted by path, then addon |
| flow step | in order; the first cancel stops the flow and names its addon |
| observer | all, in order; one that throws does not stop the others |
| theme | in the order `cbox-cms.panel.themes` gives, the last value winning |
| data | all, in order |

## Scaffolding

`cms:make:panel <fill|action|check|step> <namespace> <id>` scaffolds a slot fill, an action, a form check or a flow step: the stub of its kind and a test on its conformance helper, with sample props from the point's schema ([testing and scaffolding](../testing.md#scaffolding)). The examples on these pages have that shape.
