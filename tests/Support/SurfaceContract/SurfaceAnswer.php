<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

/**
 * What a surface answered, read the same way for every surface: the receipt's outcome (rejected
 * when the surface answered with a problem and no receipt), the problem's catalog code and its
 * errors, each as "<code> <field>" with "-" for none, the receipt's changeset id and wait level,
 * and the transport's own signal, which each SurfaceProfile writes as it reads it, such as
 * "HTTP 409 application/problem+json" or "exit 65".
 */
final readonly class SurfaceAnswer
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $outcome,
        public ?string $code,
        public array $errors,
        public ?string $changeset,
        public ?string $waitLevel,
        public string $transport,
    ) {}

    /**
     * Reads a receipt or a problem document of a surface.
     *
     * @param  array<array-key, mixed>  $document
     */
    public static function of(array $document, string $transport): self
    {
        $code = $document['code'] ?? null;
        $outcome = $document['outcome'] ?? ($code === null ? null : 'rejected');

        return new self(
            is_string($outcome) ? $outcome : throw new SurfaceAnswerUnreadable('The answer has neither a receipt\'s outcome nor a problem\'s code: '.json_encode($document)),
            is_string($code) ? $code : null,
            self::errors($document['errors'] ?? []),
            is_string($document['changeset_id'] ?? null) ? $document['changeset_id'] : null,
            is_string($document['wait_level'] ?? null) ? $document['wait_level'] : null,
            $transport,
        );
    }

    /**
     * Takes the problem's code and errors from a separate problem document, as the Inertia
     * profile gives them next to the receipt.
     *
     * @param  array<array-key, mixed>  $problem
     */
    public function withProblem(array $problem): self
    {
        $code = $problem['code'] ?? null;

        return new self($this->outcome, is_string($code) ? $code : null, self::errors($problem['errors'] ?? []), $this->changeset, $this->waitLevel, $this->transport);
    }

    /**
     * @return list<string>
     */
    private static function errors(mixed $errors): array
    {
        if (! is_array($errors)) {
            throw new SurfaceAnswerUnreadable('The problem\'s errors are not a list.');
        }

        return array_values(array_map(
            static fn (mixed $error): string => is_array($error) && is_string($error['code'] ?? null)
                ? $error['code'].' '.(is_string($error['field'] ?? null) ? $error['field'] : '-')
                : throw new SurfaceAnswerUnreadable('A problem error is not an object with a code.'),
            $errors,
        ));
    }
}
