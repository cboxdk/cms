<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;

/**
 * What cms:panel:points and cms:panel:fills print (PRD 13.2, 13.4).
 *
 * cms:panel:points, with --json, one document, keys sorted: `{"points": [...], "version": 1}`,
 * each point with `id`, `kind`, `page`, `region`, `multiplicity`, `max`, `ownership`, `keyed_by`,
 * `tightens`, `stability`, `since`, `label`, `class`, `package` and `fills`, the number of
 * contributions to it. Without it, two lines per point.
 *
 * cms:panel:fills, with --json, `{"fills": [...], "point": "<id>", "version": 1}`, each fill with
 * `contribution`, `addon`, `package`, `priority` and `scope` (`pages`, `commands`, `types`,
 * `field_types`, `requires`), in the order the host renders them. Without it, a heading and a
 * numbered line per contribution.
 */
#[Internal]
final readonly class PanelPointsOutput
{
    public const int VERSION = 1;

    /**
     * @param  list<PanelPointEntry>  $points
     */
    public function points(array $points, bool $json): CliAnswer
    {
        if ($json) {
            return new CliAnswer(ExitCode::Ok, [$this->json(['points' => array_map($this->point(...), $points), 'version' => self::VERSION])]);
        }

        if ($points === []) {
            return new CliAnswer(ExitCode::Ok, ['No panel points are declared.']);
        }

        $count = count($points);
        $lines = [sprintf('%d panel %s, by name and version', $count, $count === 1 ? 'point' : 'points')];

        foreach ($points as $point) {
            $declaration = $point->declaration;
            $fills = count($point->fills);
            $lines[] = sprintf(
                '  <info>%s</info>  %s  page %s  %s since %s  %d %s',
                $point->id()->toString(),
                $this->shape($point),
                $declaration->page,
                $point->stability->value,
                $declaration->since,
                $fills,
                $fills === 1 ? 'contribution' : 'contributions',
            );
            $lines[] = sprintf('      props %s (%s), label %s', $point->class, $point->package, $declaration->label);
        }

        return new CliAnswer(ExitCode::Ok, $lines);
    }

    public function fills(PanelPointEntry $point, bool $json): CliAnswer
    {
        $id = $point->id()->toString();

        if ($json) {
            return new CliAnswer(ExitCode::Ok, [$this->json(['fills' => array_map($this->fill(...), $point->fills), 'point' => $id, 'version' => self::VERSION])]);
        }

        $count = count($point->fills);

        if ($count === 0) {
            return new CliAnswer(ExitCode::Ok, [sprintf('<info>%s</info>: no contributions.', $id)]);
        }

        $lines = [sprintf('<info>%s</info>: %d %s, in the order the host renders them', $id, $count, $count === 1 ? 'contribution' : 'contributions')];

        foreach ($point->fills as $number => $fill) {
            $lines[] = sprintf(
                '  %d. %s  priority %d  %s, addon %s%s',
                $number + 1,
                $fill->contribution->value,
                $fill->priority,
                $fill->package,
                $fill->addon()->value,
                $this->scopeLine($fill),
            );
        }

        return new CliAnswer(ExitCode::Ok, $lines);
    }

    /**
     * `<kind>[ in <region>], renders <many|at most n|one>[, <ownership> keys by <key>][, tightens ...]`.
     */
    private function shape(PanelPointEntry $point): string
    {
        $declaration = $point->declaration;
        $parts = [$declaration->kind->value.($declaration->region instanceof Region ? ' in '.$declaration->region->value : '')];
        $parts[] = match ($declaration->multiplicity) {
            Multiplicity::Many => 'renders many',
            Multiplicity::Max => sprintf('renders at most %d', $declaration->max ?? 0),
            Multiplicity::Exclusive => 'renders one',
        };

        if ($declaration->ownership instanceof Ownership && $declaration->keyedBy instanceof ReplacementKey) {
            $parts[] = sprintf('%s keys by %s', $declaration->ownership->value, $declaration->keyedBy->value);
        }

        if ($declaration->tightens !== []) {
            $parts[] = 'tightens '.implode(' and ', array_map(static fn (Tighten $tighten): string => $tighten->value, $declaration->tightens));
        }

        return implode(', ', $parts);
    }

    private function scopeLine(PanelFill $fill): string
    {
        $scope = $fill->scope;
        $parts = array_filter([
            'pages' => implode(', ', array_map(static fn (PageName $page): string => $page->value, $scope->pages)),
            'commands' => implode(', ', array_map(static fn (CommandRef $command): string => $command->toString(), $scope->commands)),
            'types' => implode(', ', array_map(static fn (TypeName $type): string => $type->value, $scope->types)),
            'field types' => implode(', ', $scope->fieldTypes),
            'requires' => $scope->requires->value ?? '',
        ], static fn (string $value): bool => $value !== '');

        if ($parts === []) {
            return '';
        }

        return ', scope '.implode('; ', array_map(static fn (string $key, string $value): string => $key.' '.$value, array_keys($parts), $parts));
    }

    /**
     * @return array<string, mixed>
     */
    private function point(PanelPointEntry $point): array
    {
        $declaration = $point->declaration;

        return [
            'class' => $point->class,
            'fills' => count($point->fills),
            'id' => $point->id()->toString(),
            'keyed_by' => $declaration->keyedBy?->value,
            'kind' => $declaration->kind->value,
            'label' => $declaration->label,
            'max' => $declaration->max,
            'multiplicity' => $declaration->multiplicity->value,
            'ownership' => $declaration->ownership?->value,
            'package' => $point->package,
            'page' => $declaration->page,
            'region' => $declaration->region?->value,
            'since' => $declaration->since,
            'stability' => $point->stability->value,
            'tightens' => array_map(static fn (Tighten $tighten): string => $tighten->value, $declaration->tightens),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fill(PanelFill $fill): array
    {
        $scope = $fill->scope;

        return [
            'addon' => $fill->addon()->value,
            'contribution' => $fill->contribution->value,
            'package' => $fill->package,
            'priority' => $fill->priority,
            'scope' => [
                'commands' => array_map(static fn (CommandRef $command): string => $command->toString(), $scope->commands),
                'field_types' => $scope->fieldTypes,
                'pages' => array_map(static fn (PageName $page): string => $page->value, $scope->pages),
                'requires' => $scope->requires?->value,
                'types' => array_map(static fn (TypeName $type): string => $type->value, $scope->types),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function json(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
