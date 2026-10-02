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
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\Access\Domain\Dto\GrantList;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Access\Domain\Dto\ListedRole;
use Cbox\Cms\Core\Access\Domain\Dto\RoleList;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryDocument;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryExplanation;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryMeta;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorList;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedProfile;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ExplainedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Structure\Domain\Dto\ListedNode;
use Cbox\Cms\Core\Structure\Domain\Dto\NodeList;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecCommand;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecQuery;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecQueryResult;
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
 *
 * A query has two schemas per contract version (PRD 6.2, 8.8): its document's and its result's.
 * The codec of the query's document carries both its schema and the codec of its result and
 * builds its QueryCodec, and QUERY_CODECS lists every one, which the core registers under
 * QueryCodecs::TAG so every exposed surface reads the query and writes its result.
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
     * Where the schemas of the kernel's queries and their results are, relative to the root of
     * cboxdk/cms: `<query>.v<version>.json` and `<query>.result.v<version>.json`.
     */
    public const string QUERY_SCHEMA_DIRECTORY = 'packages/core/resources/schemas/queries';

    /** The generated class that lists the QueryCodec of every query's contract version. */
    public const string QUERY_CODECS = 'KernelQueryCodecs';

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

    /** The class of the QueryCodecs the list holds; the generator only writes its name. */
    private const string QUERY_CODEC = QueryCodec::class;

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
                objects: self::pathExplanationObjects('#', '#/$defs/'),
                values: self::pathExplanationValues('#', '#/$defs/'),
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
     * Every binding composer generate:protocol writes a codec for: the kernel's contracts, its
     * commands and its queries.
     *
     * @return list<SchemaBinding>
     */
    public static function all(): array
    {
        return [...self::kernel(), ...self::commands(), ...self::queries()];
    }

    /**
     * The bindings of the schemas of the kernel's queries, each at its version, sorted by file: the
     * query's document, then its result. path.resolve is public and answers with the entry the
     * placement places and the explanation of the resolution (PRD 5.9), whose objects are those of
     * path-explanation.v1.json below `#/$defs/path_explanation`.
     *
     * @return list<SchemaBinding>
     */
    public static function queries(): array
    {
        $id = static fn (string $class): ValueBinding => ValueBinding::id($class);
        $version = ValueBinding::value(AggregateVersion::class);
        $profile = [
            '#/$defs/profile/properties/display_name' => ValueBinding::value(DisplayName::class),
            '#/$defs/profile/properties/email' => ValueBinding::value(EmailAddress::class),
        ];

        return [
            ...self::query(
                'actor.list',
                ListActors::class,
                'ListActorsCodecV1',
                ['#/properties/after' => $id(ActorId::class)],
                'ActorListCodecV1',
                ['#' => ActorList::class, '#/$defs/listed_actor' => ListedActor::class, '#/$defs/profile' => ListedProfile::class],
                [
                    '#/properties/next' => $id(ActorId::class),
                    '#/$defs/listed_actor/properties/id' => $id(ActorId::class),
                    '#/$defs/listed_actor/properties/state' => ValueBinding::enum(ActorState::class),
                    '#/$defs/listed_actor/properties/version' => $version,
                    ...$profile,
                ],
            ),
            ...self::query(
                'grant.list',
                ListGrants::class,
                'ListGrantsCodecV1',
                ['#/properties/after' => $id(GrantId::class)],
                'GrantListCodecV1',
                ['#' => GrantList::class, '#/$defs/listed_grant' => ListedGrant::class, '#/$defs/profile' => ListedProfile::class],
                [
                    '#/properties/next' => $id(GrantId::class),
                    '#/$defs/listed_grant/properties/actor' => $id(ActorId::class),
                    '#/$defs/listed_grant/properties/effect' => ValueBinding::enum(GrantEffect::class),
                    '#/$defs/listed_grant/properties/id' => $id(GrantId::class),
                    '#/$defs/listed_grant/properties/locales/items' => ValueBinding::value(Locale::class),
                    '#/$defs/listed_grant/properties/node' => $id(NodeId::class),
                    '#/$defs/listed_grant/properties/role' => $id(RoleId::class),
                    '#/$defs/listed_grant/properties/role_handle' => ValueBinding::value(RoleHandle::class),
                    '#/$defs/listed_grant/properties/version' => $version,
                    ...$profile,
                ],
            ),
            ...self::query(
                'node.list',
                ListNodes::class,
                'ListNodesCodecV1',
                ['#/properties/after' => $id(NodeId::class)],
                'NodeListCodecV1',
                ['#' => NodeList::class, '#/$defs/listed_node' => ListedNode::class],
                [
                    '#/properties/next' => $id(NodeId::class),
                    '#/$defs/listed_node/properties/id' => $id(NodeId::class),
                    '#/$defs/listed_node/properties/kind' => ValueBinding::enum(NodeKind::class),
                    '#/$defs/listed_node/properties/parent' => $id(NodeId::class),
                    '#/$defs/listed_node/properties/site' => $id(SiteId::class),
                    '#/$defs/listed_node/properties/site_handle' => ValueBinding::value(SiteHandle::class),
                ],
            ),
            ...self::query(
                'path.resolve',
                ResolvePath::class,
                'ResolvePathCodecV1',
                [
                    '#/properties/host' => ValueBinding::value(Host::class),
                    '#/properties/locale' => ValueBinding::value(Locale::class),
                    '#/properties/path' => ValueBinding::value(RequestPath::class),
                ],
                'ResolvedPathCodecV1',
                [
                    '#' => ResolvedPath::class,
                    '#/$defs/read_content' => ReadContent::class,
                    ...self::pathExplanationObjects('#/$defs/path_explanation', '#/$defs/'),
                ],
                [
                    '#/$defs/read_content/properties/entry' => $id(EntryId::class),
                    '#/$defs/read_content/properties/fields' => ValueBinding::fields(FieldValues::class),
                    '#/$defs/read_content/properties/node' => $id(NodeId::class),
                    '#/$defs/read_content/properties/type' => $id(TypeId::class),
                    ...self::pathExplanationValues('#/$defs/path_explanation', '#/$defs/'),
                ],
            ),
            ...self::query(
                'role.list',
                ListRoles::class,
                'ListRolesCodecV1',
                ['#/properties/after' => $id(RoleId::class)],
                'RoleListCodecV1',
                ['#' => RoleList::class, '#/$defs/listed_role' => ListedRole::class],
                [
                    '#/properties/next' => $id(RoleId::class),
                    '#/$defs/listed_role/properties/ceiling' => ValueBinding::enum(ClassificationAccess::class),
                    '#/$defs/listed_role/properties/handle' => ValueBinding::value(RoleHandle::class),
                    '#/$defs/listed_role/properties/id' => $id(RoleId::class),
                    '#/$defs/listed_role/properties/permissions/items' => ValueBinding::value(CommandName::class),
                    '#/$defs/listed_role/properties/version' => $version,
                ],
            ),
        ];
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
            self::command('actor.activate.v1.json', 'ActivateActorCodecV1', ActivateActor::class, [
                '#/properties/actor' => $id(ActorId::class),
                '#/properties/version' => $version,
            ]),
            self::command('actor.deactivate.v1.json', 'DeactivateActorCodecV1', DeactivateActor::class, [
                '#/properties/actor' => $id(ActorId::class),
                '#/properties/source' => ValueBinding::enum(DeactivationSource::class),
            ]),
            self::command('actor.register.v1.json', 'RegisterActorCodecV1', RegisterActor::class, [
                '#/properties/actor' => $id(ActorId::class),
                '#/properties/class' => ValueBinding::enum(ActorClass::class),
                '#/properties/display_name' => ValueBinding::value(DisplayName::class),
                '#/properties/email' => ValueBinding::value(EmailAddress::class),
                '#/properties/responsible' => $id(ActorId::class),
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
            self::command('grant.assign.v1.json', 'AssignGrantCodecV1', AssignGrant::class, [
                '#/properties/actor' => $id(ActorId::class),
                '#/properties/effect' => ValueBinding::enum(GrantEffect::class),
                '#/properties/grant' => $id(GrantId::class),
                '#/properties/locales/items' => ValueBinding::value(Locale::class),
                '#/properties/node' => $id(NodeId::class),
                '#/properties/role' => $id(RoleId::class),
            ]),
            self::command('grant.revoke.v1.json', 'RevokeGrantCodecV1', RevokeGrant::class, [
                '#/properties/grant' => $id(GrantId::class),
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
            self::command('role.create.v1.json', 'CreateRoleCodecV1', CreateRole::class, [
                '#/properties/ceiling' => ValueBinding::enum(ClassificationAccess::class),
                '#/properties/handle' => ValueBinding::value(RoleHandle::class),
                '#/properties/permissions/items' => ValueBinding::value(CommandName::class),
                '#/properties/role' => $id(RoleId::class),
            ]),
            self::command('role.set_permissions.v1.json', 'SetRolePermissionsCodecV1', SetRolePermissions::class, [
                '#/properties/permissions/items' => ValueBinding::value(CommandName::class),
                '#/properties/role' => $id(RoleId::class),
                '#/properties/version' => $version,
            ]),
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

        $queries = array_values(array_filter($contracts, static fn (CodecContract $contract): bool => $contract->query instanceof CodecQuery));

        if ($queries !== []) {
            self::assertResults($queries, $contracts);
            $list = self::queryCodecs($queries, $location);
            $files[$list->path] = $list;
        }

        ksort($files, SORT_STRING);

        return new GenerationResult(array_values($files), [$location->directory]);
    }

    /**
     * The objects of a path explanation whose document is at $root and whose definitions are below
     * $defs, as path-explanation.v1.json has them and as a document that holds one has them.
     *
     * @return array<string, string>
     */
    private static function pathExplanationObjects(string $root, string $defs): array
    {
        return [
            $root => PathExplanation::class,
            $defs.'canonical_step' => CanonicalStep::class,
            $defs.'mount_step' => MountStep::class,
            $defs.'node_step' => NodeStep::class,
            $defs.'placement_step' => PlacementStep::class,
            $defs.'route_step' => RouteStep::class,
            $defs.'site_step' => SiteStep::class,
            $defs.'time_window' => TimeWindow::class,
            $defs.'visibility_step' => VisibilityStep::class,
        ];
    }

    /**
     * The bound values of a path explanation whose document is at $root and whose definitions are
     * below $defs.
     *
     * @return array<string, ValueBinding>
     */
    private static function pathExplanationValues(string $root, string $defs): array
    {
        $id = static fn (string $class): ValueBinding => ValueBinding::id($class);

        return [
            $root.'/properties/outcome' => ValueBinding::enum(ResolveOutcome::class),
            $defs.'canonical_step/properties/placement' => $id(PlacementId::class),
            $defs.'mount_step/properties/mount' => $id(NodeId::class),
            $defs.'mount_step/properties/source' => $id(NodeId::class),
            $defs.'node_step/properties/kind' => ValueBinding::enum(NodeKind::class),
            $defs.'node_step/properties/node' => $id(NodeId::class),
            $defs.'placement_step/properties/entry' => $id(EntryId::class),
            $defs.'placement_step/properties/looked_under' => $id(NodeId::class),
            $defs.'placement_step/properties/placement' => $id(PlacementId::class),
            $defs.'placement_step/properties/slug' => ValueBinding::value(Slug::class),
            $defs.'placement_step/properties/type' => $id(TypeId::class),
            $defs.'route_step/properties/path' => ValueBinding::value(RequestPath::class),
            $defs.'site_step/properties/handle' => ValueBinding::value(SiteHandle::class),
            $defs.'site_step/properties/host' => ValueBinding::value(Host::class),
            $defs.'site_step/properties/locale' => ValueBinding::value(Locale::class),
            $defs.'site_step/properties/site' => $id(SiteId::class),
            $defs.'visibility_step/properties/decision' => ValueBinding::enum(VisibilityDecision::class),
            $defs.'visibility_step/properties/lifecycle' => ValueBinding::enum(EntryLifecycle::class),
            $defs.'visibility_step/properties/release' => ValueBinding::enum(ReleaseState::class),
            $defs.'visibility_step/properties/stored' => ValueBinding::enum(Visibility::class),
        ];
    }

    /**
     * The bindings of version 1 of a query: its document's schema `<name>.v1.json`, bound to the
     * query's class, and its result's `<name>.result.v1.json`, whose objects and values are given
     * in full.
     *
     * @param  class-string  $query
     * @param  array<string, ValueBinding>  $values  the bound values of the query's document
     * @param  array<string, string>  $resultObjects  the objects of the result, its document at `#` included
     * @param  array<string, ValueBinding>  $resultValues
     * @return list<SchemaBinding>
     */
    private static function query(string $name, string $query, string $codecClass, array $values, string $resultCodec, array $resultObjects, array $resultValues): array
    {
        return [
            new SchemaBinding(
                schema: $name.'.v1.json',
                codecClass: $codecClass,
                version: 1,
                objects: ['#' => $query],
                values: $values,
                directory: self::QUERY_SCHEMA_DIRECTORY,
                query: $query,
                resultCodec: $resultCodec,
            ),
            new SchemaBinding(
                schema: $name.'.result.v1.json',
                codecClass: $resultCodec,
                version: 1,
                objects: $resultObjects,
                values: $resultValues,
                directory: self::QUERY_SCHEMA_DIRECTORY,
                resultOf: $query,
            ),
        ];
    }

    /**
     * Refuses a query whose result codec is not the codec of a result of that query among the
     * contracts.
     *
     * @param  list<CodecContract>  $queries
     * @param  list<CodecContract>  $contracts
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function assertResults(array $queries, array $contracts): void
    {
        foreach ($queries as $query) {
            $name = $query->query?->name;
            $codec = $query->query?->resultCodec;
            $results = array_filter(
                $contracts,
                static fn (CodecContract $contract): bool => $contract->codecClass === $codec
                    && $contract->result instanceof CodecQueryResult
                    && $contract->result->query === $name
                    && $contract->version === $query->version,
            );

            if ($results === []) {
                throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('The query %s version %d names the codec %s of its result, which is not the codec of a schema of its result at that version.', (string) $name, $query->version, (string) $codec));
            }
        }
    }

    /**
     * The class QUERY_CODECS: the QueryCodec of each query's contract version, sorted by the query's
     * name and version, which the core registers under QueryCodecs::TAG.
     *
     * @param  non-empty-list<CodecContract>  $queries
     */
    private static function queryCodecs(array $queries, PhpLocation $location): GeneratedFile
    {
        usort($queries, static fn (CodecContract $a, CodecContract $b): int => [$a->query?->name, $a->version] <=> [$b->query?->name, $b->version]);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace '.$location->namespace.';',
            '',
            ...PhpSource::uses([self::ATTRIBUTE, self::QUERY_CODEC], $location->namespace),
            '',
            '/**',
            ' * The QueryCodec of each version of each of the kernel\'s queries (GUARDRAILS 2.1, 2.2), which the',
            ' * core registers under the container tag QueryCodecs::TAG, so every exposed surface reads the',
            ' * query, writes its result and describes both with their JSON Schemas.',
            ' *',
            ' * Generated by composer generate:protocol from the schemas in '.self::QUERY_SCHEMA_DIRECTORY.'.',
            ' * Do not edit this file: change the schemas and run composer generate:protocol.',
            ' */',
            '#['.PhpSource::shortName(self::ATTRIBUTE).']',
            'final readonly class '.self::QUERY_CODECS,
            '{',
            '    /**',
            '     * @return list<QueryCodec>',
            '     */',
            '    public static function all(): array',
            '    {',
            '        return [',
            ...array_map(static fn (CodecContract $contract): string => '            '.$contract->codecClass.'::queryCodec(),', $queries),
            '        ];',
            '    }',
            '}',
        ];

        return new GeneratedFile($location->directory.'/'.self::QUERY_CODECS.'.php', implode("\n", $lines)."\n");
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
