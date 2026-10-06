<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use JsonException;

/**
 * The script PanelVisit runs in the page to read the contributions a panel point renders, in
 * document order: the id of each element with data-cms-point and data-cms-contribution, a
 * contribution's own element, and the ids of each element with data-cms-point and data-cms-actions,
 * an action point's toolbar, which lists its actions separated by spaces. The point's id reaches the
 * page as a JSON string, so a quote or a backslash in it never breaks out of the script; the
 * encoding sits here because only a Boundary writes JSON (GUARDRAILS 2.2).
 */
#[Experimental]
final readonly class FillOrderScript
{
    private const string FILL_ORDER = <<<'JS'
        (point) => [...document.querySelectorAll(`[data-cms-point="${point}"][data-cms-contribution], [data-cms-point="${point}"][data-cms-actions]`)]
            .flatMap((element) => element.hasAttribute('data-cms-contribution')
                ? [element.getAttribute('data-cms-contribution')]
                : element.getAttribute('data-cms-actions').split(' ').filter((id) => id !== ''))
        JS;

    /**
     * The script, applied to the point, as the browser plugin's `script()` takes it.
     *
     * @throws JsonException
     */
    public static function for(string $point): string
    {
        return sprintf('(%s)(%s)', self::FILL_ORDER, json_encode($point, JSON_THROW_ON_ERROR));
    }
}
