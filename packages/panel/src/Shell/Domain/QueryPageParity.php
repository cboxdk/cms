<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/**
 * The panel and REST stay in parity for the pages that read (decided by Sylvester on 29 September
 * 2026): a panel page whose props come from a query is an Inertia query page, and its query must
 * be a query action the registry exposes on REST, so what the panel shows a person, the mobile
 * and desktop apps read over REST too. broken() names every own page whose query no query action
 * of the registry handles on REST; SurfaceParityTest fails on one.
 */
#[Internal]
final readonly class QueryPageParity
{
    private function __construct() {}

    /**
     * The own pages whose query is not a query action the registry exposes on REST, each as
     * `<page> reads <query>@<version>`, in the order of the pages.
     *
     * @param  list<OwnPage>  $pages
     * @return list<string>
     */
    public static function broken(CompiledRegistry $registry, array $pages): array
    {
        $broken = [];

        foreach ($pages as $page) {
            $query = $page->query();

            if ($query instanceof CommandRef && ! self::exposed($registry, $query)) {
                $broken[] = sprintf('%s reads %s', $page->value, $query->toString());
            }
        }

        return $broken;
    }

    private static function exposed(CompiledRegistry $registry, CommandRef $query): bool
    {
        foreach ($registry->actions as $action) {
            if ($action->kind === ActionKind::Query && $action->command->value === $query->name->value && $action->commandVersion === $query->version) {
                return $action->exposes(Surface::Rest);
            }
        }

        return false;
    }
}
