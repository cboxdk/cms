<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use InvalidArgumentException;

/**
 * The name of a panel theme in cbox-cms.panel.themes (PRD 13.4): `app`, the application's own theme
 * in cbox-cms.panel.app_theme, or `<namespace>:<name>`, a theme an addon's manifest ships under
 * the name in its namespace, such as `fixtureaddon:brand`.
 */
#[Experimental]
final readonly class ThemeName
{
    /** The name of the application's own theme. */
    public const string APP = 'app';

    public string $value;

    /**
     * @throws InvalidArgumentException when the text is not `app` or `<namespace>:<name>`
     */
    public function __construct(string $value)
    {
        if ($value !== self::APP) {
            $parts = explode(':', $value);

            if (count($parts) !== 2
                || preg_match(AddonNamespace::PATTERN, $parts[0]) !== 1
                || in_array($parts[0], AddonNamespace::RESERVED, true)
                || preg_match(PanelContributions::THEME_NAME_PATTERN, $parts[1]) !== 1
                || strlen($parts[1]) > PanelContributions::THEME_NAME_MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf('"%s" is not the name of a panel theme: app, the application\'s own, or <namespace>:<name> of an addon\'s, such as "fixtureaddon:brand".', $value));
            }
        }

        $this->value = $value;
    }

    public static function app(): self
    {
        return new self(self::APP);
    }

    /**
     * The theme an addon's manifest ships under the local name.
     */
    public static function ofAddon(AddonNamespace $namespace, string $name): self
    {
        return new self($namespace->value.':'.$name);
    }

    public function isApp(): bool
    {
        return $this->value === self::APP;
    }

    /**
     * The namespace of the addon that ships the theme, or null for the application's.
     */
    public function addon(): ?string
    {
        return $this->isApp() ? null : explode(':', $this->value)[0];
    }

    /**
     * The name the addon's manifest ships the theme under, or null for the application's.
     */
    public function local(): ?string
    {
        return $this->isApp() ? null : explode(':', $this->value)[1];
    }
}
