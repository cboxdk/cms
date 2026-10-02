<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * One call of a query through a surface, as its caller makes it: the query and version, its JSON
 * document and the credential.
 */
final readonly class QuerySurfaceCall
{
    public function __construct(
        public CommandName $query,
        public int $version,
        public string $document,
        public TransportCredential $credential,
    ) {}
}
