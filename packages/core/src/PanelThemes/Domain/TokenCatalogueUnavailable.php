<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * The kit's token catalogue, js/ui-kit/tokens.json in the installed cboxdk/cms, cannot be read or
 * is not of its form, so no theme can be checked. The installation is incomplete.
 */
#[Experimental]
final class TokenCatalogueUnavailable extends RuntimeException {}
