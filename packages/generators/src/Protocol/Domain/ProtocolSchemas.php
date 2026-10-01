<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ConsistencyToken;
use Cbox\Cms\Contracts\Consistency\LogSequenceNumber;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\GenerationModel;
use Cbox\Cms\Contracts\Envelope\ModelParameter;
use Cbox\Cms\Contracts\Envelope\PromptReference;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Envelope\SourceReference;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryDocument;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryExplanation;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryMeta;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ExplainedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecCommand;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Codec\Domain\PhpSource;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;

/**
 * The kernel's JSON Schemas and their codecs (GUARDRAILS 2.2, PRD 6.1, 8.4, 8.8): the schemas of
 * the contracts module and of the kernel's commands, one file per contract version, and the codec
 * composer generate:protocol writes for each into the core's codecs, one per contract version. Each
 * codec reads and writes the classes its binding names, so no DTO is generated and no code
 * serialises them by hand.
 *
 * The codec of a command's contract version carries the command's JSON Schema and builds its
 * CommandCodec, and COMMAND_CODECS, generated with them, lists every one, which the core registers
 * under CommandCodecs::TAG so every exposed surface reads the command (GUARDRAILS 2.1). The kernel
 * knows no content type (GUARDRAILS 2.4), so a command's fields are the generic fields of
 * FieldValuesSchema.
 */
#[Internal]
final readonly class ProtocolSchemas
{
    /** Where the schemas are, relative to the root of cboxdk/cms. */
    public const string SCHEMA_DIRECTORY = 'packages/contracts/resources/schemas';

    /** Where the codecs go, relative to the root of cboxdk/cms; composer generate:protocol owns it. */
    public const string PHP_DIRECTORY = 'packages/core/src/Codecs/Boundary/Generated';

    public const string PHP_NAMESPACE = 'Cbox\Cms\Core\Codecs\Boundary\Generated';

    /** Where the schemas of the kernel's commands are, relative to the root of cboxdk/cms. */
    public const string COMMAND_SCHEMA_DIRECTORY = 'packages/core/resources/schemas/commands';

    /** The generated class that lists the CommandCodec of every command's contract version. */
    public const string COMMAND_CODECS = 'KernelCommandCodecs';

    /**
     * The PHP names of a time window's keys, live_from and live_until, which TimeWindow calls from
     * and until.
     *
     * @var array<string, string>
     */
    private const array WINDOW_NAMES = [
        '#/$defs/time_window/properties/live_from' => 'from',
        '#/$defs/time_window/properties/live_until' => 'until',
    ];

    /** The class of the CommandCodecs the list holds; the generator only writes its name. */
    private const string COMMAND_CODEC = CommandCodec::class;

    /** The stability of the generated codecs: the classes they read and write are Experimental. */
    public const string ATTRIBUTE = Experimental::class;

    /** Where the schemas of the core's own documents are, relative to the root of cboxdk/cms. */
    public const string CORE_SCHEMA_DIRECTORY = 'packages/core/resources/schemas';

    /**
     * The bindings of the kernel's documents, sorted by file: the schemas of the contracts module,
     * the delivery explanation, the delivery API's answers, the envelope, the explained path, the
     * path explanation, the problem details and the receipt, then the core's own, the delivery
     * API's fragment. A member of a document that is a document of another contract, such as the
     * record of a delivery answer or the explanation inside a delivery explanation, is bound with
     * ValueBinding::document(), so its own contract's codec writes it.
     *
     * @return list<SchemaBinding>
     */
    public static function kernel(): array
    {
        $document = ValueBinding::document(JsonDocument::class);
        $id = static fn (string $class): ValueBinding => ValueBinding::id($class);
        $meta = [
            '#/$defs/meta/properties/locale' => ValueBinding::value(Locale::class),
            '#/$defs/meta/properties/type' => ValueBinding::value(TypeName::class),
        ];

        return [
            new SchemaBinding(
                schema: 'delivery-explanation.v1.json',
                codecClass: 'DeliveryExplanationCodecV1',
                version: 1,
                objects: [
                    '#' => DeliveryExplanation::class,
                    '#/$defs/meta' => DeliveryMeta::class,
                ],
                values: [
                    '#/properties/data' => $document,
                    '#/properties/explanation' => $document,
                    '#/properties/problem' => $document,
                    '#/properties/status' => ValueBinding::enum(HttpStatus::class),
                    ...$meta,
                ],
            ),
            new SchemaBinding(
                schema: 'delivery.v1.json',
                codecClass: 'DeliveryCodecV1',
                version: 1,
                objects: [
                    '#' => DeliveryDocument::class,
                    '#/$defs/meta' => DeliveryMeta::class,
                ],
                values: ['#/properties/data' => $document, ...$meta],
            ),
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
                schema: 'explained-path.v1.json',
                codecClass: 'ExplainedPathCodecV1',
                version: 1,
                objects: ['#' => ExplainedPath::class],
                values: [
                    '#/properties/content_keys/items' => $id(DependencyKey::class),
                    '#/properties/explanation' => $document,
                    '#/properties/read_position' => ValueBinding::value(CommitPosition::class),
                ],
            ),
            new SchemaBinding(
                schema: 'path-explanation.v1.json',
                codecClass: 'PathExplanationCodecV1',
                version: 1,
                objects: [
                    '#' => PathExplanation::class,
                    '#/$defs/canonical_step' => CanonicalStep::class,
                    '#/$defs/mount_step' => MountStep::class,
                    '#/$defs/node_step' => NodeStep::class,
                    '#/$defs/placement_step' => PlacementStep::class,
                    '#/$defs/route_step' => RouteStep::class,
                    '#/$defs/site_step' => SiteStep::class,
                    '#/$defs/time_window' => TimeWindow::class,
                    '#/$defs/visibility_step' => VisibilityStep::class,
                ],
                values: [
                    '#/properties/outcome' => ValueBinding::enum(ResolveOutcome::class),
                    '#/$defs/canonical_step/properties/placement' => $id(PlacementId::class),
                    '#/$defs/mount_step/properties/mount' => $id(NodeId::class),
                    '#/$defs/mount_step/properties/source' => $id(NodeId::class),
                    '#/$defs/node_step/properties/kind' => ValueBinding::enum(NodeKind::class),
                    '#/$defs/node_step/properties/node' => $id(NodeId::class),
                    '#/$defs/placement_step/properties/entry' => $id(EntryId::class),
                    '#/$defs/placement_step/properties/looked_under' => $id(NodeId::class),
                    '#/$defs/placement_step/properties/placement' => $id(PlacementId::class),
                    '#/$defs/placement_step/properties/slug' => ValueBinding::value(Slug::class),
                    '#/$defs/placement_step/properties/type' => $id(TypeId::class),
                    '#/$defs/route_step/properties/path' => ValueBinding::value(RequestPath::class),
                    '#/$defs/site_step/properties/handle' => ValueBinding::value(SiteHandle::class),
                    '#/$defs/site_step/properties/host' => ValueBinding::value(Host::class),
                    '#/$defs/site_step/properties/locale' => ValueBinding::value(Locale::class),
                    '#/$defs/site_step/properties/site' => $id(SiteId::class),
                    '#/$defs/visibility_step/properties/decision' => ValueBinding::enum(VisibilityDecision::class),
                    '#/$defs/visibility_step/properties/lifecycle' => ValueBinding::enum(EntryLifecycle::class),
                    '#/$defs/visibility_step/properties/release' => ValueBinding::enum(ReleaseState::class),
                    '#/$defs/visibility_step/properties/stored' => ValueBinding::enum(Visibility::class),
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
            new SchemaBinding(
                schema: 'delivery-fragment.v1.json',
                codecClass: 'DeliveryFragmentCodecV1',
                version: 1,
                objects: ['#' => StoredAnswer::class],
                values: [
                    '#/properties/format' => ValueBinding::enum(AnswerFormat::class),
                    '#/properties/status' => ValueBinding::enum(HttpStatus::class),
                ],
                directory: self::CORE_SCHEMA_DIRECTORY,
            ),
        ];
    }

    /**
     * Every binding composer generate:protocol writes a codec for: the kernel's contracts and its
     * commands.
     *
     * @return list<SchemaBinding>
     */
    public static function all(): array
    {
        return [...self::kernel(), ...self::commands()];
    }

    /**
     * The bindings of the schemas of the kernel's commands that an exposed surface can read, each at
     * its version, sorted by file.
     *
     * @return list<SchemaBinding>
     */
    public static function commands(): array
    {
        $id = static fn (string $class): ValueBinding => ValueBinding::id($class);
        $version = ValueBinding::value(AggregateVersion::class);

        return [
            self::command('actor.deactivate.v1.json', 'DeactivateActorCodecV1', DeactivateActor::class, [
                '#/properties/actor' => $id(ActorId::class),
                '#/properties/source' => ValueBinding::enum(DeactivationSource::class),
            ]),
            self::command('entry.create.v1.json', 'CreateEntryCodecV1', CreateEntry::class, [
                '#/properties/entry' => $id(EntryId::class),
                '#/properties/fields' => ValueBinding::fields(FieldValues::class),
                '#/properties/home' => $id(NodeId::class),
                '#/properties/type' => $id(TypeId::class),
            ]),
            self::command('entry.publish.v1.json', 'PublishEntryCodecV1', PublishEntry::class, [
                '#/properties/entry' => $id(EntryId::class),
                '#/properties/locale' => ValueBinding::value(Locale::class),
                '#/properties/placement' => $id(PlacementId::class),
                '#/properties/placement_version' => $version,
                '#/properties/revision' => ValueBinding::value(RevisionNumber::class),
                '#/properties/version' => $version,
            ], ['#/$defs/time_window' => TimeWindow::class], self::WINDOW_NAMES),
            self::command('entry.revise.v1.json', 'ReviseEntryCodecV1', ReviseEntry::class, [
                '#/properties/entry' => $id(EntryId::class),
                '#/properties/fields' => ValueBinding::fields(FieldValues::class),
                '#/properties/version' => $version,
            ]),
            self::command('entry.unpublish.v1.json', 'UnpublishEntryCodecV1', UnpublishEntry::class, [
                '#/properties/entry' => $id(EntryId::class),
                '#/properties/version' => $version,
            ]),
            self::command('placement.create.v1.json', 'CreatePlacementCodecV1', CreatePlacement::class, [
                '#/properties/entry' => $id(EntryId::class),
                '#/properties/node' => $id(NodeId::class),
                '#/properties/placement' => $id(PlacementId::class),
                '#/properties/site' => $id(SiteId::class),
                '#/$defs/locale_slug/properties/locale' => ValueBinding::value(Locale::class),
                '#/$defs/locale_slug/properties/slug' => ValueBinding::value(Slug::class),
            ], ['#/$defs/locale_slug' => LocaleSlug::class]),
            self::command('placement.set_window.v1.json', 'SetPlacementWindowCodecV1', SetPlacementWindow::class, [
                '#/properties/locale' => ValueBinding::value(Locale::class),
                '#/properties/placement' => $id(PlacementId::class),
                '#/properties/version' => $version,
            ], ['#/$defs/time_window' => TimeWindow::class], self::WINDOW_NAMES),
            self::command('variant.release.v1.json', 'ReleaseVariantCodecV1', ReleaseVariant::class, [
                '#/properties/entry' => $id(EntryId::class),
                '#/properties/revision' => ValueBinding::value(RevisionNumber::class),
                '#/properties/version' => $version,
            ]),
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

        $commands = array_values(array_filter($contracts, static fn (CodecContract $contract): bool => $contract->command instanceof CodecCommand));

        if ($commands !== []) {
            $list = self::commandCodecs($commands, $location);
            $files[$list->path] = $list;
        }

        ksort($files, SORT_STRING);

        return new GenerationResult(array_values($files), [$location->directory]);
    }

    /**
     * The binding of version 1 of a command's schema, with its document bound to the command.
     *
     * @param  class-string  $command
     * @param  array<string, ValueBinding>  $values
     * @param  array<string, string>  $objects  the objects besides the document
     * @param  array<string, string>  $names
     */
    private static function command(string $schema, string $codecClass, string $command, array $values, array $objects = [], array $names = []): SchemaBinding
    {
        return new SchemaBinding(
            schema: $schema,
            codecClass: $codecClass,
            version: 1,
            objects: ['#' => $command, ...$objects],
            values: $values,
            names: $names,
            directory: self::COMMAND_SCHEMA_DIRECTORY,
            command: $command,
        );
    }

    /**
     * The class COMMAND_CODECS: the CommandCodec of each command's contract version, sorted by the
     * command's name and version, which the core registers under CommandCodecs::TAG.
     *
     * @param  non-empty-list<CodecContract>  $commands
     */
    private static function commandCodecs(array $commands, PhpLocation $location): GeneratedFile
    {
        usort($commands, static fn (CodecContract $a, CodecContract $b): int => [$a->command?->name, $a->version] <=> [$b->command?->name, $b->version]);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace '.$location->namespace.';',
            '',
            ...PhpSource::uses([self::ATTRIBUTE, self::COMMAND_CODEC], $location->namespace),
            '',
            '/**',
            ' * The CommandCodec of each version of each of the kernel\'s commands (GUARDRAILS 2.1, 2.2), which',
            ' * the core registers under the container tag CommandCodecs::TAG, so every exposed surface reads',
            ' * the command and describes it with its JSON Schema.',
            ' *',
            ' * Generated by composer generate:protocol from the schemas in '.self::COMMAND_SCHEMA_DIRECTORY.'.',
            ' * Do not edit this file: change the schemas and run composer generate:protocol.',
            ' */',
            '#['.PhpSource::shortName(self::ATTRIBUTE).']',
            'final readonly class '.self::COMMAND_CODECS,
            '{',
            '    /**',
            '     * @return list<CommandCodec>',
            '     */',
            '    public static function all(): array',
            '    {',
            '        return [',
            ...array_map(static fn (CodecContract $contract): string => '            '.$contract->codecClass.'::commandCodec(),', $commands),
            '        ];',
            '    }',
            '}',
        ];

        return new GeneratedFile($location->directory.'/'.self::COMMAND_CODECS.'.php', implode("\n", $lines)."\n");
    }
}
