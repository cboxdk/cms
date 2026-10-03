<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Closure;
use Override;

/**
 * A CommandAuthorizer that decides as the one it wraps and then runs what a test lets happen
 * meanwhile, such as another call committing on another connection, so the call goes on to its
 * commit from an authorization whose grounds are stale by then.
 */
final readonly class AfterAuthorize implements CommandAuthorizer
{
    /**
     * @param  Closure(): void  $meanwhile
     */
    public function __construct(
        private CommandAuthorizer $authorizer,
        private Closure $meanwhile,
    ) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates, Envelope $envelope): Authorization
    {
        $authorization = $this->authorizer->authorize($access, $command, $input, $aggregates, $envelope);
        ($this->meanwhile)();

        return $authorization;
    }
}
