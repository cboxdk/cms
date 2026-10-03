<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;

/**
 * The declarations one scan has found so far, which AttributeScanner fills and turns into a
 * Discovery.
 */
#[Internal]
final class ScanFindings
{
    /** @var list<CommandEntry> */
    public array $commands = [];

    /** @var list<QueryEntry> */
    public array $queries = [];

    /** @var list<DiscoveredAction> */
    public array $actions = [];

    /** @var list<DiscoveredHook> */
    public array $hooks = [];

    /** @var list<SubscriberEntry> */
    public array $subscribers = [];

    /** @var list<PanelPointEntry> */
    public array $panelPoints = [];
}
