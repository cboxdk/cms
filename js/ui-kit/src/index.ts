// The public entry of the component kit. The panel and addons import components from here, and the
// stylesheets, in this order, from "@cboxdk/cms-ui-kit/layers.css" (the order of the cascade
// layers), "@cboxdk/cms-ui-kit/tokens.css" (the design tokens) and "@cboxdk/cms-ui-kit/base.css"
// (the document styles). React Aria, whose primitives the kit builds on, is never exported: every
// component has props of its own, so the primitives can change without breaking a caller.
//
// Every export carries a stability tag, @stable or @experimental, in its TSDoc; the components of
// block B1 are experimental (decision D4 of the panel extension architecture). The API report,
// js/ui-kit/api/cms-ui-kit.api.md, records what is exported, and a test of gate 5 fails when it
// changes without the report being written anew (`npm run api:report`).

// Layout
export { AppShell } from './components/AppShell';
export type { AppShellProps } from './components/AppShell';
export { Page } from './components/Page';
export type { PageProps } from './components/Page';
export { PageHeader } from './components/PageHeader';
export type { PageHeaderProps } from './components/PageHeader';
export { Card } from './components/Card';
export type { CardProps } from './components/Card';
export { Section } from './components/Section';
export type { SectionProps } from './components/Section';
export { Stack } from './components/Stack';
export type { Gap, StackProps } from './components/Stack';
export { Brand } from './components/Brand';
export type { BrandLogo, BrandProps } from './components/Brand';
export { ShellHeader } from './components/ShellHeader';
export type { ShellHeaderProps } from './components/ShellHeader';
export { Inline } from './components/Inline';
export type { InlineProps } from './components/Inline';

// Navigation
export { SideNav } from './components/SideNav';
export type { SideNavGroup, SideNavItem, SideNavProps } from './components/SideNav';
export { Breadcrumbs } from './components/Breadcrumbs';
export type { BreadcrumbItem, BreadcrumbsProps } from './components/Breadcrumbs';
export { Tabs } from './components/Tabs';
export type { TabSpec, TabsProps } from './components/Tabs';
export { Pagination } from './components/Pagination';
export type { PaginationProps } from './components/Pagination';
export { TextLink } from './components/TextLink';
export type { TextLinkProps } from './components/TextLink';
export { SkipLink } from './components/SkipLink';
export type { SkipLinkProps } from './components/SkipLink';
export { KitRouterProvider } from './components/KitRouterProvider';
export type { KitRouterProviderProps } from './components/KitRouterProvider';

// Actions
export { Button } from './components/Button';
export type { ButtonProps, ButtonVariant } from './components/Button';
export { IconButton } from './components/IconButton';
export type { IconButtonProps } from './components/IconButton';
export { ActionBar } from './components/ActionBar';
export type { ActionBarAction, ActionBarProps } from './components/ActionBar';
export { Menu } from './components/Menu';
export type { MenuItemSpec, MenuProps, MenuTriggerSpec } from './components/Menu';
export { KeyboardShortcut } from './components/KeyboardShortcut';
export type { KeyboardShortcutProps, ShortcutKey } from './components/KeyboardShortcut';
export { Icon } from './components/Icon';
export type { IconName, IconProps, IconSize } from './components/Icon';

// Forms
export { Form } from './components/Form';
export type { FormProps } from './components/Form';
export { Field } from './components/Field';
export type { FieldControlProps, FieldProps } from './components/Field';
export { TextInput } from './components/TextInput';
export type { TextInputProps, TextInputType } from './components/TextInput';
export { PasswordInput } from './components/PasswordInput';
export type { PasswordInputProps } from './components/PasswordInput';
export { TextArea } from './components/TextArea';
export type { TextAreaProps } from './components/TextArea';
export { NumberInput } from './components/NumberInput';
export type { NumberInputProps } from './components/NumberInput';
export { Checkbox } from './components/Checkbox';
export type { CheckboxProps } from './components/Checkbox';
export { Switch } from './components/Switch';
export type { SwitchProps } from './components/Switch';
export { RadioGroup } from './components/RadioGroup';
export type { RadioGroupProps, RadioOption } from './components/RadioGroup';
export { Select } from './components/Select';
export type { SelectOption, SelectProps } from './components/Select';
export { Combobox } from './components/Combobox';
export type { ComboboxOption, ComboboxProps } from './components/Combobox';
export { MultiSelect } from './components/MultiSelect';
export type { MultiSelectOption, MultiSelectProps } from './components/MultiSelect';
export { Fieldset } from './components/Fieldset';
export type { FieldsetProps } from './components/Fieldset';
export { FieldError } from './components/FieldError';
export type { FieldErrorProps } from './components/FieldError';
export { ErrorSummary } from './components/ErrorSummary';
export type { ErrorSummaryItem, ErrorSummaryProps } from './components/ErrorSummary';
export { FormActions } from './components/FormActions';
export type { FormActionsProps } from './components/FormActions';
export { JsonEditor } from './components/JsonEditor';
export type { JsonEditorProps } from './components/JsonEditor';

// Feedback
export { Callout } from './components/Callout';
export type { CalloutProps, CalloutTone } from './components/Callout';
export { ToastRegion, createToastQueue } from './components/ToastRegion';
export type {
  KitToast,
  KitToastOptions,
  KitToastQueue,
  ToastRegionProps,
} from './components/ToastRegion';
export { Badge } from './components/Badge';
export type { BadgeProps } from './components/Badge';
export { ProgressLabel } from './components/ProgressLabel';
export type { ProgressLabelProps } from './components/ProgressLabel';
export { Skeleton } from './components/Skeleton';
export type { SkeletonProps } from './components/Skeleton';
export { EmptyState } from './components/EmptyState';
export type { EmptyStateProps } from './components/EmptyState';
export { ErrorState } from './components/ErrorState';
export type { ErrorStateProps } from './components/ErrorState';
export { StatusScreen } from './components/StatusScreen';
export type { StatusScreenProps } from './components/StatusScreen';
export { TaskScreen } from './components/TaskScreen';
export type {
  TaskScreenFact,
  TaskScreenProps,
  TaskScreenShowcase,
  TaskScreenShowcaseCard,
} from './components/TaskScreen';

// Overlays
export { Dialog } from './components/Dialog';
export type { DialogProps } from './components/Dialog';
export { ConfirmDialog } from './components/ConfirmDialog';
export type { ConfirmDialogProps } from './components/ConfirmDialog';
export { Drawer } from './components/Drawer';
export type { DrawerProps } from './components/Drawer';
export { Tooltip } from './components/Tooltip';
export type { TooltipProps } from './components/Tooltip';
export { CommandPalette } from './components/CommandPalette';
export type {
  CommandPaletteItem,
  CommandPaletteProps,
  CommandPaletteSection,
} from './components/CommandPalette';
export { Wizard } from './components/Wizard';
export type { WizardProps, WizardStep } from './components/Wizard';

// Data display
export { DataTable } from './components/DataTable';
export type {
  DataTableColumn,
  DataTableProps,
  DataTableSort,
  SortDirection,
} from './components/DataTable';
export { DescriptionList } from './components/DescriptionList';
export type { DescriptionListItem, DescriptionListProps } from './components/DescriptionList';
export { Tree } from './components/Tree';
export type { TreeNode, TreeProps } from './components/Tree';
export { Tag } from './components/Tag';
export type { TagProps } from './components/Tag';
export { Timestamp } from './components/Timestamp';
export type { TimestampProps } from './components/Timestamp';

// Domain
export { ReceiptStatus } from './components/ReceiptStatus';
export type {
  ReceiptOutcome,
  ReceiptStatusProps,
  ReceiptSummary,
  ReceiptWaitLevel,
} from './components/ReceiptStatus';
export { DryRunReport } from './components/DryRunReport';
export type { DryRunReportProps, DryRunSummary } from './components/DryRunReport';
export { ProblemDetails } from './components/ProblemDetails';
export type { ProblemDetailsProps, ProblemSummary } from './components/ProblemDetails';
export { ActorChip } from './components/ActorChip';
export type { ActorChipProps, ActorClass, ActorState } from './components/ActorChip';
export { NodePath } from './components/NodePath';
export type { NodePathProps } from './components/NodePath';
export { ClassificationBadge } from './components/ClassificationBadge';
export type { Classification, ClassificationBadgeProps } from './components/ClassificationBadge';
export { NodePicker } from './components/NodePicker';
export type { NodePickerProps } from './components/NodePicker';
export { ActorPicker } from './components/ActorPicker';
export type { ActorPickerProps, PickerActor } from './components/ActorPicker';
export { RolePicker } from './components/RolePicker';
export type { PickerRole, RolePickerProps } from './components/RolePicker';
export type { OptionItem } from './components/option';
export type { Tone } from './components/tone';

// Texts and tokens
export {
  DEFAULT_KIT_LOCALE,
  isKitLocale,
  KIT_LOCALES,
  KitI18nProvider,
} from './i18n/KitI18nProvider';
export type { KitI18nProviderProps, KitLocale } from './i18n/KitI18nProvider';
export type { PartName, ThemeTokenName, TokenName } from './generated/tokens';
