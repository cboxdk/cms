<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\PanelPoints\Tone;
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
use Cbox\Cms\Panel\Domain\Dto\AccountMePage;
use Cbox\Cms\Panel\Domain\Dto\ActionProp;
use Cbox\Cms\Panel\Domain\Dto\AddonPage;
use Cbox\Cms\Panel\Domain\Dto\AddonProp;
use Cbox\Cms\Panel\Domain\Dto\CheckProp;
use Cbox\Cms\Panel\Domain\Dto\CommandFormPage;
use Cbox\Cms\Panel\Domain\Dto\ContributionsProp;
use Cbox\Cms\Panel\Domain\Dto\DecoratorProp;
use Cbox\Cms\Panel\Domain\Dto\FillProp;
use Cbox\Cms\Panel\Domain\Dto\ForgotPasswordPage;
use Cbox\Cms\Panel\Domain\Dto\ForgotPasswordRefusals;
use Cbox\Cms\Panel\Domain\Dto\HomePage;
use Cbox\Cms\Panel\Domain\Dto\LoginPage;
use Cbox\Cms\Panel\Domain\Dto\LoginRefusals;
use Cbox\Cms\Panel\Domain\Dto\NavProp;
use Cbox\Cms\Panel\Domain\Dto\NotFoundPage;
use Cbox\Cms\Panel\Domain\Dto\PageLinkProp;
use Cbox\Cms\Panel\Domain\Dto\PaletteProp;
use Cbox\Cms\Panel\Domain\Dto\PanelBrand;
use Cbox\Cms\Panel\Domain\Dto\PanelBrandLogo;
use Cbox\Cms\Panel\Domain\Dto\PointFillsProp;
use Cbox\Cms\Panel\Domain\Dto\PrefillProp;
use Cbox\Cms\Panel\Domain\Dto\ReplacementProp;
use Cbox\Cms\Panel\Domain\Dto\ResetPasswordPage;
use Cbox\Cms\Panel\Domain\Dto\ResetPasswordRefusals;
use Cbox\Cms\Panel\Domain\Dto\StepProp;
use Cbox\Cms\Panel\Domain\ForgotPasswordRefusal;
use Cbox\Cms\Panel\Domain\LoginRefusal;
use Cbox\Cms\Panel\Domain\ResetFormRefusal;
use Cbox\Cms\Panel\Domain\ResetPasswordRefusal;
use Cbox\Cms\Panel\Domain\SignInReason;

/**
 * The props of the panel's pages (GUARDRAILS 2.2, 2.4): one JSON Schema per page and contract
 * version in packages/panel/resources/schemas/pages, and one for the brand every page shares,
 * brand.v1.json, each bound to its DTO in the panel's
 * Domain\Dto, so the panel renders the props through the generated codec and js/panel imports
 * their generated TypeScript types and validators. contributions.v1.json is the prop
 * cms.contributions that every page behind the login sends beside its own props (PRD 13.4),
 * addon-page.v1.json the props of an addon's page below /x/<namespace>/, account-me.v1.json
 * the props of the who-am-I page, whose result and rejection are documents of the kernel's
 * contracts, command-form.v1.json the props of the generic command form, whose schema is the
 * command's own JSON Schema as a document, and palette.v1.json the prop `palette` every page
 * behind the login shares, the read of action.list the command palette is built from. Types go one way, from PHP and the schema to
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

    /** The directory of the kernel contracts' modules the panel's host reads answers with, below TYPESCRIPT_DIRECTORY. */
    public const string PROTOCOL = 'protocol';

    /**
     * The kernel contracts the panel reads, by codec class: a command a contribution issues through
     * the host answers with its receipt and, for a rejection, the problem details; and the result
     * of actor.me, which the who-am-I page reads from its props.
     *
     * @var list<string>
     */
    public const array PROTOCOL_CODECS = ['ActionListCodecV1', 'ActorMeCodecV1', 'DryRunSummaryCodecV1', 'ProblemCodecV1', 'ReceiptCodecV1'];

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
            self::page('account-me.v1.json', 'AccountMePageCodecV1', ['#' => AccountMePage::class], [
                '#/properties/rejection' => ValueBinding::document(JsonDocument::class),
                '#/properties/result' => ValueBinding::document(JsonDocument::class),
            ]),
            self::page('addon-page.v1.json', 'AddonPageCodecV1', ['#' => AddonPage::class], [
                '#/properties/addon' => ValueBinding::value(AddonNamespace::class),
                '#/properties/page' => ValueBinding::value(ContributionId::class),
            ]),
            self::page('palette.v1.json', 'PalettePropCodecV1', ['#' => PaletteProp::class], [
                '#/properties/rejection' => ValueBinding::document(JsonDocument::class),
                '#/properties/result' => ValueBinding::document(JsonDocument::class),
            ]),
            self::page('command-form.v1.json', 'CommandFormPageCodecV1', ['#' => CommandFormPage::class], [
                '#/properties/command' => ValueBinding::value(CommandName::class),
                '#/properties/schema' => ValueBinding::document(JsonDocument::class),
            ]),
            self::page('brand.v1.json', 'PanelBrandCodecV1', [
                '#' => PanelBrand::class,
                '#/$defs/logo' => PanelBrandLogo::class,
            ]),
            self::page('contributions.v1.json', 'ContributionsCodecV1', [
                '#' => ContributionsProp::class,
                '#/$defs/action' => ActionProp::class,
                '#/$defs/addon' => AddonProp::class,
                '#/$defs/check' => CheckProp::class,
                '#/$defs/decorator' => DecoratorProp::class,
                '#/$defs/fill' => FillProp::class,
                '#/$defs/nav' => NavProp::class,
                '#/$defs/page' => PageLinkProp::class,
                '#/$defs/point' => PointFillsProp::class,
                '#/$defs/prefill' => PrefillProp::class,
                '#/$defs/replacement' => ReplacementProp::class,
                '#/$defs/step' => StepProp::class,
            ], [
                '#/$defs/action/properties/confirm' => ValueBinding::enum(Confirm::class),
                '#/$defs/action/properties/tone' => ValueBinding::enum(Tone::class),
                '#/$defs/addon/properties/addon' => ValueBinding::value(AddonNamespace::class),
                '#/$defs/check/properties/severity' => ValueBinding::enum(Severity::class),
                '#/$defs/decorator/properties/tightens/items' => ValueBinding::enum(Tighten::class),
                '#/$defs/fill/properties/addon' => ValueBinding::value(AddonNamespace::class),
                '#/$defs/fill/properties/id' => ValueBinding::value(ContributionId::class),
                '#/$defs/fill/properties/kind' => ValueBinding::enum(PointKind::class),
                '#/$defs/fill/properties/props' => ValueBinding::document(JsonDocument::class),
                '#/$defs/nav/properties/page' => ValueBinding::value(ContributionId::class),
                '#/$defs/point/properties/kind' => ValueBinding::enum(PointKind::class),
                '#/$defs/point/properties/multiplicity' => ValueBinding::enum(Multiplicity::class),
                '#/$defs/point/properties/region' => ValueBinding::enum(Region::class),
                '#/$defs/step/properties/position' => ValueBinding::enum(StepPosition::class),
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
     * The codec of each page in PHP_DIRECTORY, and in TYPESCRIPT_DIRECTORY the runtime module, each
     * page's module and the module of each kernel contract of PROTOCOL_CODECS among $protocol, which
     * the result owns.
     *
     * @param  list<CodecContract>  $contracts
     * @param  list<CodecContract>  $protocol  the kernel's contracts; those of PROTOCOL_CODECS get a module
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput or GenerateErrorCode::NameCollision
     */
    public static function result(array $contracts, string $runtime, array $protocol = []): GenerationResult
    {
        $location = new PhpLocation(self::PHP_DIRECTORY, self::PHP_NAMESPACE);
        $files = [self::TYPESCRIPT_DIRECTORY.'/'.self::RUNTIME => new GeneratedFile(self::TYPESCRIPT_DIRECTORY.'/'.self::RUNTIME, $runtime)];

        foreach ($protocol as $contract) {
            if (! in_array($contract->codecClass, self::PROTOCOL_CODECS, true)) {
                continue;
            }

            $name = (string) preg_replace('/Codec(V[0-9]+)\z/', '$1', $contract->codecClass);
            $module = TypeScriptEmitter::emit(
                $contract,
                self::TYPESCRIPT_DIRECTORY.'/'.self::PROTOCOL.'/'.$name.'.ts',
                '../validation',
                [
                    sprintf('A contract of the kernel, %s, as TypeScript (GUARDRAILS 2.2): its JSON form, which the', $name),
                    sprintf('kernel\'s codec %s writes, and a validator that checks a JSON value against', $contract->codecClass),
                    'every rule of its JSON Schema. The panel\'s host reads the answers of commands with it.',
                    '',
                    'Generated by composer generate:protocol from the JSON Schemas of cboxdk/cms.',
                    'Do not edit this file: change the schema and run composer generate:protocol.',
                ],
            );
            $files[$module->path] = $module;
        }

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
