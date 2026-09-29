<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ConsistencyToken;
use Cbox\Cms\Contracts\Consistency\LogSequenceNumber;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\GenerationModel;
use Cbox\Cms\Contracts\Envelope\ModelParameter;
use Cbox\Cms\Contracts\Envelope\PromptReference;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Envelope\SourceReference;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;

/**
 * The kernel's JSON Schemas and their codecs (GUARDRAILS 2.2, PRD 6.1, 8.4, 8.8): the schemas of
 * the contracts module, one file per contract version, and the codec composer generate:protocol
 * writes for each into the core's codecs, one per contract version. Each codec reads and writes the
 * classes of the contracts its binding names, so no DTO is generated and no code serialises them
 * by hand.
 */
#[Internal]
final readonly class ProtocolSchemas
{
    /** Where the schemas are, relative to the root of cboxdk/cms. */
    public const string SCHEMA_DIRECTORY = 'packages/contracts/resources/schemas';

    /** Where the codecs go, relative to the root of cboxdk/cms; composer generate:protocol owns it. */
    public const string PHP_DIRECTORY = 'packages/core/src/Codecs/Boundary/Generated';

    public const string PHP_NAMESPACE = 'Cbox\Cms\Core\Codecs\Boundary\Generated';

    /** The stability of the generated codecs: the classes they read and write are Experimental. */
    public const string ATTRIBUTE = Experimental::class;

    /**
     * The bindings of the kernel's schemas, sorted by file.
     *
     * @return list<SchemaBinding>
     */
    public static function kernel(): array
    {
        return [
            new SchemaBinding(
                schema: 'envelope.v1.json',
                codecClass: 'EnvelopeCodecV1',
                version: 1,
                objects: [
                    '#' => RequestEnvelope::class,
                    '#/$defs/model' => GenerationModel::class,
                    '#/$defs/parameter' => ModelParameter::class,
                    '#/$defs/provenance' => Provenance::class,
                ],
                values: [
                    '#/properties/correlation_id' => ValueBinding::value(CorrelationId::class),
                    '#/properties/idempotency_key' => ValueBinding::value(IdempotencyKey::class),
                    '#/properties/on_behalf_of/items' => ValueBinding::id(ActorId::class),
                    '#/properties/wait_level' => ValueBinding::enum(WaitLevel::class),
                    '#/$defs/provenance/properties/prompt' => ValueBinding::value(PromptReference::class),
                    '#/$defs/provenance/properties/sources/items' => ValueBinding::value(SourceReference::class),
                ],
            ),
            new SchemaBinding(
                schema: 'problem.v1.json',
                codecClass: 'ProblemCodecV1',
                version: 1,
                objects: [
                    '#' => Problem::class,
                    '#/$defs/field_error' => CatalogError::class,
                ],
                values: [
                    '#/properties/code' => ValueBinding::enum(ErrorCode::class),
                    '#/$defs/field_error/properties/code' => ValueBinding::enum(ErrorCode::class),
                    '#/$defs/field_error/properties/field' => ValueBinding::id(FieldPath::class),
                ],
                names: [
                    '#/$defs/field_error/properties/detail' => 'message',
                    '#/$defs/field_error/properties/field' => 'path',
                ],
            ),
            new SchemaBinding(
                schema: 'receipt.v1.json',
                codecClass: 'ReceiptCodecV1',
                version: 1,
                objects: [
                    '#' => Receipt::class,
                    '#/$defs/consistency_token' => ConsistencyToken::class,
                    '#/$defs/projection_status' => ProjectionStatus::class,
                ],
                values: [
                    '#/properties/changeset_id' => ValueBinding::id(ChangesetId::class),
                    '#/properties/outcome' => ValueBinding::enum(Outcome::class),
                    '#/properties/position' => ValueBinding::value(CommitPosition::class),
                    '#/properties/retention_class' => ValueBinding::enum(RetentionClass::class),
                    '#/properties/wait_level' => ValueBinding::enum(WaitLevel::class),
                    '#/$defs/consistency_token/properties/lsn' => ValueBinding::value(LogSequenceNumber::class),
                    '#/$defs/projection_status/properties/projection' => ValueBinding::value(ProjectionName::class),
                    '#/$defs/projection_status/properties/state' => ValueBinding::enum(ProjectionState::class),
                ],
            ),
        ];
    }

    /**
     * The codec files of the contracts in $location, which the result owns, sorted by path. Two
     * contracts with one codec class are refused.
     *
     * @param  list<CodecContract>  $contracts
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function result(array $contracts, PhpLocation $location): GenerationResult
    {
        $files = [];

        foreach ($contracts as $contract) {
            $file = PhpCodecEmitter::emit($contract, $location, $location);

            if (isset($files[$file->path])) {
                throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('Two kernel schemas have the codec %s; give each contract version its own.', $contract->codecClass));
            }

            $files[$file->path] = $file;
        }

        ksort($files, SORT_STRING);

        return new GenerationResult(array_values($files), [$location->directory]);
    }
}
