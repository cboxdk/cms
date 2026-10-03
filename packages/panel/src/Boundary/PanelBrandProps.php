<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Http\Inertia\Boundary\InertiaProps;
use Cbox\Cms\Panel\Boundary\Generated\PanelBrandCodecV1;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandFile;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandImage;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Domain\Dto\PanelBrand;
use Cbox\Cms\Panel\Domain\Dto\PanelBrandLogo;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * The installation's brand as the prop every page of the panel shares, brand (PRD 13.4): the name,
 * and each brand image with the addresses the panel serves its files at, written by the generated
 * PanelBrandCodecV1 (brand.v1.json), and the address of a brand file for the root view, such as
 * the favicon's.
 */
#[Internal]
final readonly class PanelBrandProps
{
    public const string PROP = 'brand';

    public function __construct(
        private Branding $branding,
        private UrlGenerator $urls,
        private PanelBrandCodecV1 $codec,
    ) {}

    /**
     * @return array<array-key, mixed>
     */
    public function props(): array
    {
        return InertiaProps::document($this->codec->encode(new PanelBrand(
            $this->image($this->branding->loginImage()),
            $this->image($this->branding->logo),
            $this->branding->name(),
        ), ClassificationAccess::Public));
    }

    /**
     * The address the panel serves the brand file at.
     */
    public function url(BrandFile $file): string
    {
        return $this->urls->route(PanelRoute::Brand->value, ['name' => $file->name], false);
    }

    private function image(?BrandImage $image): ?PanelBrandLogo
    {
        return $image instanceof BrandImage ? new PanelBrandLogo($image->alt, $this->url($image->dark), $this->url($image->light)) : null;
    }
}
