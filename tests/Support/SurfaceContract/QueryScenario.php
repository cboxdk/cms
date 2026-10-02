<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

/**
 * What a surface contract test of a query sends through a surface (GUARDRAILS 2.1, 9): a document
 * the query's generated codec refuses, a principal the kernel's authorizer refuses, and a read the
 * kernel answers, whose result the surface writes with the query's result codec.
 */
enum QueryScenario: string
{
    /**
     * The query's document without its first required property, json_invalid at its path, or, for
     * a query that requires none, with a key it does not have, json_invalid at the document.
     */
    case DocumentRefused = 'document_refused';

    /** The query authorizer refuses the principal: unauthorized. */
    case Unauthorized = 'unauthorized';

    /** The kernel answers the read with the smallest result the result's JSON Schema accepts. */
    case Answered = 'answered';

    /**
     * Whether the kernel hands the query to its action in this scenario.
     */
    public function handled(): bool
    {
        return $this === self::Answered;
    }
}
