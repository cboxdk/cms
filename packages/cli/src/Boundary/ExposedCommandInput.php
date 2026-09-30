<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliActions;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\InvalidCommandName;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Reads a call of cms:run into the ExposedCall the kernel runs (GUARDRAILS 2.1, 2.2):
 *
 * 1. The command's name and version must be a write the compiled registry exposes on the CLI
 *    (CliActions), or the call is a usage error, 64, that lists the ones it exposes. A registry
 *    cache that cannot be read refuses the call with registry_cache_missing or
 *    registry_cache_malformed.
 * 2. The command's document is read later, by the command's generated codec from CommandCodecs, at
 *    the classification access of the verified actor.
 * 3. The envelope options, --idempotency-key, --dry-run and --wait-level, are written as the JSON
 *    object of envelope.v1.json and read through the envelope's generated codec, so the CLI reads
 *    them by the same rules as every other surface; a value it refuses is json_invalid at the path
 *    envelope.<field>, such as envelope.wait_level. The on-behalf-of chain and the provenance are
 *    no options: the chain comes from the credential.
 * 4. The credential is the process's configured service credential (CliCredential), never an
 *    argument or an option.
 */
#[Internal]
final readonly class ExposedCommandInput
{
    /** The member of a refused envelope option's path. */
    public const string ENVELOPE = 'envelope';

    /** A command's version: 1 or more, without leading zeros. */
    private const string VERSION = '/\A[1-9][0-9]{0,8}\z/';

    public function __construct(
        private Container $container,
        private Repository $config,
        private EnvelopeCodecV1 $envelopes,
        private CommandCodecs $codecs,
    ) {}

    /**
     * @throws CliCallRefused when the call cannot be run as it is
     */
    public function read(mixed $name, mixed $version, mixed $document, mixed $idempotencyKey, mixed $dryRun, mixed $waitLevel): ExposedCall
    {
        $command = $this->command($name);
        $number = $this->version($version);
        $actions = $this->actions();
        $entry = $actions->find($command, $number) ?? throw CliCallRefused::usage($this->notExposed($command, $number, $actions));

        return new ExposedCall(
            Surface::Cli,
            CliCredential::read($this->config),
            $this->envelope($idempotencyKey, $dryRun, $waitLevel),
            $this->codec($entry->command, $entry->commandVersion),
            is_string($document) ? $document : throw CliCallRefused::usage('Give the command as its JSON document, the argument document.'),
        );
    }

    private function command(mixed $name): CommandName
    {
        if (! is_string($name)) {
            throw CliCallRefused::usage('Give the name of the command, such as entry.create.');
        }

        try {
            return new CommandName($name);
        } catch (InvalidCommandName $invalid) {
            throw CliCallRefused::usage($invalid->getMessage(), $invalid);
        }
    }

    private function version(mixed $version): int
    {
        if (! is_string($version) || preg_match(self::VERSION, $version) !== 1) {
            throw CliCallRefused::usage(sprintf(
                'The version of the command must be a whole number from 1, such as 1; it is %s.',
                is_string($version) ? '"'.$version.'"' : 'missing',
            ));
        }

        return (int) $version;
    }

    private function actions(): CliActions
    {
        // The container declares no exceptions, so the registry cache's are told apart here.
        try {
            return $this->container->make(CliActions::class);
        } catch (Throwable $failed) {
            throw match (true) {
                $failed instanceof RegistryCacheMissing => CliCallRefused::catalog(ErrorCode::RegistryCacheMissing, null, $failed->getMessage(), $failed),
                $failed instanceof MalformedRegistryCache => CliCallRefused::catalog(ErrorCode::RegistryCacheMalformed, null, $failed->getMessage(), $failed),
                default => $failed,
            };
        }
    }

    private function notExposed(CommandName $command, int $version, CliActions $actions): string
    {
        $signatures = $actions->signatures();

        return sprintf(
            'The CLI exposes no version %d of the command %s. %s',
            $version,
            $command->value,
            $signatures === []
                ? 'It exposes no command: no write action lists Surface::Cli in its #[Action], or cms:build has not run since one did.'
                : 'It exposes: '.implode(', ', $signatures).'.',
        );
    }

    private function codec(CommandName $command, int $version): CommandCodec
    {
        try {
            return $this->codecs->for($command, $version);
        } catch (UnknownCommand $unknown) {
            throw CliCallRefused::software($unknown->getMessage(), $unknown);
        }
    }

    private function envelope(mixed $idempotencyKey, mixed $dryRun, mixed $waitLevel): RequestEnvelope
    {
        $json = EnvelopeOptions::document($idempotencyKey, $dryRun, $waitLevel);

        try {
            return $this->envelopes->decode($json, ClassificationAccess::Public);
        } catch (DecodingFailed $failed) {
            throw CliCallRefused::catalog(
                $failed->errorCode,
                new FieldPath(self::ENVELOPE, ...($failed->path instanceof FieldPath ? $failed->path->segments : [])),
                $failed->reason,
                $failed,
            );
        }
    }
}
