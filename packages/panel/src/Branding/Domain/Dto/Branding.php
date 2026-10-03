<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Branding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The installation's brand (PRD 13.4, Sylvester, 2 October 2026): the product name, the logo of
 * the shell's header, the brand image of the login page and the favicon the application sets in
 * cbox-cms.panel.branding, each optional. Without a name the panel shows DEFAULT_NAME. It is the
 * installation's alone: no theme or addon contributes to it.
 */
#[Internal]
final readonly class Branding
{
    /** The name the panel shows without branding. */
    public const string DEFAULT_NAME = 'Cbox CMS';

    /** The longest product name. */
    public const int NAME_MAX_LENGTH = 60;

    /** The longest alternative text of a brand image. */
    public const int ALT_MAX_LENGTH = 150;

    public function __construct(
        public ?string $name = null,
        public ?BrandImage $logo = null,
        public ?BrandImage $login = null,
        public ?BrandFile $favicon = null,
    ) {}

    /**
     * The name the panel shows: the application's, or Cbox CMS.
     */
    public function name(): string
    {
        return $this->name ?? self::DEFAULT_NAME;
    }

    /**
     * The image the login page shows: its own, or else the logo.
     */
    public function loginImage(): ?BrandImage
    {
        return $this->login ?? $this->logo;
    }

    /**
     * The brand file the panel serves under the name, or null.
     */
    public function file(string $name): ?BrandFile
    {
        foreach ([$this->logo?->light, $this->logo?->dark, $this->login?->light, $this->login?->dark, $this->favicon] as $file) {
            if ($file instanceof BrandFile && $file->name === $name) {
                return $file;
            }
        }

        return null;
    }
}
