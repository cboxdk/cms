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
- M0-T5: Lagene er namespace-segmenter under et modul, fx `Cbox\Cms\Core\Entries\Domain\Commands`. Et namespace er i et lag, når et segment hedder som laget, enten som sidste segment eller med flere segmenter under. Matcher flere segmenter, afgør det inderste: `Cbox\Cms\Http\Boundary\RequestParser` er Boundary. `DomainEvents` er ikke `Domain`. Tabellen står under "Hvor ting bor" i `CLAUDE.md` og `AGENTS.md`.
- M0-T5: GUARDRAILS 2.5, Infrastructure og frameworket. Infrastructure rummer Eloquent-modeller, understøttelse af migrationer og partitionsmanageren. Laget må bruge `Illuminate\Database`, men ikke `Illuminate\Http`, facades, actions eller overflader, og ikke `mixed` (PHPStan-reglen i T4). Klasser der taler med Postgres og mapper rækker, altså kvitteringslageret og idempotenslageret i T13 og T14 med deres rækkemappere, bor i Adapter. Adapter må bruge frameworket og `mixed`. GUARDRAILS 2.2 nævner rækkemappere under Boundary; de ligger her ved lageret i Adapter, og begge lag tillader `mixed`.
- M0-T5: Domænet må kun bruge domænet og kontrakterne, efter tabellens "intet uden for domænet". Det udelukker også fx `Illuminate\Support\Collection`. Kontraktpakken `Cbox\Cms\Contracts` er en del af domænet og må ikke have lagsegmenter. Actions må kun bruge domænet, kontrakterne og andre klasser i Actions, fordi planlæggerne ligger i samme lag (GUARDRAILS 2.1).
- M0-T5: Overfladerne er http-pakken (`Cbox\Cms\Http`), cli-pakken (`Cbox\Cms\Cli`) og `Jobs`, som GUARDRAILS 2.5 regner med til overfladerne. De må ikke bruge Infrastructure, Adapter eller Eloquent. Boundary og Adapter må ikke bruge actions eller overflader. Artisan-kommandoer ligger i `Cli\Console`, aldrig i et `Commands`-namespace.
- M0-T5: `Commands`, `Queries`, `Dto` og `Receipts` må kun ligge under `Domain` eller i kontraktpakken, og de rummer kun `final readonly` klasser, ingen enums eller interfaces. Det samme gælder `Actions`.
- M0-T5: Den ene gateway for udgående HTTP er `Cbox\Cms\Core\Egress`. Reglen gælder `packages/*/src` og `workbench/app`, ikke tests, der læser lokale filer. Ud over Guzzle, Http-facaden, `curl_*` og `file_get_contents` dækker den `Illuminate\Http\Client`, Symfony HttpClient, PSR-18, `fsockopen` og `stream_socket_client`.
- M0-T5: Globale facade-aliaser som `\DB` og real-time facades er forbudt i `packages/*/src` og `workbench/app`. Pest ser ikke et alias som en facade, så uden reglen kunne `\DB::` gå uden om lagreglerne.
- M0-T5: Stabilitetsattributterne gælder klasser, interfaces, traits og enums i `packages/*/src`. `#[Stable]`, `#[Experimental]` og `#[Internal]` er selv `#[Stable]`. `#[Command]`, `#[Action]`, `#[Hook]`, `Surface` og `Phase` er `#[Experimental]`, indtil M1 har brugt dem. Service providers er `#[Internal]`. http og cli kræver nu `cboxdk/cms-contracts` direkte, fordi deres providers bruger attributten.
- M0-T5: `Surface` har `Rest`, `Inertia`, `Mcp` og `Cli`, én per transportprofil i GUARDRAILS 2.1. Kommandopaletten hører til panelet og dermed `Inertia`. Jobs og planlæggeren kalder en action direkte og har ingen surface, så `#[Action(surfaces: [])]` er tilladt. `Phase` har `Authorize`, `Transform` og `Validate`, én per hook-interface (PRD 6.3).
- M0-T5: `#[Command]` kræver et navn af mindst to snake_case-segmenter adskilt af punktum og en version fra 1. `#[Hook]` kræver en klasse med `#[Command]` og et budget fra 1 til 20 ms (PRD 6.3); prioriteten er et vilkårligt heltal. Attributterne kan kun sidde på klasser og kan ikke gentages.
- M0-T5: Arkitekturtestene er en egen suite, `Arch`, i `phpunit.xml`, med filerne i `tests/Arch`. `Monorepo`-suiten udelader mappen. T1's arkitekturtest er flyttet dertil.

## Til review af Sylvester

Ændringer af kontroller, afvigelser fra GUARDRAILS og andet der skal ses af et menneske:

- M0-T3: Nye kontroller, som skal reviewes af en anden end forfatteren (GUARDRAILS 7.3): den fælles PHPStan-, Rector- og Pint-konfiguration i `packages/testkit/config/`, rodens `phpstan.neon`, `rector.php` og `pint.json`, og testene der vogter dem (`tests/Feature/Tooling/QualityGatesTest.php`, `packages/testkit/tests/SharedToolConfigTest.php`).
- M0-T3: Arkitekturtesten for `strict_types` fra T1 tjekkede ingen pakkeklasser. Pest slår et namespace op i Composers PSR-4-map, og der findes ingen mapping for `Cbox\Cms` alene. Testen lister nu hver pakkes namespace ud fra manifesterne. Kontrollen er strammet, ikke svækket: en klasse uden `strict_types` i `packages/core/src` bestod før og fejler nu.
- M0-T3: `PostgresRolesTest` fra T2 er skrevet om, så den består niveau 10: en `try`/`catch` i stedet for `toThrow` med en typet closure, og en hjælper i stedet for `$this->table`. Påstandene er de samme. Pest tæller 8 færre assertions, fordi tjekket af undtagelsens klasse nu er selve `catch`. Tjekket ved at ændre SQLSTATE (8 fejl) og ved at lade én sætning lykkes (1 fejl).
- M0-T3: Rector ændrede eksisterende kode: `#[Override]` på `$enablesPackageDiscoveries` i `tests/TestCase.php`, `env()` i `WorkbenchServiceProvider` er ikke længere statisk, og to tests bruger `__DIR__.'/../src'`.
- M0-T5: GUARDRAILS 2.5 og Infrastructure. Tabellen siger at Infrastructure ("Eloquent, Postgres, drivere") kun må afhænge af domæne og kontrakter. Eloquent er selv frameworket, og 2.2 lægger modellerne i Infrastructure. Læsningen i Tolkninger: `Illuminate\Database` er tilladt, `Illuminate\Http`, facades, actions og overflader er ikke, og `mixed` er forbudt. Postgres-lagrene og deres rækkemappere ligger i Adapter. Bekræft eller ret læsningen, før T4 fryser `mixed`-reglen. Får en model brug for `mixed`, fx ved at overskrive `getAttribute()`, skal den ligge i Adapter, eller reglen skal ændres.
- M0-T5: Nye kontroller (GUARDRAILS 7.3): `tests/Arch/*`, hjælperne i `tests/Support/Arch/`, suiten `Arch` i `phpunit.xml` og testene af scanneren i `tests/Feature/Tooling/ArchitectureScannerTest.php`. Hver regel er set fejle på en midlertidig fil og blive grøn igen, da filen blev fjernet.
- M0-T5: Pest fund. Med en liste af mål består `not->toUse()`, så snart ét mål ikke bruger afhængigheden, og med en tom liste af mål fejler den. Reglerne tjekker derfor hvert mål for sig og siger det eksplicit, når et lag endnu er tomt (`Rules::forbid`). `toOnlyUse()`, `toBeFinal()`, `toBeReadonly()` og `not->toBeUsed()` opfører sig korrekt med lister.
- M0-T5: Namespaces uden lagsegment, fx service providers og det kommende indhold i generators og testkit, får kun de globale regler. Overvej senere at kræve et lag for al kode uden for pakkens rod.

## Kontroller kørt

Seneste resultat per port:

- Ingen endnu.
