<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * The testkit's shared contract suites, which mutation on changed files leaves out (Sylvester's
 * decision of 1 October, settling M0-T47; GUARDRAILS 7.3). A suite is a trait named *Contract in
 * packages/testkit/src that a test class per implementation uses, such as ReceiptStoreContract:
 * it is test code shipped in src so addons can run it, and its mutations remove assertions,
 * which no test of the kernel can catch without testing the tests. Every other class keeps the
 * minimum score. tests/Feature/Tooling/Mutation/SharedContractSuitesTest.php fails when the list
 * names a file that is not such a suite, or when such a suite is missing from it, so no production
 * class can be left out here.
 */
final readonly class SharedContractSuites
{
    /**
     * The repository-relative path of each suite, sorted.
     *
     * @var list<string>
     */
    public const array PATHS = [
        'packages/testkit/src/Cache/FragmentStoreContract.php',
        'packages/testkit/src/Cdn/CdnDriverContract.php',
        'packages/testkit/src/Clock/ClockContract.php',
        'packages/testkit/src/Codecs/RecordCodecsContract.php',
        'packages/testkit/src/Doctor/DoctorCheckContract.php',
        'packages/testkit/src/Egress/EgressGatewayContract.php',
        'packages/testkit/src/Egress/MailGatewayContract.php',
        'packages/testkit/src/Idempotency/IdempotencyStoreContract.php',
        'packages/testkit/src/Identity/ActorDirectoryContract.php',
        'packages/testkit/src/Identity/BreachedPasswordsContract.php',
        'packages/testkit/src/Identity/CredentialVerifierContract.php',
        'packages/testkit/src/Identity/LocalCredentialStoreContract.php',
        'packages/testkit/src/Identity/SessionCredentialContract.php',
        'packages/testkit/src/Ids/IdGeneratorContract.php',
        'packages/testkit/src/Login/IssuerResolverContract.php',
        'packages/testkit/src/Login/LoginConnectionContract.php',
        'packages/testkit/src/Panel/PanelContributionsContract.php',
        'packages/testkit/src/Provisioning/ScimProvisioningContract.php',
        'packages/testkit/src/ReceiptStore/ReceiptStoreContract.php',
        'packages/testkit/src/Schema/TypeCatalogContract.php',
        'packages/testkit/src/Signals/BackChannelLogoutContract.php',
        'packages/testkit/src/Signals/SecurityEventReceiverContract.php',
        'packages/testkit/src/Telemetry/TelemetryContract.php',
        'packages/testkit/src/TypeTables/TypeTableReaderContract.php',
        'packages/testkit/src/Validation/TypeValidatorsContract.php',
    ];

    /**
     * Whether mutation on changed files leaves the source at $path out.
     */
    public static function leavesOut(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }
}
