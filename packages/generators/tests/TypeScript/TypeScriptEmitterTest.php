<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\TypeScript;

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\StringForm;
use Cbox\Cms\Generators\Codec\Domain\TypeScriptEmitter;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Tests\Support\JsToolchainLock;
use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use LogicException;

/*
 * The TypeScript module of a contract version (PRD 11.12, GUARDRAILS 2.2): its types with the
 * presence of each key, and its rules as data the runtime checks. A contract made to hit every
 * form Prettier breaks, long unions of choices and of an enum, long keys, a long pattern, integer
 * bounds past what a JavaScript number holds and an enum of integers, comes out as Prettier, tsc
 * and ESLint accept it unchanged.
 */

/**
 * A property of a stress contract.
 */
function stressProperty(string $key, CodecValue $value, bool $required = false, bool $nullable = false, ?string $default = null, ?ClassificationAccess $classification = null): CodecProperty
{
    return new CodecProperty($key, lcfirst(str_replace('_', '', ucwords($key, '_'))), $value, $required, $classification, 'The '.str_replace('_', ' ', $key).'.', $nullable, $default);
}

/**
 * @param  list<string>  $choices
 */
function choice(array $choices): CodecValue
{
    return CodecValue::of(CodecKind::Choice, [new ValidationRule(ValidationRuleName::In, $choices)]);
}

function stressContract(): CodecContract
{
    $nested = new CodecObject('StressV1AVeryLongGroupHandleThatMakesEveryNameLonger', ['A nested group.'], [
        stressProperty('a_long_key_that_pushes_a_keyword_union_past_the_print_width_of_the_module_by_some_characters', CodecValue::of(CodecKind::Boolean)),
        stressProperty('size', choice(['small', 'large']), required: true),
    ]);

    $longest = new CodecObject('StressV1ANestedGroupWhoseClassNameIsSoLongThatItsListAndNullCannotShareOneLine', ['A group with a long name.'], [
        stressProperty('name', CodecValue::of(CodecKind::Text), required: true),
    ]);

    return new CodecContract(new CodecObject('StressV1', ['A contract made to hit every form Prettier breaks.'], [
        stressProperty('short_choices', choice(['a', 'b']), required: true),
        stressProperty('medium_choices', choice(['first_choice_value', 'second_choice_value', 'third_choice_value']), required: true),
        stressProperty('long_choices', choice(array_map(static fn (int $number): string => 'a_choice_value_that_is_long_'.$number, range(1, 6))), required: true),
        stressProperty('quoted_choice', choice(["it's", 'plain']), required: true),
        stressProperty('status', CodecValue::enum(ExitCode::class), required: true),
        stressProperty('wait_level', CodecValue::enum(WaitLevel::class), default: 'WaitLevel::Commit'),
        stressProperty('actor', CodecValue::id(ActorId::class, new StringForm('^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$', 36, 36)), required: true, nullable: true),
        stressProperty('huge', CodecValue::of(CodecKind::Integer, [new ValidationRule(ValidationRuleName::Integer), new ValidationRule(ValidationRuleName::Min, ['-9223372036854775808']), new ValidationRule(ValidationRuleName::Max, ['9223372036854775807'])])),
        stressProperty('impossible', CodecValue::of(CodecKind::Integer, [new ValidationRule(ValidationRuleName::Integer), new ValidationRule(ValidationRuleName::Min, ['9007199254740992'])])),
        stressProperty('secret', CodecValue::of(CodecKind::Text), required: true, classification: ClassificationAccess::Confidential),
        stressProperty('a_long_key_so_that_a_nullable_list_of_long_group_items_breaks_before_its_union', CodecValue::list(CodecValue::object($nested), [new ValidationRule(ValidationRuleName::List), new ValidationRule(ValidationRuleName::MinItems, ['1'])])),
        stressProperty('long_items', CodecValue::list(CodecValue::object($longest))),
        stressProperty('a_very_long_key_that_keeps_a_type_name_and_null_on_one_line_as_prettier_hugs_it', CodecValue::object($nested)),
    ]), 'StressCodecV1', 1, ['The stress contract.']);
}

/**
 * The code a generation failure has.
 *
 * @param  callable(): mixed  $generate
 */
function failureCode(callable $generate): GenerateErrorCode
{
    try {
        $generate();
    } catch (GenerationFailed $failed) {
        return $failed->problems[0]->code;
    }

    throw new LogicException('The generation did not fail.');
}

it('writes the types with each key\'s presence, and the rules in the order the PHP codec reads them', function (): void {
    $module = TypeScriptEmitter::emit(stressContract(), 'generated/StressV1.ts', './validation', ['A stress module.'])->contents;

    expect($module)->toStartWith("// A stress module.\n\nimport { validate, type ObjectRule, type Validation } from './validation';\n")
        ->toContain("  short_choices: StressV1ShortChoicesChoice;\n")
        ->toContain("  wait_level?: WaitLevel;\n")
        ->toContain("  actor: string | null;\n")
        ->toContain("  huge?: number | null;\n")
        ->toContain("  secret?: string;\n")
        ->toContain("export type StressV1QuotedChoiceChoice = \"it's\" | 'plain';\n")
        ->toContain("export type StressV1MediumChoicesChoice =\n  'first_choice_value' | 'second_choice_value' | 'third_choice_value';\n")
        ->toContain("export type StressV1LongChoicesChoice =\n  | 'a_choice_value_that_is_long_1'\n")
        ->toContain("  a_long_key_that_pushes_a_keyword_union_past_the_print_width_of_the_module_by_some_characters?:\n    boolean | null;\n")
        ->toContain("  a_very_long_key_that_keeps_a_type_name_and_null_on_one_line_as_prettier_hugs_it?: StressV1AVeryLongGroupHandleThatMakesEveryNameLonger | null;\n")
        ->toContain("  a_long_key_so_that_a_nullable_list_of_long_group_items_breaks_before_its_union?:\n    readonly StressV1AVeryLongGroupHandleThatMakesEveryNameLonger[] | null;\n")
        ->toContain("  long_items?:\n    | readonly StressV1ANestedGroupWhoseClassNameIsSoLongThatItsListAndNullCannotShareOneLine[]\n    | null;\n")
        ->toContain("{ key: 'huge', presence: 'optional', value: { kind: 'integer' } }")
        ->toContain("{ key: 'impossible', presence: 'optional', value: { kind: 'integer', min: Infinity } }")
        ->toContain("{ key: 'secret', presence: 'omittable', value: { kind: 'text' } }")
        ->toContain("key: 'wait_level',\n      presence: 'omittable',")
        ->toContain("key: 'actor',\n      presence: 'present',")
        ->toContain("        pattern:\n          '^[0-9a-fA-F]{8}")
        ->toContain('        values: [0, 64, 65, 66,')
        ->toContain("const stressV1ANestedGroupWhoseClassNameIsSoLongThatItsListAndNullCannotShareOneLineRule: ObjectRule =\n  { properties: [{ key: 'name', presence: 'required', value: { kind: 'text' } }] };\n")
        ->toContain("          object:\n            stressV1ANestedGroupWhoseClassNameIsSoLongThatItsListAndNullCannotShareOneLineRule,\n")
        ->toEndWith("export function validateStressV1(value: unknown): Validation<StressV1> {\n  return validate<StressV1>(value, stressV1Rule);\n}\n");
});

it('writes a module that Prettier, tsc and ESLint accept unchanged', function (): void {
    $module = TypeScriptEmitter::emit(stressContract(), 'StressV1.ts', './validation', ['A stress module.'])->contents;
    $runtime = (string) file_get_contents(Phpstan::root().'/packages/generators/resources/typescript/validation.ts');

    [$format, $lint, $typecheck] = JsToolchainLock::exclusive(static function () use ($module, $runtime): array {
        $directory = Node::PROBE_DIRECTORY.'/cms-probe-'.bin2hex(random_bytes(4));
        $absolute = Phpstan::root().'/'.$directory;
        mkdir($absolute);
        file_put_contents($absolute.'/StressV1.ts', $module);
        file_put_contents($absolute.'/validation.ts', $runtime);

        try {
            return [
                Node::tool('prettier', ['--check', $directory]),
                Node::tool('eslint', ['--max-warnings=0', $directory]),
                Node::run(['npm', 'run', '--silent', 'typecheck']),
            ];
        } finally {
            array_map(unlink(...), glob($absolute.'/*.ts') ?: []);
            rmdir($absolute);
        }
    });

    expect($format->getExitCode())->toBe(0, $format->getOutput().$format->getErrorOutput())
        ->and($lint->getExitCode())->toBe(0, $lint->getOutput())
        ->and($typecheck->getExitCode())->toBe(0, $typecheck->getOutput());
});

it('refuses two declarations of one name with generate_name_collision', function (): void {
    $group = new CodecObject('CollisionV1ColourChoice', ['A group whose name is a select\'s union.'], [stressProperty('name', CodecValue::of(CodecKind::Text))]);
    $contract = new CodecContract(new CodecObject('CollisionV1', ['A collision.'], [
        stressProperty('colour', choice(['red'])),
        stressProperty('colour_choice', CodecValue::object($group)),
    ]), 'CollisionCodecV1', 1, ['A collision.']);

    expect(failureCode(static fn (): GeneratedFile => TypeScriptEmitter::emit($contract, 'CollisionV1.ts', './validation', [])))->toBe(GenerateErrorCode::NameCollision);
});

it('refuses a codec class that does not end in Codec and its version', function (): void {
    $contract = new CodecContract(new CodecObject('Odd', [], [stressProperty('name', CodecValue::of(CodecKind::Text))]), 'OddReader', 1, []);

    expect(failureCode(static fn (): GeneratedFile => TypeScriptEmitter::emit($contract, 'Odd.ts', './validation', [])))->toBe(GenerateErrorCode::InvalidOutput);
});

it('refuses a rule that has no form for the kind of its value', function (): void {
    $contract = new CodecContract(new CodecObject('RuleV1', [], [
        stressProperty('flag', CodecValue::of(CodecKind::Boolean, [new ValidationRule(ValidationRuleName::MaxLength, ['3'])])),
    ]), 'RuleCodecV1', 1, []);

    expect(failureCode(static fn (): GeneratedFile => TypeScriptEmitter::emit($contract, 'RuleV1.ts', './validation', [])))->toBe(GenerateErrorCode::InvalidOutput);
});
