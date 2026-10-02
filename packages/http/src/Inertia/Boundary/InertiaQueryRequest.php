<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Http\Inertia\Domain\Dto\InertiaQueryInput;
use Illuminate\Http\Request;

/**
 * Reads an Inertia page visit that reads (GUARDRAILS 2.1), as REST reads a GET of a query: the
 * query's document from the query parameter RestRoute::QUERY_PARAMETER, an empty object when the
 * visit leaves it out, read with the query's codec at public classification access, because a query
 * holds no classified content, and the credential as RequestCredential gives it: the session the
 * panel authenticated, or the Bearer header. A document the codec
 * refuses is refused with its code at its path below `query`.
 */
#[Internal]
final readonly class InertiaQueryRequest
{
    /** The query document when the visit leaves the parameter out. */
    private const string EMPTY_QUERY = '{}';

    /**
     * @throws InertiaQueryRefused when the query document is not one the query's codec reads
     */
    public function read(Request $request, QueryCodec $codec): InertiaQueryInput
    {
        $document = $request->query->all()[RestRoute::QUERY_PARAMETER] ?? self::EMPTY_QUERY;
        $at = new FieldPath(RestRoute::QUERY_PARAMETER);

        if (! is_string($document)) {
            throw InertiaQueryRefused::document(DecodingFailed::malformed(sprintf('The query parameter %s holds more than one value; send the query\'s document as one JSON object.', RestRoute::QUERY_PARAMETER)), $at);
        }

        try {
            $query = $codec->query->decode($document, ClassificationAccess::Public);
        } catch (DecodingFailed $failed) {
            throw InertiaQueryRefused::document($failed, $failed->path instanceof FieldPath ? $at->then(...$failed->path->segments) : $at);
        }

        return new InertiaQueryInput($query, RequestCredential::of($request));
    }
}
