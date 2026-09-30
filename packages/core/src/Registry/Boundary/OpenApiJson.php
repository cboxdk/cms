<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\DescribedRoute;
use Cbox\Cms\Core\Registry\Domain\Dto\OpenApiDocument;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use JsonException;
use stdClass;
use UnexpectedValueException;

/**
 * Writes the OpenAPI 3.1 document of the REST surface (GUARDRAILS 2.1, PRD 6.1, 8.8) from its
 * described routes and the kernel's receipt and problem details schemas.
 *
 * - A write is a POST whose body is the command's document, `application/json`, with the envelope
 *   in headers: Idempotency-Key, which REST requires, and Cbox-Wait-Level, Cbox-Dry-Run and
 *   Cbox-Correlation-Id, each with its default when it is left out. It needs a Bearer credential.
 *   It answers 200 with the receipt when it committed or ran dry, 202 with the receipt when it
 *   committed but did not reach its wait level (committed_wait_timeout), and a problem details
 *   document, `application/problem+json`, when it was rejected.
 * - A read is a GET whose query parameter QUERY_PARAMETER holds the query's document as JSON, an
 *   empty object when it is left out. It runs as the anonymous principal without a credential. It
 *   answers 200 with the result, or a problem details document.
 *
 * Every JSON Schema is a component of its own: `Receipt` and `Problem`, and per route
 * `command.<name>.v<version>`, or `query.<name>.v<version>` and `result.<name>.v<version>`. Each
 * gets an `$id` of its own when it has none, `urn:cbox-cms:<component>`, so the references in it
 * to its own definitions (`#/$defs/...`) resolve inside it and not against the OpenAPI document.
 *
 * The document is pretty-printed JSON with every object's keys sorted and a final newline, so the
 * same routes and schemas always give the same bytes.
 */
#[Internal]
final readonly class OpenApiJson
{
    /** The version of OpenAPI the document follows. */
    public const string OPENAPI = '3.1.1';

    public const string RECEIPT = 'Receipt';

    public const string PROBLEM = 'Problem';

    /** The header a caller's idempotency key is sent in; REST requires it (PRD 6.1). */
    public const string IDEMPOTENCY_KEY = 'Idempotency-Key';

    public const string WAIT_LEVEL = 'Cbox-Wait-Level';

    public const string DRY_RUN = 'Cbox-Dry-Run';

    public const string CORRELATION_ID = 'Cbox-Correlation-Id';

    /** The media type of a problem details document (RFC 9457). */
    public const string PROBLEM_MEDIA_TYPE = 'application/problem+json';

    /**
     * @param  list<DescribedRoute>  $routes
     *
     * @throws UnexpectedValueException when a schema cannot be read or the document cannot be written
     */
    public function encode(array $routes, JsonSchema $receipt, JsonSchema $problem): OpenApiDocument
    {
        $schemas = [
            self::PROBLEM => $this->schema($problem, 'problem:v1'),
            self::RECEIPT => $this->schema($receipt, 'receipt:v1'),
        ];
        $paths = [];

        foreach ($routes as $described) {
            $route = $described->route;
            $name = sprintf('%s.v%d', $route->name->value, $route->version);

            if ($route->kind === ActionKind::Write) {
                $schemas['command.'.$name] = $this->schema($described->input, 'command:'.$name);
                $paths[$route->path] = ['post' => $this->write($route, 'command.'.$name)];

                continue;
            }

            $schemas['query.'.$name] = $this->schema($described->input, 'query:'.$name);
            $schemas['result.'.$name] = $this->schema($described->result ?? throw new UnexpectedValueException(sprintf('The read %s has no result schema.', $name)), 'result:'.$name);
            $paths[$route->path] = ['get' => $this->read($route, 'query.'.$name, 'result.'.$name)];
        }

        $document = [
            'components' => [
                'parameters' => $this->parameters(),
                'responses' => [
                    'Rejected' => [
                        'content' => [self::PROBLEM_MEDIA_TYPE => ['schema' => $this->reference('schemas', self::PROBLEM)]],
                        'description' => 'Rejected: nothing was committed or read. The problem details (RFC 9457) carry the catalog code of the error that decided it, the HTTP status the catalog gives that code, and every error with the path of its field.',
                    ],
                ],
                'schemas' => $schemas,
                'securitySchemes' => [
                    'bearer' => [
                        'description' => 'A credential of the installation, whose actor the call runs as (PRD 5.16).',
                        'scheme' => 'bearer',
                        'type' => 'http',
                    ],
                ],
            ],
            'info' => [
                'description' => 'The REST surface of Cbox CMS, compiled by cms:build from the actions registry (GUARDRAILS 2.1). Every write runs through the command pipeline and every read through the query pipeline.',
                'title' => 'Cbox CMS REST',
                'version' => RestRoute::CONTRACT,
            ],
            'openapi' => self::OPENAPI,
            'paths' => $paths === [] ? new stdClass : $paths,
        ];

        try {
            return new OpenApiDocument(json_encode($this->sorted($document), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The OpenAPI document cannot be written as JSON: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function write(RestRoute $route, string $command): array
    {
        return [
            'description' => sprintf('Runs version %d of the command %s through the command pipeline (PRD 6.1, 6.2).', $route->version, $route->name->value),
            'operationId' => $command,
            'parameters' => array_map(fn (string $name): array => $this->reference('parameters', $name), ['CorrelationId', 'DryRun', 'IdempotencyKey', 'WaitLevel']),
            'requestBody' => [
                'content' => ['application/json' => ['schema' => $this->reference('schemas', $command)]],
                'required' => true,
            ],
            'responses' => [
                '200' => $this->receipt('Committed, or computed without committing (dry_run): the receipt.'),
                '202' => $this->receipt('Committed, but the wait level was not reached in time (committed_wait_timeout): the receipt. The command is not sent again.'),
                '4XX' => $this->reference('responses', 'Rejected'),
                '5XX' => $this->reference('responses', 'Rejected'),
            ],
            'security' => [['bearer' => []]],
            'summary' => sprintf('%s, version %d', $route->name->value, $route->version),
            'tags' => ['commands'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(RestRoute $route, string $query, string $result): array
    {
        return [
            'description' => sprintf('Runs version %d of the query %s through the query pipeline (PRD 6.2).', $route->version, $route->name->value),
            'operationId' => $query,
            'parameters' => [[
                'content' => ['application/json' => ['schema' => $this->reference('schemas', $query)]],
                'description' => 'The query\'s document as JSON; an empty object when it is left out.',
                'in' => 'query',
                'name' => RestRoute::QUERY_PARAMETER,
                'required' => false,
            ]],
            'responses' => [
                '200' => [
                    'content' => ['application/json' => ['schema' => $this->reference('schemas', $result)]],
                    'description' => 'Answered: the result, without the fields above the caller\'s classification access.',
                ],
                '4XX' => $this->reference('responses', 'Rejected'),
                '5XX' => $this->reference('responses', 'Rejected'),
            ],
            'security' => [new stdClass, ['bearer' => []]],
            'summary' => sprintf('%s, version %d', $route->name->value, $route->version),
            'tags' => ['queries'],
        ];
    }

    /**
     * The envelope's headers (PRD 6.1), with the rules of envelope.v1.json.
     *
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        return [
            'CorrelationId' => [
                'description' => 'The id that ties the call together across surfaces, such as a trace id. The surface makes one when it is left out.',
                'in' => 'header',
                'name' => self::CORRELATION_ID,
                'required' => false,
                'schema' => ['pattern' => '^[!-~]{1,128}$', 'type' => 'string'],
            ],
            'DryRun' => [
                'description' => 'true computes the plan and the receipt without committing.',
                'in' => 'header',
                'name' => self::DRY_RUN,
                'required' => false,
                'schema' => ['default' => 'false', 'enum' => ['false', 'true'], 'type' => 'string'],
            ],
            'IdempotencyKey' => [
                'description' => 'The caller\'s idempotency key, required on every command (PRD 6.1). The same key with the same command gives the first call\'s result; with another command it is rejected with idempotency_conflict.',
                'in' => 'header',
                'name' => self::IDEMPOTENCY_KEY,
                'required' => true,
                'schema' => ['pattern' => '^[!-~]{1,255}$', 'type' => 'string'],
            ],
            'WaitLevel' => [
                'description' => 'How long to wait before the call returns (PRD 8.4).',
                'in' => 'header',
                'name' => self::WAIT_LEVEL,
                'required' => false,
                'schema' => ['default' => 'commit', 'enum' => ['commit', 'origin', 'edge', 'verified', 'propagated'], 'type' => 'string'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(string $description): array
    {
        return [
            'content' => ['application/json' => ['schema' => $this->reference('schemas', self::RECEIPT)]],
            'description' => $description,
        ];
    }

    /**
     * @return array{'$ref': string}
     */
    private function reference(string $section, string $name): array
    {
        return ['$ref' => sprintf('#/components/%s/%s', $section, $name)];
    }

    /**
     * The schema as an object, with an `$id` of its own unless it has one.
     *
     * @throws UnexpectedValueException when the schema is not a JSON object
     */
    private function schema(JsonSchema $schema, string $id): stdClass
    {
        try {
            $value = json_decode($schema->json, false, JsonSchema::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('A JSON Schema is not well-formed JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! $value instanceof stdClass) {
            throw new UnexpectedValueException('A JSON Schema is not a JSON object.');
        }

        if (! property_exists($value, '$id')) {
            $value->{'$id'} = 'urn:cbox-cms:'.$id;
        }

        return $value;
    }

    /**
     * The value with the keys of every object sorted; lists keep their order.
     */
    private function sorted(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $sorted = new stdClass;

            foreach ($properties as $key => $property) {
                $sorted->{$key} = $this->sorted($property);
            }

            return $sorted;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map($this->sorted(...), $value);
            }

            ksort($value, SORT_STRING);

            return array_map($this->sorted(...), $value);
        }

        return $value;
    }
}
