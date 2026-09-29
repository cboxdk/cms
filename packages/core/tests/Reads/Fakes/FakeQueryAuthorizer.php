<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Override;

/**
 * Allows every read, or refuses every one with the reason given, and records what it was asked.
 */
final class FakeQueryAuthorizer implements QueryAuthorizer
{
    /** @var list<array{AccessContext, CommandName, Query}> */
    public array $asked = [];

    public function __construct(private readonly ?string $refusal = null) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization
    {
        $this->asked[] = [$access, $query, $input];

        return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
    }
}
