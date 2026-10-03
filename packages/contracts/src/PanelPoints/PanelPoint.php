<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Attribute;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Declares a point of the panel that contributions extend, on the class of the point's props, for
 * example
 *
 *     #[PanelPoint(name: 'account.me.sections', version: 1, kind: PointKind::Slot, page: 'account.me',
 *         region: Region::Sections, since: '1.0', label: 'panel.points.account_me_sections')]
 *
 * cms:build reads it from the scan roots and writes the point to the panel registry, panel.php,
 * under its id `<name>@<version>`. The class is a final readonly class whose public properties are
 * the props a contribution receives, none for a point without props, and its stability attribute,
 * #[Stable], #[Experimental] or #[Internal], is the point's: an #[Internal] point is the core's own
 * wiring, never contributable.
 *
 * - `name` and `version`: the point's id. A breaking change to the props is the next version.
 * - `kind`: what a contribution gives the host (PointKind).
 * - `page`: the page that renders the point.
 * - `region`: where on the page a slot sits; a slot has one and no other kind does.
 * - `multiplicity` and `max`: how many contributions the host renders; `max`, from 1, is given
 *   exactly when the multiplicity is Max. A replacement is Exclusive, and only a replacement is.
 * - `ownership` and `keyedBy`: which keys of a replacement an addon may replace, and what a key
 *   is; a replacement has both and no other kind has either.
 * - `tightens`: the props of the default a decorator may tighten, each once; only a decorator has
 *   any.
 * - `since`: the panel API release the point arrived in, `<major>.<minor>`.
 * - `label`: the translation key of the point's name in the panel's catalogue.
 *
 * Anything else throws InvalidPanelPoint, which cms:build reports as registry_invalid_attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class PanelPoint
{
    public const string SINCE_PATTERN = '/\A(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\z/';

    public const string LABEL_PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z0-9][a-z0-9_-]*)+\z/';

    /** @var list<Tighten> */
    public array $tightens;

    /**
     * @param  list<Tighten>  $tightens
     *
     * @throws InvalidPanelPoint
     */
    public function __construct(
        public string $name,
        public int $version,
        public PointKind $kind,
        public string $page,
        public string $since,
        public string $label,
        public ?Region $region = null,
        public Multiplicity $multiplicity = Multiplicity::Many,
        public ?int $max = null,
        public ?Ownership $ownership = null,
        public ?ReplacementKey $keyedBy = null,
        array $tightens = [],
    ) {
        $id = new PointId(new PointName($name), $version);
        new PageName($page);
        $point = $id->toString();

        if (preg_match(self::SINCE_PATTERN, $since) !== 1) {
            throw InvalidPanelPoint::because(sprintf('The panel point %s says it arrived in "%s", which is not a release of the panel API as <major>.<minor>, such as "1.0".', $point, $since));
        }

        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw InvalidPanelPoint::because(sprintf('The label of the panel point %s, "%s", is not a translation key: dot-separated segments of lowercase letters, digits, hyphens and underscores, such as "panel.points.account_me_sections".', $point, $label));
        }

        if (($kind === PointKind::Slot) !== ($region instanceof Region)) {
            throw InvalidPanelPoint::because($kind === PointKind::Slot
                ? sprintf('The slot %s has no region. Give it the Region it sits in.', $point)
                : sprintf('The panel point %s is of kind %s and has the region %s. Only a slot has a region.', $point, $kind->value, $region->value ?? ''));
        }

        if (($multiplicity === Multiplicity::Max) !== ($max !== null)) {
            throw InvalidPanelPoint::because($multiplicity === Multiplicity::Max
                ? sprintf('The panel point %s renders at most max contributions and gives no max. Give max: <n>.', $point)
                : sprintf('The panel point %s gives max %d, and its multiplicity is %s. Give max only with Multiplicity::Max.', $point, $max ?? 0, $multiplicity->value));
        }

        if ($max !== null && $max < 1) {
            throw InvalidPanelPoint::because(sprintf('The panel point %s renders at most %d contributions. Give max from 1.', $point, $max));
        }

        if (($kind === PointKind::Replacement) !== ($multiplicity === Multiplicity::Exclusive)) {
            throw InvalidPanelPoint::because($kind === PointKind::Replacement
                ? sprintf('The replacement point %s is %s. Exactly one replacement wins, so its multiplicity is Multiplicity::Exclusive.', $point, $multiplicity->value)
                : sprintf('The panel point %s is of kind %s and exclusive. Only a replacement is exclusive.', $point, $kind->value));
        }

        if (($kind === PointKind::Replacement) !== ($ownership instanceof Ownership) || ($kind === PointKind::Replacement) !== ($keyedBy instanceof ReplacementKey)) {
            throw InvalidPanelPoint::because($kind === PointKind::Replacement
                ? sprintf('The replacement point %s needs both ownership and keyedBy, so a replacement names the key it replaces and the build can tell whether the addon owns it.', $point)
                : sprintf('The panel point %s is of kind %s and gives ownership or keyedBy. Only a replacement has them.', $point, $kind->value));
        }

        if ($tightens !== [] && $kind !== PointKind::Decorator) {
            throw InvalidPanelPoint::because(sprintf('The panel point %s is of kind %s and lists props a decorator may tighten. Only a decorator tightens.', $point, $kind->value));
        }

        $seen = [];

        foreach ($tightens as $tighten) {
            if (isset($seen[$tighten->value])) {
                throw InvalidPanelPoint::because(sprintf('The decorator point %s lists %s twice in tightens. List each once.', $point, $tighten->value));
            }

            $seen[$tighten->value] = true;
        }

        $this->tightens = $tightens;
    }

    /**
     * The point's id, `<name>@<version>`.
     */
    public function id(): PointId
    {
        return new PointId(new PointName($this->name), $this->version);
    }

    /**
     * The page that renders the point.
     */
    public function pageName(): PageName
    {
        return new PageName($this->page);
    }
}
