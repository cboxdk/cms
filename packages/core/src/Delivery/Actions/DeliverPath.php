<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Delivery\Domain\DeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\DeliverySource;
use Cbox\Cms\Core\Delivery\Domain\Dto\CacheDirective;
use Cbox\Cms\Core\Delivery\Domain\Dto\Delivery;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryRequest;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliverySettings;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Delivery\Domain\PathAnswers;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use DateTimeImmutable;
use LogicException;

/**
 * The delivery API's resolve (PRD 8.9, 8.10, 8.12, MILESTONES M1 point 5): what a site shows at a
 * path in a locale, as the record DTO of the entry's type, for anyone, and cached.
 *
 * 1. The known parameters are checked: `site`, the host the page is requested at, `locale`, `path`
 *    and `debug`, which is `1` or absent. A parameter that is missing or malformed is
 *    validation_failed. A host no configured site is served at is host_not_configured (421, point
 *    7), before any fragment or read.
 * 2. Without `debug`, the fragment of (host, locale, path) is served when the store holds one
 *    (PRD 9.3): its bytes, its content keys, and a shared lifetime of what is left of it. Nothing is
 *    read and the query pipeline does not run.
 * 3. Otherwise path.resolve runs through the query pipeline as the anonymous principal (invariant
 *    25), whose classification access is public. A resolved path is answered with the record of its
 *    entry, written by the type's generated codec at the public classification access, so no field
 *    above public is in the body (invariant 10); every other outcome with a problem (PathAnswers).
 * 4. The answer is stored as a fragment with its content keys, built at the read's position and
 *    kept until the lower of now plus the configured max age and its valid_until (invariant 17).
 *    The store refuses it when a dependency was purged at or above that position, and the answer is
 *    then sent with `no-store` (PRD 8.12 point 1); a stored answer is sent to a shared cache for
 *    the same lifetime, stale only when PathAnswers allows it.
 * 5. With `debug=1`, the read runs as the principal of the request's credential, and the answer
 *    holds the PathExplanation of every step, for an actor whose classification access is at least
 *    internal; for anyone else it is unauthorized. An explanation is never stored or cached.
 */
#[Internal]
final readonly class DeliverPath
{
    /** The form of a fragment key; the version changes when the stored form of an answer does. */
    public const string FRAGMENT_PREFIX = 'delivery:resolve:v1:';

    public function __construct(
        private QueryPipeline $pipeline,
        private FragmentStore $fragments,
        private DeliveryDocuments $documents,
        private RecordCodecs $records,
        private TypeCatalog $types,
        private SiteHosts $sites,
        private DeliverySettings $settings,
        private Clock $clock,
    ) {}

    public function deliver(DeliveryRequest $request): Delivery
    {
        $errors = [];
        $host = $this->parsed($request->site, 'site', static fn (string $value): Host => new Host($value), $errors);
        $locale = $this->parsed($request->locale, 'locale', static fn (string $value): Locale => new Locale($value), $errors);
        $path = $this->parsed($request->path, 'path', static fn (string $value): RequestPath => new RequestPath($value), $errors);

        if ($request->debug !== null && $request->debug !== '1') {
            $errors[] = new CatalogError(ErrorCode::ValidationInvalidFormat, new FieldPath('debug'), 'debug is 1, or left out.');
        }

        if (! $host instanceof Host || ! $locale instanceof Locale || ! $path instanceof RequestPath || $errors !== []) {
            return $this->problem(Problem::of(ErrorCode::ValidationFailed, 'The request does not name a host, a locale and a path to resolve.', $errors));
        }

        if (! $this->sites->serving($host) instanceof ConfiguredSite) {
            return $this->problem(Problem::of(ErrorCode::HostNotConfigured, sprintf('No configured site is served at %s.', $host->value)));
        }

        $query = new ResolvePath($host, $locale, $path);

        if ($request->debug !== null) {
            return $this->explained($query, $this->pipeline->run(new QueryCall($query, $request->credential)));
        }

        $key = self::fragmentKey($query);
        $fragment = $this->fragments->read($key);
        $stored = $fragment instanceof Fragment ? $this->documents->stored($fragment->body) : null;

        if ($fragment instanceof Fragment && $stored instanceof StoredAnswer) {
            $maxAge = $this->secondsUntil($fragment->validUntil);

            return new Delivery($stored->status, $stored->format, $stored->body, $fragment->dependencies, $maxAge > 0 ? $this->shared($maxAge, $stored->stale) : CacheDirective::noStore(), DeliverySource::Fragment);
        }

        return $this->built($key, $query, $this->pipeline->run(new QueryCall($query, null)));
    }

    /**
     * The fragment key of a resolution: its host, its locale and its path (PRD 8.10 point 8), hashed.
     */
    public static function fragmentKey(ResolvePath $query): FragmentKey
    {
        return new FragmentKey(self::FRAGMENT_PREFIX.hash('sha256', $query->host->value."\n".$query->locale->value."\n".$query->path->value));
    }

    /**
     * The answer of an anonymous read, stored as a fragment when it can be.
     */
    private function built(FragmentKey $key, ResolvePath $query, QueryResult $result): Delivery
    {
        $resolved = $this->resolved($result);

        if (! $resolved instanceof ResolvedPath) {
            return $this->rejected($result);
        }

        $explanation = $resolved->explanation;
        $answer = $this->answer($query, $resolved, null);
        $body = $this->documents->body($answer);
        $keys = PathAnswers::contentKeys($explanation, $result->contentKeys);
        $stale = PathAnswers::mayBeStale($explanation);
        $until = $this->until(PathAnswers::validUntil($explanation));
        $position = $result->position ?? throw new LogicException('An answered read has the position of its snapshot.');

        if ($this->secondsUntil($until) < 1) {
            return new Delivery($answer->status, $answer->format(), $body, $keys, CacheDirective::noStore(), DeliverySource::Origin);
        }

        $stored = new StoredAnswer($answer->status, $answer->format(), $body, $stale);
        $outcome = $this->fragments->write(new Fragment($key, $this->documents->fragment($stored), $keys, $position, $until));
        $cache = $outcome instanceof FragmentStored ? $this->shared($this->secondsUntil($until), $stale) : CacheDirective::noStore();

        return new Delivery($answer->status, $answer->format(), $body, $keys, $cache, DeliverySource::Origin);
    }

    /**
     * The answer of a read that asked for the explanation, for an actor at least internal.
     */
    private function explained(ResolvePath $query, QueryResult $result): Delivery
    {
        $resolved = $this->resolved($result);

        if (! $resolved instanceof ResolvedPath) {
            return $this->rejected($result);
        }

        if (! $result->access->allows(ClassificationAccess::Internal)) {
            return $this->problem(Problem::of(ErrorCode::Unauthorized, 'The explanation of a resolution is for an actor whose classification access is at least internal. Send the credential of such an actor with debug=1, or leave debug out.'));
        }

        $answer = $this->answer($query, $resolved, $resolved->explanation);

        return new Delivery($answer->status, $answer->format(), $this->documents->body($answer), PathAnswers::contentKeys($resolved->explanation, $result->contentKeys), CacheDirective::noStore(), DeliverySource::Origin);
    }

    private function answer(ResolvePath $query, ResolvedPath $resolved, ?PathExplanation $explanation): DeliveryAnswer
    {
        $code = PathAnswers::code($resolved->explanation);
        $content = $resolved->content;

        if ($code instanceof ErrorCode || ! $content instanceof ReadContent) {
            return DeliveryAnswer::problem(Problem::of($code ?? ErrorCode::PathNotFound, PathAnswers::detail($resolved->explanation)), $explanation);
        }

        $type = $this->types->find($content->type);

        if (! $type instanceof TypeDefinition) {
            throw new LogicException(sprintf('The resolved entry %s is of the type %s, which the TypeCatalog does not have.', $content->entry->toString(), $content->type->toString()));
        }

        return new DeliveryAnswer(
            HttpStatus::Ok,
            $this->records->encode($content, ClassificationAccess::Public),
            $type->name,
            $query->locale,
            $resolved->explanation->canonical?->url,
            explanation: $explanation,
        );
    }

    private function rejected(QueryResult $result): Delivery
    {
        $first = $result->errors[0] ?? throw new LogicException('path.resolve answered with something other than a resolved path.');

        return $this->problem(Problem::of($first->code, $first->message, $result->errors));
    }

    private function problem(Problem $problem): Delivery
    {
        $answer = DeliveryAnswer::problem($problem);

        return new Delivery($answer->status, $answer->format(), $this->documents->body($answer), [], CacheDirective::noStore(), DeliverySource::Origin);
    }

    private function resolved(QueryResult $result): ?ResolvedPath
    {
        return $result->result instanceof ResolvedPath ? $result->result : null;
    }

    /**
     * The lower of now plus the configured max age and the valid_until of what the answer depends on.
     */
    private function until(?DateTimeImmutable $validUntil): DateTimeImmutable
    {
        $limit = $this->clock->now()->modify(sprintf('+%d seconds', $this->settings->maxAge));

        return $validUntil instanceof DateTimeImmutable && $validUntil < $limit ? $validUntil : $limit;
    }

    /**
     * The whole seconds from now until the instant, never more than it: 0 when it is less than one.
     */
    private function secondsUntil(DateTimeImmutable $until): int
    {
        $now = $this->clock->now();
        $micro = ((int) $until->format('U') - (int) $now->format('U')) * 1_000_000 + ((int) $until->format('u') - (int) $now->format('u'));

        return max(0, intdiv($micro, 1_000_000));
    }

    private function shared(int $maxAge, bool $stale): CacheDirective
    {
        return $stale
            ? CacheDirective::shared($maxAge, $this->settings->staleWhileRevalidate, $this->settings->staleIfError)
            : CacheDirective::shared($maxAge);
    }

    /**
     * The value of a known parameter, or null with its error added when it is missing or malformed.
     *
     * @template T of object
     *
     * @param  callable(string): T  $parse
     * @param  list<CatalogError>  $errors
     * @return T|null
     */
    private function parsed(?string $value, string $name, callable $parse, array &$errors): ?object
    {
        if ($value === null || $value === '') {
            $errors[] = new CatalogError(ErrorCode::ValidationRequired, new FieldPath($name), sprintf('%s is required.', $name));

            return null;
        }

        try {
            return $parse($value);
        } catch (InvalidRoutingValue|InvalidContentValue $invalid) {
            $errors[] = new CatalogError(ErrorCode::ValidationInvalidFormat, new FieldPath($name), $invalid->getMessage());

            return null;
        }
    }
}
