<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An action at an action point (PRD 13.4): a button or menu item the host renders that runs one
 * command through the Inertia profile as the viewer. It is data and runs no code of the addon.
 *
 * - command: the command class it runs. It must be in the addon's AddonCapabilities::$issues
 *   and exposed on Inertia, or cms:build refuses it as registry_panel_command_not_issuable. The
 *   host hides the action from a viewer without the command's permission.
 * - label and icon: the translation key of its text in the addon's catalogue, and a kit icon.
 * - prefill: the command document's properties the action fills from the point's props, each a
 *   JSON pointer into the props, such as ['actor' => '/actor']. cms:build checks each pointer
 *   against the point's props schema and the property against the command's schema with a
 *   compatible type, as registry_panel_action_prefill_invalid.
 * - confirm: how it asks before running (Confirm), and tone: how it is shown.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class ActionContribution implements PanelContribution
{
    /** A property of a command document. */
    public const string PROPERTY_PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    /** A JSON pointer (RFC 6901) to a value inside the props, never the whole document. */
    public const string POINTER_PATTERN = '/\A(?:\/(?:[^~\/]|~[01])*)+\z/';

    public int $priority;

    public string $command;

    public string $label;

    public ?string $icon;

    /** @var array<string, string> */
    public array $prefill;

    /**
     * @param  string  $command  the command class, such as RequestAccess::class
     * @param  string  $label  the translation key of its text
     * @param  string|null  $icon  a kit icon, such as "key"
     * @param  array<string, string>  $prefill  command property => JSON pointer into the point's props
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        string $command,
        string $label,
        ?string $icon = null,
        array $prefill = [],
        public Confirm $confirm = Confirm::None,
        public Tone $tone = Tone::Neutral,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        $this->command = ContributionRules::className($id->value, 'command', $command);
        $this->label = ContributionRules::translationKey($id->value, 'label', $label);
        $this->icon = ContributionRules::icon($id->value, $icon);

        foreach ($prefill as $property => $pointer) {
            if (preg_match(self::PROPERTY_PATTERN, $property) !== 1) {
                throw InvalidAddonManifest::because(sprintf('The contribution %s prefills "%s", which is not a property of a command document: lowercase snake_case, such as "actor".', $id->value, $property));
            }

            if (preg_match(self::POINTER_PATTERN, $pointer) !== 1) {
                throw InvalidAddonManifest::because(sprintf('The contribution %s prefills %s from "%s", which is not a JSON pointer into the point\'s props, such as "/actor".', $id->value, $property, $pointer));
            }
        }

        ksort($prefill, SORT_STRING);
        $this->prefill = $prefill;
    }

    public function id(): ContributionId
    {
        return $this->id;
    }

    public function point(): string
    {
        return $this->point;
    }

    public function kind(): PointKind
    {
        return PointKind::Action;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function runsCode(): bool
    {
        return false;
    }
}
