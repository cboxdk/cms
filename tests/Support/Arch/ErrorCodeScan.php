<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use BackedEnum;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use ReflectionClass;

/**
 * Holds the error catalog, Cbox\Cms\Contracts\Errors\ErrorCode, and the codes the source declares
 * to each other (PRD 6.1, GUARDRAILS 2.1, 7.2).
 *
 * A code is declared by a class constant named CODE or CODE_<NAME> with a string value, such as
 * Conflict::CODE or AppRoleCheck::CODE_SUPERUSER, by a case of an enum whose short name ends in
 * ErrorCode, such as BuildErrorCode, or by naming a case of the catalog itself,
 * ErrorCode::<Case>, in a source file other than the catalog's. Constants and enum cases a class
 * inherits count where they are declared.
 *
 * scan() finds the declarations in the given types; problems() then reports each declared code
 * that the catalog lacks, each constant named like a code whose value is no code, each entry that
 * no declaration uses and that RESERVED does not hold for a later block, each entry of RESERVED
 * that is used now or is no entry, and each constant of NOT_CODES that no longer exists.
 */
final readonly class ErrorCodeScan
{
    /**
     * Constants named like a code, and cases of an enum of codes, whose value is something else,
     * with the reason.
     */
    public const array NOT_CODES = [
        CheckResult::class.'::CODE_PATTERN' => 'the pattern every code of a doctor check matches, not a code',
    ];

    /**
     * Entries that M1 adds before the code that uses them exists (PRD 6.1), each with where it
     * will be used. The test fails once a code here is used, so the entry leaves this list with
     * the task that uses it.
     */
    public const array RESERVED = [
        'dry_run' => 'the surfaces answer a receipt with the outcome dry_run (M1 points 3 and 6, GUARDRAILS 2.1)',
    ];

    /**
     * @param  list<ErrorCodeDeclaration>  $declarations
     * @param  list<string>  $constants  the constants seen, as <class>::<name>, whatever their value
     */
    private function __construct(
        public array $declarations,
        public array $constants,
    ) {}

    /**
     * The declarations in the types, and in the source files the catalog's cases are named in.
     *
     * @param  list<DeclaredType>  $types
     * @param  list<SourceFile>  $files
     */
    public static function scan(array $types, array $files): self
    {
        $declarations = [];
        $constants = [];

        foreach ($types as $type) {
            $name = $type->fqcn();

            if ($name === ErrorCode::class || (! class_exists($name) && ! interface_exists($name) && ! enum_exists($name) && ! trait_exists($name))) {
                continue;
            }

            $class = new ReflectionClass($name);
            $where = Codebase::relative($type->path);

            $codeEnum = $class->isEnum() && str_ends_with($class->getShortName(), 'ErrorCode');

            foreach ($class->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $name) {
                    continue;
                }

                $constantName = $constant->getName();
                $value = $constant->getValue();

                if ($constant->isEnumCase()) {
                    if ($codeEnum && $value instanceof BackedEnum && is_string($value->value)) {
                        $declarations[] = new ErrorCodeDeclaration($value->value, $name.'::'.$constantName, $where);
                    }

                    continue;
                }

                if ($constantName !== 'CODE' && ! str_starts_with($constantName, 'CODE_')) {
                    continue;
                }

                $constants[] = $name.'::'.$constantName;

                if (is_string($value)) {
                    $declarations[] = new ErrorCodeDeclaration($value, $name.'::'.$constantName, $where);
                }
            }
        }

        foreach ($files as $file) {
            if (str_ends_with($file->path, '/packages/contracts/src/Errors/ErrorCode.php')) {
                continue;
            }

            array_push($declarations, ...self::caseReferences($file));
        }

        return new self($declarations, $constants);
    }

    /**
     * Everything wrong between the declarations and the catalog, one line each.
     *
     * @param  list<string>  $catalog  the codes of the catalog's cases
     * @param  array<string, string>  $reserved  RESERVED
     * @param  array<string, string>  $notCodes  NOT_CODES
     * @return list<string>
     */
    public function problems(array $catalog, array $reserved = self::RESERVED, array $notCodes = self::NOT_CODES): array
    {
        $problems = [];
        $known = array_flip($catalog);
        /** @var array<string, true> $used */
        $used = [];

        foreach ($this->declarations as $declaration) {
            if (isset($notCodes[$declaration->declaredBy])) {
                continue;
            }

            if (preg_match(ErrorCode::PATTERN, $declaration->code) !== 1) {
                $problems[] = sprintf('%s (%s) is %s, which is no error code: codes are lowercase words joined by underscores.', $declaration->declaredBy, $declaration->where, var_export($declaration->code, true));

                continue;
            }

            $used[$declaration->code] = true;

            if (! isset($known[$declaration->code])) {
                $problems[] = sprintf('%s (%s) declares %s, which the error catalog Cbox\Cms\Contracts\Errors\ErrorCode has no entry for.', $declaration->declaredBy, $declaration->where, $declaration->code);
            }
        }

        foreach ($catalog as $code) {
            if (! isset($used[$code]) && ! isset($reserved[$code])) {
                $problems[] = sprintf('The error catalog has an entry for %s, but no code in the source declares or uses it.', $code);
            }
        }

        foreach ($reserved as $code => $reason) {
            if (! isset($known[$code])) {
                $problems[] = sprintf('%s is reserved in ErrorCodeScan::RESERVED, but the error catalog has no entry for it.', $code);
            } elseif (isset($used[$code])) {
                $problems[] = sprintf('%s is used now; remove it from ErrorCodeScan::RESERVED (%s).', $code, $reason);
            }
        }

        $declared = [...$this->constants, ...array_map(static fn (ErrorCodeDeclaration $declaration): string => $declaration->declaredBy, $this->declarations)];

        foreach (array_keys($notCodes) as $constant) {
            if (! in_array($constant, $declared, true)) {
                $problems[] = sprintf('%s is in ErrorCodeScan::NOT_CODES, but no such constant or case is declared.', $constant);
            }
        }

        return $problems;
    }

    /**
     * The catalog's cases a source file names as ErrorCode::<Case>, when it imports the catalog.
     *
     * @return list<ErrorCodeDeclaration>
     */
    private static function caseReferences(SourceFile $file): array
    {
        $source = (string) file_get_contents($file->path);

        if (preg_match('/^use\s+'.preg_quote(ErrorCode::class, '/').'\s*;/m', $source) !== 1) {
            return [];
        }

        preg_match_all('/\bErrorCode::([A-Z][A-Za-z0-9]*)\b/', $source, $matches);
        $declarations = [];

        foreach (array_unique($matches[1]) as $case) {
            $code = self::caseValue($case);

            if ($code !== null) {
                $declarations[] = new ErrorCodeDeclaration($code, ErrorCode::class.'::'.$case, Codebase::relative($file->path));
            }
        }

        return $declarations;
    }

    private static function caseValue(string $case): ?string
    {
        foreach (ErrorCode::cases() as $each) {
            if ($each->name === $case) {
                return $each->value;
            }
        }

        return null;
    }
}
