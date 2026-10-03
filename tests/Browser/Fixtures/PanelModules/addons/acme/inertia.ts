// A test-only addon module that imports a module an addon may not import (PRD 13.4). Inside an
// addon's scope the panel's import map gives it the panel's refused entry, which throws
// PanelImportRefused before this module runs.

import { usePage } from '@inertiajs/react';

export const page: unknown = usePage;
