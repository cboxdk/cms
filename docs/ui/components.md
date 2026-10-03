---
title: Components
weight: 37
description: Every component of the kit, by group, with what it is for.
---

# Components

The components of the kit, `js/ui-kit`, by group. Each one is experimental in block B1, has its stories in the light and the dark theme, in forced colours and in Danish, and has a docs page in the kit's Storybook (`npm run storybook`) with its keyboard contract and its props.

## Layout

- `AppShell`: the frame of every page, with the top bar, the navigation and the main landmark, and a skip link.
- `Page` and `PageHeader`: the content of a page and its top, with the page's one heading.
- `Card` and `Section`: a panel on the raised surface, and a part of a page with a heading.
- `Stack` and `Inline`: children one below the other, and side by side, with the kit's spacing.
- `Brand` and `ShellHeader`: the installation's logo and product name, and the shell's header with the brand at the start and its actions at the end.

## Navigation

- `SideNav`: the panel's navigation, with the current page marked.
- `Breadcrumbs`: where a page sits.
- `Tabs`: views of one thing, one shown at a time.
- `Pagination`: the previous and next buttons of a list read in keyset pages.
- `TextLink` and `SkipLink`: a link, and the first link of a page, to its content.
- `KitRouterProvider`: lets the kit's links go through the application's router.

## Actions

- `Button` and `IconButton`: a button with text, and one with an icon and a required label.
- `ActionBar`: the actions of a page in a toolbar, with an overflow menu.
- `Menu`: a button that opens a list of actions.
- `KeyboardShortcut`: the keys of a shortcut, written for the reader's system.
- `Icon`: the kit's icons, which are decoration only.

## Forms

- `Form`, `FormActions` and `Fieldset`: a form, its row of buttons, and a group of fields.
- `Field` and `FieldError`: the frame of a field and the error of a control the kit has no component for.
- `TextInput`, `PasswordInput`, `TextArea` and `NumberInput`: text, a password with a reveal button, text of several lines, and a number in the page's locale.
- `Checkbox`, `Switch` and `RadioGroup`: a yes or no, a setting that takes effect at once, and a choice of one of a few.
- `Select`, `Combobox` and `MultiSelect`: a choice from a list, a choice found by typing, and a choice of several.
- `ErrorSummary`: the errors of a refused form, each a link to its field.
- `JsonEditor`: a JSON value, checked as it is typed.

The form that is rendered from a command's JSON Schema comes with the generic command form (B1-T15).

## Feedback

- `Callout`: a message about the page's state, in a tone.
- `ToastRegion` with `createToastQueue`: short messages about something that happened.
- `Badge`: a short label of a state.
- `ProgressLabel` and `Skeleton`: a wait that says what it waits for, and the shape of what loads.
- `EmptyState` and `ErrorState`: what a list or a page shows when it is empty, and when it failed.
- `StatusScreen` and `TaskScreen`: a page about the panel's state, and a page for one task such as signing in.

## Overlays

- `Dialog`, `ConfirmDialog` and `Drawer`: a modal dialog, a question before an action that cannot simply be undone, and a modal panel at the end of the screen.
- `Tooltip`: a short hint about a control.
- `CommandPalette`: the pages and commands found by typing, opened with Ctrl+K or Command+K.
- `Wizard`: a flow of steps.

## Data display

- `DataTable`: a table of rows with sorting, row actions and keyset pages.
- `DescriptionList`: facts about one thing.
- `Tree`: a tree of nodes.
- `Tag` and `Timestamp`: a short value as a chip, and a point in time in the reader's locale.

## Domain

- `ReceiptStatus`: what a command's receipt says.
- `DryRunReport`: what a dry run found.
- `ProblemDetails`: a refusal from the kernel, with its catalog code.
- `ActorChip`, `NodePath` and `ClassificationBadge`: an actor, where a node sits, and a classification.
- `NodePicker`, `ActorPicker` and `RolePicker`: fields to choose a node, an actor and a role.
