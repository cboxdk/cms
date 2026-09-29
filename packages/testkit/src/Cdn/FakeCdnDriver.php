<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\CdnPurgeResult;
use Cbox\Cms\Contracts\Cdn\CdnUnavailable;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use InvalidArgumentException;

/**
 * The in-memory fake of CdnDriver (GUARDRAILS 2.3), and its own harness. The real drivers come
 * with full-scale invalidation; until then the kernel purges through this fake.
 *
 * It records every request it would send, with the keys and the mode the edge applies, and
 * purgedWith() gives the mode of the latest purge of a key. The constructor sets
 * what the CDN can do: whether it purges softly, as Fastly does, or always hard, as Cloudflare
 * does (PRD 8.12 point 3), and the most keys per request. interrupt() makes it throw
 * CdnUnavailable for every purge until restore(), without recording anything.
 */
#[Experimental]
final class FakeCdnDriver implements CdnDriver, CdnDriverHarness
{
    /** The most keys per request by default, Fastly's limit for a purge by surrogate key. */
    public const int DEFAULT_MAX_KEYS_PER_REQUEST = 256;

    /** @var list<CdnPurge> */
    private array $requests = [];

    /** @var array<string, PurgeMode> by key, the mode of its latest purge */
    private array $purged = [];

    private bool $interrupted = false;

    /** @var positive-int */
    private readonly int $maxKeysPerRequest;

    public function __construct(
        private readonly bool $softPurge = true,
        int $maxKeysPerRequest = self::DEFAULT_MAX_KEYS_PER_REQUEST,
    ) {
        if ($maxKeysPerRequest < 1) {
            throw new InvalidArgumentException(sprintf('A CDN takes at least one key per request, got %d.', $maxKeysPerRequest));
        }

        $this->maxKeysPerRequest = $maxKeysPerRequest;
    }

    public function driver(): CdnDriver
    {
        return $this;
    }

    public function purge(CdnPurge $purge): CdnPurgeResult
    {
        if ($this->interrupted) {
            throw CdnUnavailable::because('the fake CDN is interrupted.');
        }

        $mode = $purge->mode === PurgeMode::Soft && ! $this->softPurge ? PurgeMode::Hard : $purge->mode;
        $chunks = array_chunk($purge->keys, $this->maxKeysPerRequest);

        foreach ($chunks as $keys) {
            $this->requests[] = new CdnPurge($keys, $mode);

            foreach ($keys as $key) {
                $this->purged[$key->toString()] = $mode;
            }
        }

        return new CdnPurgeResult(new CdnPurge($purge->keys, $mode), count($chunks));
    }

    public function supportsSoftPurge(): bool
    {
        return $this->softPurge;
    }

    public function maxKeysPerRequest(): int
    {
        return $this->maxKeysPerRequest;
    }

    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * Whether $key has been purged, and with the mode of its latest purge; null when never.
     */
    public function purgedWith(DependencyKey $key): ?PurgeMode
    {
        return $this->purged[$key->toString()] ?? null;
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
    }

    public function restore(): void
    {
        $this->interrupted = false;
    }
}
