# Fremdrift

STATUS: running

Opdateres af hver workflow-kørsel. Læses først i hver session og af hver agent der ændrer kode.

## Blokke

Rækkefølgen er `MILESTONES.md`. Id'erne bruges af workflowen `cms-milestone`.

| Id | Blok | Status |
|---|---|---|
| M0 | Milepæl 0: værktøjskæde | in_progress |
| M1 | Milepæl 1: gående skelet | todo |
| B1 | Panel-skelet | todo |
| B2 | Model | todo |
| B3 | Tid og schema-evolution | todo |
| B4 | Invalidering i fuld skala | todo |
| B5 | Forespørgsler | todo |
| B6 | Compliance | todo |
| B7 | Webhooks | todo |
| B8 | Assets | todo |
| B9 | Kuratering | todo |
| B10 | Udgivelsespakker | todo |
| B11 | CRDT | todo |
| B12 | Panel og Data Studio | todo |
| B13 | Slutbrugere | todo |
| B14 | Bulk og ingestion | todo |
| B15 | Resten af projektionerne | todo |
| B16 | Bevis | todo |

Status er `todo`, `next`, `in_progress`, `done`, `incomplete` eller `blocked`.

## Seneste kørsel

Ingen endnu.

## Blokeret

Beslutninger der venter på Sylvester, og hvad de blokerer:

- **Navnet på app-skabelonen.** GUARDRAILS 2.6 kalder skabelonrepoet `cbox-cms`, men planlægningsrepoet hedder også `~/Projects/cbox-cms`. Blokerer først B1, hvor skabelonen skal findes. Indtil da ligger compose-filen til udvikling i `laravel-cms`.
- PRD 26, spørgsmål 8: kommentarer i kernen. Blokerer den del af B13 der handler om kommentarer.
- PRD 26, spørgsmål 13: prioritetsskala og forudindstillinger. Blokerer standardværdierne i B9, ikke modellen.
- PRD 26, spørgsmål 16: udbyder efter AI-forordningen. Blokerer intet i kernen.

## Tolkninger

Valg truffet hvor PRD'en var tvetydig:

- M0-T3: PHPStan niveau 10 gælder også tests: `packages/*/tests` og `tests`. Boundary- og Adapter-reglen fra T4 gælder kun `packages/*/src` og `workbench/app`. Tests er ikke domæne.
- M0-T3: Den fælles konfiguration ligger i `packages/testkit/config/`: `phpstan.neon`, `rector.php` og `pint.json`. Roden og addons henter den fra `vendor/cboxdk/cms-testkit/config/` og tilføjer kun deres egne stier. Pint bruger `extend`, Rector får en builder retur, PHPStan bruger `includes`.
- M0-T3: Testkittet kræver selv PHPStan, Larastan, Rector, rector-laravel, Pint og orchestra/testbench i `require`. Addons kræver testkittet i `require-dev`, så værktøjerne kun er til udvikling. Roden har derfor flyttet testkittet fra `require` til `require-dev`. Testbench er med, fordi Larastan skal starte en Laravel-app, og en pakke har ingen `bootstrap/app.php`.
- M0-T3: PHPStan udvider ikke globs i `paths`. Rodens `phpstan.neon` lister derfor hver pakkes `src` og `tests`. En test fejler, hvis en mappe under `packages/*` mangler.
- M0-T3: Den fælles PHPStan-konfiguration er lidt strengere end niveau 10 alene: `checkUninitializedProperties`, `checkMissingCallableSignature`, `reportAnyTypeWideningInVarTag` og `reportUnmatchedIgnoredErrors`. Rector kører sættene for PHP 8.5, `UP_TO_LARAVEL_130`, typedeklarationer, kodekvalitet og død kode. Pint bruger Laravel-presettet plus `declare_strict_types`, `strict_comparison`, `strict_param`, `void_return` og import af klasser.

## Til review af Sylvester

Ændringer af kontroller, afvigelser fra GUARDRAILS og andet der skal ses af et menneske:

- M0-T3: Nye kontroller, som skal reviewes af en anden end forfatteren (GUARDRAILS 7.3): den fælles PHPStan-, Rector- og Pint-konfiguration i `packages/testkit/config/`, rodens `phpstan.neon`, `rector.php` og `pint.json`, og testene der vogter dem (`tests/Feature/Tooling/QualityGatesTest.php`, `packages/testkit/tests/SharedToolConfigTest.php`).
- M0-T3: Arkitekturtesten for `strict_types` fra T1 tjekkede ingen pakkeklasser. Pest slår et namespace op i Composers PSR-4-map, og der findes ingen mapping for `Cbox\Cms` alene. Testen lister nu hver pakkes namespace ud fra manifesterne. Kontrollen er strammet, ikke svækket: en klasse uden `strict_types` i `packages/core/src` bestod før og fejler nu.
- M0-T3: `PostgresRolesTest` fra T2 er skrevet om, så den består niveau 10: en `try`/`catch` i stedet for `toThrow` med en typet closure, og en hjælper i stedet for `$this->table`. Påstandene er de samme. Pest tæller 8 færre assertions, fordi tjekket af undtagelsens klasse nu er selve `catch`. Tjekket ved at ændre SQLSTATE (8 fejl) og ved at lade én sætning lykkes (1 fejl).
- M0-T3: Rector ændrede eksisterende kode: `#[Override]` på `$enablesPackageDiscoveries` i `tests/TestCase.php`, `env()` i `WorkbenchServiceProvider` er ikke længere statisk, og to tests bruger `__DIR__.'/../src'`.

## Kontroller kørt

Seneste resultat per port:

- Ingen endnu.
