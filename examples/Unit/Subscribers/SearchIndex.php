<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

/**
 * The search addon's index, in memory for the example: the version of each page it has indexed.
 * A real index would be a search engine the subscriber calls.
 */
final class SearchIndex
{
    /** @var array<string, int> the indexed version, by page id */
    public array $pages = [];
}
