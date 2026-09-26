<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use PHPUnit\Framework\Assert;

/*
 * The column encoding of extension fields is injective (PRD 11.12, point 2): every column
 * `ext__<namespace>__<handle>` decodes back to exactly its namespace and handle, no two
 * (namespace, handle) pairs share a column, and no column of an owner's own field is an extension
 * column. The namespaces and handles are the candidates below that Owner and Handle accept, so the
 * test follows those rules: a looser rule lets more candidates in, and those must still hold.
 */

/**
 * Every string of 1 to $length characters over $alphabet.
 *
 * @param  list<string>  $alphabet
 * @return list<string>
 */
function columnCandidates(array $alphabet, int $length): array
{
    $all = [];
    $previous = [''];

    for ($size = 1; $size <= $length; $size++) {
        $next = [];

        foreach ($previous as $prefix) {
            foreach ($alphabet as $character) {
                $next[] = $prefix.$character;
            }
        }

        array_push($all, ...$next);
        $previous = $next;
    }

    return $all;
}

/**
 * The namespaces among the candidates, with digits and at the maximum length.
 *
 * @return list<Owner>
 */
function columnNamespaces(): array
{
    $candidates = [
        ...columnCandidates(['a', 'b', '1', '_'], 3),
        'app', 'ext', 'acme', 'shop2', 'a_b', 'a__b', 'ab_', 'e', 'ex', 'ext1', 'cms',
        'a'.str_repeat('9', 19), str_repeat('z', 20), str_repeat('z', 21), '1a', 'A',
    ];
    $namespaces = [];

    foreach (array_unique($candidates) as $candidate) {
        try {
            $namespaces[] = new Owner($candidate);
        } catch (GenerationFailed) {
            continue;
        }
    }

    return $namespaces;
}

/**
 * The handles among the candidates, with single underscores, digits and at the maximum length, and
 * the strings that look like a namespace and a handle run together.
 *
 * @return list<Handle>
 */
function columnHandles(): array
{
    $candidates = [
        ...columnCandidates(['a', 'b', '1', '_'], 4),
        'ext', 'ext_app', 'ext_app_tax_code', 'ext__app__tax_code', 'app__tax_code', 'app_tax_code',
        'tax_code', 'a__b', 'a_', '_a', 'cms_title', 'cms', 'title', 'A', 'a-b', '1a',
        str_repeat('a', 63), 'a'.str_repeat('_1', 31), str_repeat('a', 64),
    ];
    $handles = [];

    foreach (array_unique($candidates) as $candidate) {
        try {
            $handles[] = new Handle($candidate);
        } catch (GenerationFailed) {
            continue;
        }
    }

    return $handles;
}

it('generates namespaces and handles that reach the edges of their rules', function (): void {
    $namespaces = array_map(static fn (Owner $owner): string => $owner->value, columnNamespaces());
    $handles = array_map(static fn (Handle $handle): string => $handle->value, columnHandles());

    expect($namespaces)->toContain('a', 'a1', 'b11', 'app', 'a'.str_repeat('9', 19), str_repeat('z', 20))
        ->and(array_values(array_intersect(['ext', 'a_b', 'a__b', 'ab_', str_repeat('z', 21)], $namespaces)))->toBe([])
        ->and($handles)->toContain('a', 'a_b', 'a_1', 'a1_b', 'ab_1', 'ext_app', 'app_tax_code', str_repeat('a', 63), 'a'.str_repeat('_1', 31))
        ->and(array_values(array_intersect(['ext', 'a__b', 'a_', '_a', 'ext__app__tax_code', 'cms_title', str_repeat('a', 64)], $handles)))->toBe([]);
});

it('decodes every extension column back to exactly its namespace and handle', function (): void {
    $checked = 0;

    foreach (columnNamespaces() as $namespace) {
        foreach (columnHandles() as $handle) {
            $column = ColumnName::ofExtensionField($namespace, $handle)->value;
            $parts = explode(ColumnName::SEPARATOR, $column, 3);

            Assert::assertCount(3, $parts, $column);
            [$prefix, $decodedNamespace, $decodedHandle] = $parts;
            Assert::assertSame(Handle::RESERVED, $prefix, $column);
            Assert::assertSame(1, preg_match(Owner::PATTERN, $decodedNamespace), $column);
            Assert::assertSame(1, preg_match(Handle::PATTERN, $decodedHandle), $column);
            Assert::assertSame([$namespace->value, $handle->value], [$decodedNamespace, $decodedHandle], $column);
            $checked++;
        }
    }

    expect($checked)->toBe(count(columnNamespaces()) * count(columnHandles()))->toBeGreaterThan(1000);
});

it('gives no two namespace and handle pairs the same column, and no own field an extension column', function (): void {
    $extensionColumns = [];

    foreach (columnNamespaces() as $namespace) {
        foreach (columnHandles() as $handle) {
            $column = ColumnName::ofExtensionField($namespace, $handle)->value;
            $pair = $namespace->value.' '.$handle->value;

            Assert::assertArrayNotHasKey($column, $extensionColumns, sprintf('%s and %s both give %s.', $extensionColumns[$column] ?? '', $pair, $column));
            $extensionColumns[$column] = $pair;
        }
    }

    foreach (columnHandles() as $handle) {
        $column = ColumnName::ofTypeField($handle)->value;

        Assert::assertArrayNotHasKey($column, $extensionColumns, sprintf('The own field %s has the column of the extension field %s.', $handle->value, $extensionColumns[$column] ?? ''));
    }

    expect($extensionColumns)->toHaveCount(count(columnNamespaces()) * count(columnHandles()));
});
