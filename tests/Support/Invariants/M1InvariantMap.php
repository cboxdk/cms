<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Invariants;

/**
 * The invariants of PRD 6.5 that block M1 touches, each with the tests that try to break it and
 * the issuers they try it through (GUARDRAILS 9 and 11).
 *
 * PRD 6.5 wants every invariant tried through every command issuer; until the property-based
 * generator does that, this map is the record of which issuers each invariant is tried through,
 * and InvariantCoverage::envelopeIssuersMissing() names the rest. tests/Feature/Invariants/
 * InvariantCoverageTest.php fails when an invariant of TOUCHED has no test, and when a mapped test
 * does not exist, is in no suite of gate 5 or is skipped.
 */
final readonly class M1InvariantMap
{
    /** The invariants M1 touches, by their number in PRD 6.5. */
    public const array TOUCHED = [1, 2, 3, 4, 5, 6, 10, 11, 12, 13, 14, 15, 18, 21, 22, 25, 36, 37];

    private const string COMMITTER = 'packages/core/tests/Postgres/PostgresChangesetCommitterTest.php';

    private const string PIPELINE = 'packages/core/tests/Actions/CommandPipelineTest.php';

    private const string HOOKS = 'packages/core/tests/Actions/CommandPipelineHooksTest.php';

    private const string SURFACES = 'tests/Feature/Surfaces/SurfaceContractTest.php';

    private const string STORAGE = 'packages/core/tests/Postgres/StorageSchemaTest.php';

    private const string REVISION_STORAGE = 'packages/core/tests/Postgres/RevisionStorageSchemaTest.php';

    private const string RELEASE = 'packages/core/tests/Actions/ReleaseVariantTest.php';

    private const string PLACEMENTS = 'packages/core/tests/Postgres/PlacementCommandsTest.php';

    private const string SEED = 'packages/core/tests/Actions/SeedEntriesTest.php';

    private const string MCP = 'packages/mcp/tests/McpSurfaceTest.php';

    private const string CLI = 'packages/cli/tests/Console/RunCommandTest.php';

    private const string EXPOSED = 'packages/core/tests/Actions/RunExposedCommandTest.php';

    private const string ADDON = 'tests/Postgres/WalkingSkeleton/AddonExtensionTest.php';

    private const string DEACTIVATED = 'tests/Postgres/WalkingSkeleton/DeactivatedActorTest.php';

    /**
     * @return list<InvariantCoverage>
     */
    public static function map(): array
    {
        $kernel = [Issuer::Kernel];
        $database = [Issuer::Database];
        $static = [Issuer::StaticRule];
        $surfaces = [Issuer::Rest, Issuer::Inertia, Issuer::Mcp, Issuer::Cli];

        return [
            new InvariantCoverage(1, 'Every change of state goes through the kernel and gives exactly one changeset.', [
                TestReference::of('packages/core/tests/Postgres/PublishingCommandsTest.php', 'publishes and unpublishes, each in exactly one changeset with the events of both sub-plans, and publishes again', $kernel),
                TestReference::of('tests/Postgres/WalkingSkeleton/IdempotentRepeatsTest.php', 'commits five repeats with one key as one changeset and answers each with the first receipt', $kernel),
                TestReference::of(self::SEED, 'plans every entry of the chunk in one changeset, one step of every entry after the other', [Issuer::Seed]),
                TestReference::of('packages/cli/tests/Postgres/RunEntryCreateTest.php', 'creates an entry of a workbench fixture type through cms:run entry.create 1 as the configured service actor', [Issuer::Cli]),
                TestReference::of(self::STORAGE, 'lets the app role read no rows and write none without an actor context, and run no DDL on the tables', $database),
                TestReference::of('packages/testkit/tests/Phpstan/KernelTableWriteRuleTest.php', 'test_it_reports_every_write_to_a_kernel_table_outside_the_kernel_as_non_ignorable', $static),
                TestReference::of('tests/Arch/FixtureWritersTest.php', 'fixture writers: no production module or the workbench uses the testkit\'s fixture writers', $static),
                TestReference::of('tests/Postgres/KernelTablesTest.php', 'lists every table and LIST partition the migrations built, and nothing else', $database),
            ]),
            new InvariantCoverage(2, 'A changeset commits atomically with its revisions, head changes, placements, audit and events.', [
                TestReference::of(self::COMMITTER, 'commits the changeset, its principals, reason, audit, mutations, events, idempotency record and receipt together', $kernel),
                TestReference::of(self::COMMITTER, 'rolls back everything when a mutation writer fails after an earlier mutation was written, and leaves the key fresh', $kernel),
                TestReference::of(self::COMMITTER, 'answers partition_missing and keeps nothing when no partition takes the receipt, after the events were written', $kernel),
                TestReference::of(self::COMMITTER, 'writes the events of a seed on the bulk stream', [Issuer::Seed]),
                TestReference::of(self::PIPELINE, 'runs the call in a command transaction with the call\'s access context', $kernel),
            ]),
            new InvariantCoverage(3, 'Revisions are immutable.', [
                TestReference::of(self::REVISION_STORAGE, 'narrows the app role to SELECT and INSERT, and UPDATE on the head snapshots alone, on every level of the partitioned tables', $database),
                TestReference::of(self::REVISION_STORAGE, 'lets the app role read no rows, insert none and change none without an actor context, and run no DDL on the tables', $database),
                TestReference::of('packages/core/tests/Postgres/VariantReleaseTest.php', 'keeps a draft that differs from the released row, numbers the next revision after the published one, and releases it', $kernel),
            ]),
            new InvariantCoverage(4, 'A revision validates against the schema version it is written under.', [
                TestReference::of(self::PIPELINE, 'rejects invalid fields with validation_failed and each field error, and commits nothing', $kernel),
                TestReference::of('packages/core/tests/Postgres/EntryCommandsTest.php', 'rejects fields that break the type\'s rules, and a value for an encrypted field, and keeps nothing', $kernel),
                TestReference::of(self::SURFACES, 'it_keeps_the_surface_contract_of_the_action_on_the_surface', $surfaces),
                TestReference::of(self::CLI, 'rejects a validation error with the catalog\'s exit code and the problem with every code and path', [Issuer::Cli]),
            ]),
            new InvariantCoverage(5, 'A release requires the revision to validate against its own schema version and the release policies.', [
                TestReference::of(self::RELEASE, 'rejects a revision that does not validate at the release stage, with the errors below the revision', $kernel),
                TestReference::of(self::RELEASE, 'rejects a revision the variant does not have, and one written under another schema version than the type\'s', $kernel),
                TestReference::of('packages/core/tests/Postgres/VariantReleaseTest.php', 'rejects a release of a type with stages none, a stale version, a revision the variant does not have and one that breaks the type\'s rules, and keeps nothing', $kernel),
                TestReference::of(self::SEED, 'validates the release of a revision the chunk creates at the release stage, as the plan holds it', [Issuer::Seed]),
                TestReference::of(self::ADDON, 'rejects a release without the addon\'s field with the validate hook, and only the release', $kernel),
            ]),
            new InvariantCoverage(6, 'A placement can be live only while the entry is active and the variant has a released revision.', [
                TestReference::of('packages/core/tests/Actions/ResolvePathTest.php', 'decides the precedence strongest first: entry, variant, placement withdrawn, release, then the window', $kernel),
                TestReference::of('packages/core/tests/Postgres/PublishingCommandsTest.php', 'takes the release back: the head, the release log and the type table\'s rows, and hides the placements', $kernel),
                TestReference::of('packages/core/tests/Actions/PublishEntryTest.php', 'refuses a publish without a revision for a type with stages', $kernel),
                TestReference::of('packages/core/tests/Postgres/AccessPoliciesTest.php', 'lets each actor read what its regions reach, its own rows and what is public, and the anonymous context only what is released with a live placement', $database),
            ]),
            new InvariantCoverage(10, 'Fields above public never reach edge-cacheable answers or telemetry, and events and audit hold no text of content.', [
                TestReference::of('packages/http/tests/Postgres/DeliveryResolveTest.php', 'it_leaves_the_confidential_and_the_internal_field_of_the_type_out_of_the_body', [Issuer::Delivery]),
                TestReference::of('packages/core/tests/Actions/DeliverPathTest.php', 'answers a resolved path with its record at the public access, and stores it as a fragment with its content keys', [Issuer::Delivery]),
                TestReference::of('packages/core/tests/Actions/PipelineTelemetryTest.php', 'puts no field value of a command in any attribute, committed or rejected', $kernel),
                TestReference::of('packages/core/tests/Actions/PipelineTelemetryTest.php', 'puts no field value a query read in any attribute, for an actor or the anonymous principal', $kernel),
                TestReference::of('packages/core/tests/Actions/QueryPipelineTest.php', 'reads as the anonymous principal without a credential, and strips every field above public', $kernel),
                TestReference::of('packages/testkit/tests/Phpstan/EventPayloadTextRuleTest.php', 'test_it_reports_payload_properties_that_can_hold_text_or_that_an_event_cannot_carry', $static),
                TestReference::of('packages/contracts/tests/Events/EventValuesTest.php', 'refuses an id with a space, a byte outside visible ASCII or no characters, and never repeats it', $kernel),
                TestReference::of(self::REVISION_STORAGE, 'keeps the on-behalf-of chain in order, with principals that exist, and the reason\'s text classified', $database),
            ]),
            new InvariantCoverage(11, 'Commands with expected versions fail on a mismatch.', [
                TestReference::of('tests/Postgres/WalkingSkeleton/ConcurrentSavesTest.php', 'commits one of two concurrent revises of an entry and rejects the other with version_conflict', $kernel),
                TestReference::of('tests/Postgres/WalkingSkeleton/ConcurrentSavesTest.php', 'commits one of two concurrent creates of one entry id and rejects the other with version_conflict', $kernel),
                TestReference::of(self::PIPELINE, 'rejects a command whose expected version is not the one resolve() read, before it authorizes', $kernel),
                TestReference::of(self::SURFACES, 'it_keeps_the_surface_contract_of_the_action_on_the_surface', $surfaces),
                TestReference::of(self::MCP, 'answers a version conflict as a tool error with version_conflict', [Issuer::Mcp]),
                TestReference::of(self::CLI, 'rejects a version conflict with the catalog\'s exit code', [Issuer::Cli]),
                TestReference::of(self::SEED, 'answers version_conflict when an entry of the chunk was created after it was read', [Issuer::Seed]),
                TestReference::of(self::PLACEMENTS, 'commits one of two placements that claim one slug at the same time and rejects the other with version_conflict', $kernel),
            ]),
            new InvariantCoverage(12, 'A hook cannot change actor, grants or classification, nor get past the invariants; the kernel validates after the transforms.', [
                TestReference::of(self::HOOKS, 'refuses a transform that changes what a hook may not change, and commits nothing', $kernel),
                TestReference::of(self::HOOKS, 'validates the plan again after the transforms, so a transform cannot commit fields that break a rule', $kernel),
                TestReference::of(self::HOOKS, 'adds a validate hook\'s errors after the kernel\'s, which it cannot remove', $kernel),
                TestReference::of(self::ADDON, 'changes the plan of entry.create with the transform hook, and stores the addon\'s slug beside the owner\'s', $kernel),
            ]),
            new InvariantCoverage(13, 'No network IO in the command transaction.', [
                TestReference::of('packages/testkit/tests/Phpstan/HookIoRuleTest.php', 'test_it_reports_io_in_a_hook_as_non_ignorable_and_leaves_other_classes_alone', $static),
                TestReference::of('tests/Arch/HygieneTest.php', 'raw HTTP: no Guzzle, Http facade, HTTP client, curl_*, sockets or file_get_contents outside the gateway namespace', $static),
                TestReference::of('tests/Arch/HygieneTest.php', 'egress: no URL-capable file function, socket, stream context, process or XML loader outside the gateway namespace', $static),
            ]),
            new InvariantCoverage(14, 'At most one canonical placement per entry and locale, and exactly one while a placement is visible.', [
                TestReference::of(self::PLACEMENTS, 'places one entry on two sites and keeps exactly one canonical placement when both are visible', $kernel),
                TestReference::of(self::PLACEMENTS, 'moves the canonical flag off a placement below a node the actor cannot reach', $kernel),
                TestReference::of('packages/core/tests/Actions/SetPlacementWindowTest.php', 'moves the canonical flag to the placement that becomes visible when the canonical one is not', $kernel),
                TestReference::of('packages/core/tests/Actions/CreatePlacementTest.php', 'plans a hidden placement with its slugs, canonical in each locale where the entry has none, and reads what it decides on', $kernel),
                TestReference::of(self::STORAGE, 'allows at most one canonical placement per entry and locale, per stage, and never a withdrawn one', $database),
            ]),
            new InvariantCoverage(15, '(node, locale, slug) is unique among placements that are not withdrawn.', [
                TestReference::of(self::PLACEMENTS, 'refuses a slug another placement has below the node, and a node outside the actor\'s regions', $kernel),
                TestReference::of(self::PLACEMENTS, 'commits one of two placements that claim one slug at the same time and rejects the other with version_conflict', $kernel),
                TestReference::of('packages/core/tests/Actions/CreatePlacementTest.php', 'rejects a slug another placement has below the node in the locale, and commits nothing', $kernel),
                TestReference::of('packages/core/tests/Actions/CreatePlacementTest.php', 'takes a slug a withdrawn placement had below the node', $kernel),
                TestReference::of(self::STORAGE, 'keeps (node, locale, slug) unique among the placements that are not withdrawn, per stage', $database),
            ]),
            new InvariantCoverage(18, 'Agent and token actors cannot run commands that change public visibility.', [
                TestReference::of(self::RELEASE, 'rejects a release by an agent\'s credential, and by an envelope that records an agent, as agent_visibility_forbidden (invariant 18)', $kernel),
                TestReference::of('packages/core/tests/Actions/SetPlacementWindowTest.php', 'rejects a window from an agent, now or later, and lets an agent hide a placement', $kernel),
                TestReference::of('packages/core/tests/Actions/PublishEntryTest.php', 'rejects an agent, which may not make content public', $kernel),
                TestReference::of('packages/core/tests/Actions/CreatePlacementTest.php', 'lets an agent create a placement, which is hidden', $kernel),
            ]),
            new InvariantCoverage(21, 'The kernel hands an addon only what its capabilities allow, and its subscribers run as its own service identity.', [
                TestReference::of(self::HOOKS, 'gives an addon\'s hook without the capability for an aggregate\'s fields a view without them, and one with it a view with them (invariant 21)', $kernel),
                TestReference::of(self::HOOKS, 'never gives an addon\'s hook more than the actor may read, whatever its manifest allows', $kernel),
                TestReference::of(self::HOOKS, 'refuses a transform of an addon\'s hook on a field above what its manifest lets it read, though the actor may read it', $kernel),
                TestReference::of('packages/core/tests/Actions/ResolveSubscriberActorTest.php', 'runs an addon\'s subscriber as the addon\'s own active service actor', [Issuer::Subscriber]),
                TestReference::of('packages/core/tests/Actions/ResolveSubscriberActorTest.php', 'refuses to run an addon\'s subscriber as an actor that is not an active service actor', [Issuer::Subscriber]),
                TestReference::of('packages/core/tests/Postgres/EventRunnerTest.php', 'runs an addon\'s subscriber under the addon\'s own service actor and a kernel subscriber under the runner\'s, on the batch\'s connection (invariant 21)', [Issuer::Subscriber]),
                TestReference::of('packages/core/tests/Postgres/EventRunnerTest.php', 'refuses an addon\'s subscriber without an active service actor and never runs it as the runner\'s actor', [Issuer::Subscriber]),
                TestReference::of('packages/core/tests/Actions/RunLaneTest.php', 'runs each subscription under its own actor: an addon\'s as the addon\'s service actor, the others as the runner\'s', [Issuer::Subscriber]),
                TestReference::of('packages/core/tests/Actions/BuildRegistryTest.php', 'refuses a hook of the addon that its manifest does not allow, and writes nothing', $static),
                TestReference::of('packages/core/tests/Actions/BuildRegistryTest.php', 'refuses a subscriber of the addon that receives an event on a lane its manifest does not allow', $static),
            ]),
            new InvariantCoverage(22, 'Type tables are derived: every row can be rebuilt from the head\'s revisions or snapshot, to the same row.', [
                TestReference::of('tests/Postgres/WalkingSkeleton/RebuildReadModelsTest.php', 'rebuilds fixture_article from the heads\' revisions with the same values in the released and the draft stage', $kernel),
                TestReference::of('tests/Postgres/WalkingSkeleton/RebuildReadModelsTest.php', 'rebuilds fixture_measurement from the head snapshots with the same values', $kernel),
                TestReference::of('tests/Postgres/WalkingSkeleton/ThirdTypeTest.php', 'rebuilds the event type table from the head snapshots with the same rows', $kernel),
                TestReference::of('packages/core/tests/Actions/RebuildReadModelsTest.php', 'rebuilds the type in chunks of the chunk size as an operation, under the service actor\'s context', $kernel),
            ]),
            new InvariantCoverage(25, 'Public writes go through commands with an anonymous or end-user actor; the anonymous principal reads only what is published.', [
                TestReference::of('packages/contracts/tests/Identity/PrincipalTest.php', 'gives the anonymous principal public classification access', $kernel),
                TestReference::of('packages/core/tests/Postgres/AccessPoliciesTest.php', 'holds an actor\'s writes to its regions and the anonymous context to none', $database),
                TestReference::of('packages/core/tests/Postgres/PathResolutionTest.php', 'resolves a placement on its site and through a mount on a second site, as the anonymous principal', $kernel),
                TestReference::of(self::EXPOSED, 'rejects a call without a credential as unauthorized, and one with a refused credential with the verifier\'s code', $kernel),
                TestReference::of(self::CLI, 'refuses a run without a configured credential as unauthorized, with the catalog\'s exit code', [Issuer::Cli]),
                TestReference::of(self::MCP, 'refuses a call without an agent\'s credential as unauthorized, and commits nothing', [Issuer::Mcp]),
                TestReference::of('packages/http/tests/Postgres/DeliveryResolveTest.php', 'it_resolves_the_published_article_on_its_site_and_through_the_mount_with_the_record_the_keys_and_the_cache_headers', [Issuer::Delivery]),
            ]),
            new InvariantCoverage(36, 'An extension only adds fields in its own namespace, and an extension field is required only at release and workflow transitions.', [
                TestReference::of('packages/generators/tests/Validators/PhpTypeValidatorsTest.php', 'requires the owner\'s required fields on every write, and a required extension field only on release', $static),
                TestReference::of('packages/generators/tests/Schema/YamlBlueprintRulesTest.php', 'rejects an extension of a type of its own owner with generate_extension_of_own_type', $static),
                TestReference::of('packages/generators/tests/Generation/SchemaResolverTest.php', 'encodes an extension field as ext__<namespace>__<handle>, which no handle of the owner can be', $static),
                TestReference::of(self::ADDON, 'rejects a release without the addon\'s field with the validate hook, and only the release', $kernel),
                TestReference::of(self::ADDON, 'puts the addon\'s field under its namespace in the type table, the record, the DTO and TypeScript, beside the owner\'s field of the same handle', $kernel),
                TestReference::of('workbench/addons/fixtureaddon/tests/Unit/RequireSlugOnReleaseTest.php', 'it_requires_the_slug_of_a_released_article', $kernel),
            ]),
            new InvariantCoverage(37, 'Once an actor is deactivated, no command as it or on its behalf commits, and its credentials are refused.', [
                TestReference::of(self::DEACTIVATED, 'rejects a command as a deactivated actor', $kernel),
                TestReference::of(self::DEACTIVATED, 'rejects a command on behalf of a deactivated actor', $kernel),
                TestReference::of(self::DEACTIVATED, 'fails a command in flight with version_conflict when a deactivation of its actor commits meanwhile', $kernel),
                TestReference::of(self::DEACTIVATED, 'rejects a read as a deactivated actor through the query pipeline', $kernel),
                TestReference::of('packages/core/tests/Postgres/PostgresCredentialVerifierTest.php', 'refuses a credential after its actor\'s generation is increased', $kernel),
                TestReference::of('packages/core/tests/Postgres/PostgresCredentialVerifierTest.php', 'refuses an agent credential when the person it acts for is deactivated', $kernel),
                TestReference::of(self::PIPELINE, 'rejects an actor that is not active before it resolves anything', $kernel),
            ]),
        ];
    }
}
