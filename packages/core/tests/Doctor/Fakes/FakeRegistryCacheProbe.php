<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Dto\RegistryCacheState;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use DateTimeImmutable;

/**
 * A registry cache built after vendor/ changed, until the test sets another state.
 */
final class FakeRegistryCacheProbe implements RegistryCacheProbe
{
    public RegistryCacheState $state;

    public function __construct(?RegistryCacheState $state = null)
    {
        $this->state = $state ?? self::fresh();
    }

    public static function fresh(): RegistryCacheState
    {
        return self::build(builtAt: new DateTimeImmutable('2026-01-01T10:00:05Z'));
    }

    /**
     * @param  list<string>  $missing
     */
    public static function build(
        ?DateTimeImmutable $builtAt,
        array $missing = [],
        ?string $damage = null,
        ?DateTimeImmutable $manifestChangedAt = new DateTimeImmutable('2026-01-01T10:00:00Z'),
    ): RegistryCacheState {
        return new RegistryCacheState('/app/bootstrap/cache/cms', $missing, $builtAt, $damage, '/app/vendor/composer/installed.json', $manifestChangedAt);
    }

    public function state(): RegistryCacheState
    {
        return $this->state;
    }
}
