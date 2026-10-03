<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Doctor\Fakes;

use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Branding\Domain\InvalidBranding;
use Cbox\Cms\Panel\Doctor\Domain\Probes\BrandingProbe;
use Override;

/**
 * The installation's brand a test gives, or the reasons it cannot be used.
 */
final readonly class FakeBrandingProbe implements BrandingProbe
{
    /**
     * @param  list<string>  $reasons  why the branding cannot be used; none for a brand that can
     */
    public function __construct(
        private Branding $branding = new Branding,
        private array $reasons = [],
    ) {}

    #[Override]
    public function branding(): Branding
    {
        return $this->reasons === [] ? $this->branding : throw new InvalidBranding($this->reasons);
    }
}
