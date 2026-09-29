<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Override;

/**
 * Allows every command, or refuses every one with the reason given, and records what it was asked.
 */
final class FakeCommandAuthorizer implements CommandAuthorizer
{
    /** @var list<array{AccessContext, CommandName, Command, Aggregates}> */
    public array $asked = [];

    public function __construct(private readonly ?string $refusal = null) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates): Authorization
    {
        $this->asked[] = [$access, $command, $input, $aggregates];

        return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
    }
}
