<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Branding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The kind of image a brand file is, read from its bytes, never its name: an SVG document or a PNG.
 */
#[Internal]
enum BrandImageType: string
{
    case Svg = 'svg';
    case Png = 'png';

    public function contentType(): string
    {
        return match ($this) {
            self::Svg => 'image/svg+xml',
            self::Png => 'image/png',
        };
    }
}
