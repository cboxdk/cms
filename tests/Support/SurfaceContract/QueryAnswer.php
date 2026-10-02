<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

/**
 * What a surface answered a query with, read the same way for every surface: answered with the
 * result's document as the JSON text the surface sent, or rejected with a problem's catalog code and its errors, each as
 * "<code> <field>" with "-" for none, and the transport's own signal, which each
 * QuerySurfaceProfile writes as it reads it, such as "HTTP 403 application/problem+json".
 */
final readonly class QueryAnswer
{
    /**
     * @param  list<string>  $errors
     * @param  ?string  $result  the result's JSON text when the read was answered
     */
    public function __construct(
        public string $outcome,
        public ?string $code,
        public array $errors,
        public ?string $result,
        public string $transport,
    ) {}

    /**
     * Reads a problem document when the surface signalled a rejection, else takes the JSON text as
     * the result's document.
     */
    public static function of(string $json, bool $rejected, string $transport): self
    {
        if (! $rejected) {
            return new self('answered', null, [], $json, $transport);
        }

        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $problem = SurfaceAnswer::of(is_array($document) ? $document : [], $transport);

        return new self($problem->outcome, $problem->code, $problem->errors, null, $transport);
    }
}
