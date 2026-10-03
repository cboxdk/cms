<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Codec\Domain\TypeScriptEmitter;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;
use Cbox\Cms\Panel\Domain\Dto\ContributionsProp;
use Cbox\Cms\Panel\Domain\Dto\FillProp;
use Cbox\Cms\Panel\Domain\Dto\ForgotPasswordPage;
use Cbox\Cms\Panel\Domain\Dto\ForgotPasswordRefusals;
use Cbox\Cms\Panel\Domain\Dto\HomePage;
use Cbox\Cms\Panel\Domain\Dto\LoginPage;
use Cbox\Cms\Panel\Domain\Dto\LoginRefusals;
use Cbox\Cms\Panel\Domain\Dto\NotFoundPage;
use Cbox\Cms\Panel\Domain\Dto\PointFillsProp;
use Cbox\Cms\Panel\Domain\Dto\ResetPasswordPage;
use Cbox\Cms\Panel\Domain\Dto\ResetPasswordRefusals;
use Cbox\Cms\Panel\Domain\ForgotPasswordRefusal;
use Cbox\Cms\Panel\Domain\LoginRefusal;
use Cbox\Cms\Panel\Domain\ResetFormRefusal;
use Cbox\Cms\Panel\Domain\ResetPasswordRefusal;
use Cbox\Cms\Panel\Domain\SignInReason;

/**
 * The props of the panel's pages (GUARDRAILS 2.2, 2.4): one JSON Schema per page and contract
 * version in packages/panel/resources/schemas/pages, bound to the page's DTO in the panel's
 * Domain\Dto, so the panel renders the props through the generated codec and js/panel imports
 * their generated TypeScript types and validators. contributions.v1.json is the prop
 * cms.contributions that every page behind the login sends beside its own props (PRD 13.4). Types go one way, from PHP and the schema to
 * TypeScript; no page declares its props by hand.
 *
 * composer generate:protocol writes, from these bindings, the PHP codecs into PHP_DIRECTORY and,
 * into TYPESCRIPT_DIRECTORY, the runtime module of the validators and a module per page,
 * `pages/<Page>V<version>.ts`. The bindings live in the repository's tooling, not in the generators
 * module, because the generators may not use the panel.
 */
final readonly class PanelPageSchemas
{
    /** Where the schemas are, relative to the root of cboxdk/cms. */
    public const string SCHEMA_DIRECTORY = 'packages/panel/resources/schemas/pages';

    /** Where the codecs go, relative to the root of cboxdk/cms; composer generate:protocol owns it. */
    public const string PHP_DIRECTORY = 'packages/panel/src/Boundary/Generated';

    public const string PHP_NAMESPACE = 'Cbox\Cms\Panel\Boundary\Generated';

    /** Where the TypeScript goes, relative to the root of cboxdk/cms; composer generate:protocol owns it. */
    public const string TYPESCRIPT_DIRECTORY = 'js/panel/src/generated';

    /** The runtime module of the validators, below TYPESCRIPT_DIRECTORY. */
    public const string RUNTIME = 'validation.ts';

    /** The directory of the pages' modules, below TYPESCRIPT_DIRECTORY. */
    public const string PAGES = 'pages';

    /** The stability of the generated codecs: the pages' DTOs are the panel's own. */
    public const string ATTRIBUTE = Internal::class;

    /**
     * The binding of each page's schema, sorted by file.
     *
     * @return list<SchemaBinding>
     */
    public static function all(): array
    {
        return [
            self::page('contributions.v1.json', 'ContributionsCodecV1', [
                '#' => ContributionsProp::class,
                '#/$defs/fill' => FillProp::class,
                '#/$defs/point' => PointFillsProp::class,
            ], [
                '#/$defs/fill/properties/addon' => ValueBinding::value(AddonNamespace::class),
                '#/$defs/fill/properties/id' => ValueBinding::value(ContributionId::class),
                '#/$defs/fill/properties/kind' => ValueBinding::enum(PointKind::class),
                '#/$defs/fill/properties/props' => ValueBinding::document(JsonDocument::class),
            ]),
            self::page('forgot-password.v1.json', 'ForgotPasswordPageCodecV1', [
                '#' => ForgotPasswordPage::class,
                '#/$defs/refusals' => ForgotPasswordRefusals::class,
            ], [
                '#/$defs/refusals/properties/email' => ValueBinding::enum(ForgotPasswordRefusal::class),
            ]),
            self::page('home.v1.json', 'HomePageCodecV1', ['#' => HomePage::class]),
            self::page('login.v1.json', 'LoginPageCodecV1', [
                '#' => LoginPage::class,
                '#/$defs/refusals' => LoginRefusals::class,
            ], [
                '#/properties/reason' => ValueBinding::enum(SignInReason::class),
                '#/$defs/refusals/properties/email' => ValueBinding::enum(LoginRefusal::class),
                '#/$defs/refusals/properties/form' => ValueBinding::enum(LoginRefusal::class),
                '#/$defs/refusals/properties/password' => ValueBinding::enum(LoginRefusal::class),
            ]),
            self::page('not-found.v1.json', 'NotFoundPageCodecV1', ['#' => NotFoundPage::class]),
            self::page('reset-password.v1.json', 'ResetPasswordPageCodecV1', [
                '#' => ResetPasswordPage::class,
                '#/$defs/refusals' => ResetPasswordRefusals::class,
            ], [
                '#/$defs/refusals/properties/form' => ValueBinding::enum(ResetFormRefusal::class),
                '#/$defs/refusals/properties/password' => ValueBinding::enum(ResetPasswordRefusal::class),
            ]),
        ];
    }

    /**
     * The codec of each page in PHP_DIRECTORY, and in TYPESCRIPT_DIRECTORY the runtime module and
     * each page's module, which the result owns.
     *
     * @param  list<CodecContract>  $contracts
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput or GenerateErrorCode::NameCollision
     */
    public static function result(array $contracts, string $runtime): GenerationResult
    {
        $location = new PhpLocation(self::PHP_DIRECTORY, self::PHP_NAMESPACE);
        $files = [self::TYPESCRIPT_DIRECTORY.'/'.self::RUNTIME => new GeneratedFile(self::TYPESCRIPT_DIRECTORY.'/'.self::RUNTIME, $runtime)];

        foreach ($contracts as $contract) {
            $codec = PhpCodecEmitter::emit($contract, $location, $location);
            $name = (string) preg_replace('/Codec(V[0-9]+)\z/', '$1', $contract->codecClass);
            $module = TypeScriptEmitter::emit(
                $contract,
                self::TYPESCRIPT_DIRECTORY.'/'.self::PAGES.'/'.$name.'.ts',
                '../validation',
                [
                    sprintf('The props of a page of the panel, %s, as TypeScript (GUARDRAILS 2.2): their JSON', $name),
                    sprintf('form, which the panel\'s codec %s writes, and a validator that checks a JSON', $contract->codecClass),
                    'value against every rule of the page\'s JSON Schema.',
                    '',
                    'Generated by composer generate:protocol from the schemas in '.self::SCHEMA_DIRECTORY.'.',
                    'Do not edit this file: change the schema and run composer generate:protocol.',
                ],
            );

            foreach ([$codec, $module] as $file) {
                if (isset($files[$file->path])) {
                    throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('Two page schemas give the file %s; give each page and contract version its own codec.', $file->path));
                }

                $files[$file->path] = $file;
            }
        }

        ksort($files, SORT_STRING);

        return new GenerationResult(array_values($files), [self::TYPESCRIPT_DIRECTORY, self::PHP_DIRECTORY]);
    }

    /**
     * The binding of a page's schema at version 1.
     *
     * @param  array<string, string>  $objects
     * @param  array<string, ValueBinding>  $values
     */
    private static function page(string $schema, string $codecClass, array $objects, array $values = []): SchemaBinding
    {
        return new SchemaBinding(
            schema: $schema,
            codecClass: $codecClass,
            version: 1,
            objects: $objects,
            values: $values,
            directory: self::SCHEMA_DIRECTORY,
        );
    }
}
