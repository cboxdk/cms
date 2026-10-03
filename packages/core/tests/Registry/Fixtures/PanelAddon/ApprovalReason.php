<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

/**
 * A value class of the addon, which its replacements at a point keyed by value class may replace.
 */
final readonly class ApprovalReason
{
    public function __construct(public string $value) {}
}
