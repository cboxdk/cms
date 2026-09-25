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
- M0-T4: "Typet array" betyder `list<T>` eller `array<K, V>`, hvor hverken K eller V er `mixed`, i alle dybder. `array`, `mixed[]`, `list<mixed>`, `array<string, mixed>` og `list<array>` er utypede. `Foo[]` og `array<Foo>` har ingen nøgletype og tæller også som utypede. Array shapes og tupler som `array{id: int}` er ikke tilladt uden for Boundary og Adapter, fordi strukturerede data er DTO'er (GUARDRAILS 2.2). `mixed` er forbudt i alle dybder: generiske argumenter som `Collection<int, mixed>`, iterables og callable-signaturer. `Generator<int, Foo>` efterlader TSend og TReturn som `mixed` og skal skrives `Generator<int, Foo, null, void>`. En template som `T` er en generisk parameter og er tilladt. En template uden bound er tilladt; en bound som `of array` er ikke.
- M0-T4: Reglen tjekker erklæringer: parametre og returtyper på metoder, funktioner, closures og arrow functions, properties, template-bounds og typerne i klassens tags for extends, implements, use, property og method. Inline `@var` i en krop og konstanter tjekkes ikke. En erklæring helt uden type er PHPStans egen `missingType.*`-fejl og rapporteres ikke to gange. Promoverede properties tjekkes som konstruktørparametre. Closures tjekkes kun på native typer, fordi PHPStan ikke læser PHPDoc på closures og udleder resten.
- M0-T4: Reglen for `mixed` og arrays gælder ikke testkode: et namespace med segmentet `Tests` eller det globale namespace, hvor Pest-filerne ligger. Det følger T3's tolkning, at tests ikke er domæne. Derfor skal al kode i `packages/*/src` og `workbench/app` have et namespace uden `Tests`-segment; en ny Arch-test (`tests/Arch/NamespacesTest.php`) tjekker det. Reglen for `@phpstan-ignore` gælder også tests, fordi GUARDRAILS 2.2 ikke undtager dem.
- M0-T4: Begge regler giver fejl, der ikke kan ignoreres (`nonIgnorable()`). Hverken en ignore-kommentar eller `ignoreErrors` kan skjule dem. Reglen for `@phpstan-ignore` læser filens tokens i en collector og rapporterer efter analysen, så den også finder kommentarer, som ikke hører til en node. Uden `nonIgnorable()` ville en kommentar på sin egen linje skjule fejlen om sig selv. Testene viser det med en kontrolregel, der bygger de samme fejl som almindelige fejl: så forsvinder de.
- M0-T4: Reglerne ligger i `Cbox\Cms\Testkit\Phpstan` uden lagsegment, så reglen for `mixed` også gælder deres egen kode. De er `#[Internal]`, fordi addons kun bruger dem gennem neon-filen. Regel 1 er seks små regler, én per nodetype, fordi PHPStan fraråder `instanceof` på sine egne noder (`phpstanApi.instanceofAssumption`). Identifikatorerne er `cboxCms.mixed`, `cboxCms.untypedArray`, `cboxCms.arrayShape` og `cboxCms.phpstanIgnore`.
- M0-T4: Regeltestenes fixtures har endelsen `.php.inc`. Så analyserer rodens PHPStan, Pint og Rector ikke den bevidst forkerte kode, og `phpstan.neon` behøver ingen `excludePaths`. De autoloades gennem en classmap i `autoload-dev`, fordi PHPStans RuleTestCase skal kunne finde klasserne.
- M0-T28: `#[Internal]` kan sidde på en klasse, en metode eller en klassekonstant. På en klasse dækker den hele klassen, også dens metoder og konstanter. Kode uden for `Cbox\Cms` må ikke bruge den, og det globale namespace er uden for. Reglen er tre udvidelser til PHPStans restricted usage-regler og ikke en egen regel. De finder brug i `new`, statiske kald, konstanter, `::class`, `instanceof`, `catch`, `extends`, `implements`, traits, attributter, native typer og PHPDoc, og kald af metoder. Hver brug rapporteres én gang. Identifikatoren er `cboxCms.internalUse`.
- M0-T28: Pest-filer i monorepoet der bruger interne klasser, har nu et namespace under `Cbox\Cms`, fx `Cbox\Cms\Tests\Feature\Tooling` og `Cbox\Cms\Core\Tests`. Ni filer er ændret. Det globale namespace er uden for kernen, ligesom et addons Pest-filer, og det er meningen: et addons tests må heller ikke binde sig til interne klasser.
- M0-T28: Transaktionsreglen gælder `transaction`, `beginTransaction`, `commit`, `rollBack`, `savepoint` og `createSavepoint` i Actions og Jobs. Modtageren er en `ConnectionInterface`, en `ConnectionResolverInterface` (database-manageren), `PDO` eller `DB`-facaden statisk. En domænemetode der hedder `commit()`, rapporteres ikke. Strengreglen rapporterer hver streng, heredoc og nowdoc i Actions og Jobs med ordet `SAVEPOINT`, uanset store og små bogstaver. `ROLLBACK TO` og `RELEASE` alene rapporteres ikke, for de virker kun på et savepoint der er oprettet med `SAVEPOINT`. Testkode er undtaget. Identifikatorerne er `cboxCms.transaction` og `cboxCms.savepoint`, og fejlene kan ikke ignoreres.
- M0-T28: String-id-reglen gælder offentlige metoder uden for Boundary og Adapter, også konstruktører og dermed promoverede properties, Infrastructure og namespaces uden lag. Et id er en parameter der hedder `$id` eller ender på `Id`, og returtypen af en metode der hedder `id` eller ender på `Id`, fx `getId()`. Typen er `string`, `?string` eller en undertype som `non-empty-string`. `int`, `int|string` og offentlige properties uden for konstruktøren tjekkes ikke. Et værdiobjekt tager sin streng som `$value`. Testkode er undtaget. Identifikatoren er `cboxCms.stringId`, og fejlen kan ikke ignoreres.

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
- M0-T4: Nye kontroller (GUARDRAILS 7.3): reglerne i `packages/testkit/src/Phpstan`, registreringen i `packages/testkit/config/phpstan.neon`, regeltestene og fixtures i `packages/testkit/tests/Phpstan`, `tests/Feature/Tooling/LayerTypeRulesTest.php` og Arch-testen `tests/Arch/NamespacesTest.php`. Set fejle på midlertidige filer i `packages/core/src` og blive grønne igen. Et addon i scratchpad, der kun kræver testkittet, får også reglerne.
- M0-T4: Læsningen af "typet array" er streng: array shapes, `Foo[]`, `array<Foo>`, `Generator<int, Foo>` og `Traversable<Foo>` rapporteres uden for Boundary og Adapter. Bekræft eller løsn den, før der kommer kode i Domain og Actions. Det er billigt at løsne senere og dyrt at stramme.
- M0-T4: Collectoren læser den analyserede fil med `SplFileObject`. `file_get_contents` er forbudt uden for gateway-namespacet af Arch-reglen for udgående HTTP, og PHPStans egen `FileReader` er ikke dækket af PHPStans løfte om bagudkompatibilitet (`phpstanApi.method`). Stien kommer fra PHPStan og tjekkes med `is_file`. Arch-reglen er ikke ændret. Overvej om den skal ramme URL-wrappers frem for funktionsnavne.
- M0-T4: En klasse der implementerer et PHP-interface med `mixed` i signaturen, fx `ArrayAccess::offsetGet(mixed $offset)`, kan ikke ligge i Domain eller Actions. Den hører til i Adapter (GUARDRAILS 2.2: "signaturer som frameworket kræver").
- M0-T4: Testkittets `LayerScope` har sin egen liste af lagnavne, fordi testkittet ikke kan bruge `tests/Support/Arch/Layer`. `LayerTypeRulesTest` holder listerne og læsningen af det inderste segment ens. Overvej at flytte `Layer` til testkittet, når Arch-reglerne skal nå addons.
- M0-T28: Nye kontroller (GUARDRAILS 7.3): `TransactionCallsRule`, `SavepointStringsRule`, `StringIdsRule`, `InternalUse` og de tre `Internal*UsageExtension` i `packages/testkit/src/Phpstan`, registreringen i `packages/testkit/config/phpstan.neon`, regeltestene med fixtures, `internal-use.neon` og `RegisteredRules` i `packages/testkit/tests/Phpstan`, og de nye tests i `tests/Feature/Tooling/LayerTypeRulesTest.php`. Set fejle, da registreringerne midlertidigt blev fjernet.
- M0-T28: `cboxCms.internalUse` kan ignoreres. PHPStans restricted usage-API bygger fejlen, og en udvidelse kan ikke gøre den non-ignorable. En ignore-kommentar uden for Boundary og Adapter rapporteres stadig af `cboxCms.phpstanIgnore`. Men i et addons Boundary og Adapter, og i dets `ignoreErrors`, kan fejlen skjules. En egen regel per nodetype kunne give en fejl der ikke kan ignoreres, men med mange flere klasser. Beslut om det er nok.
- M0-T28: Kontrakttesten "declares every attribute for classes only" er delt i to. `Internal` har nu `TARGET_CLASS | TARGET_METHOD | TARGET_CLASS_CONSTANT`, og en ny test låser præcis de flag. De andre attributter er stadig kun til klasser. Opgaven beder om en regel for metoder og konstanter markeret `#[Internal]`, og det kræver at attributten må sidde der.
- M0-T28: String-id-reglen rammer også metoder som frameworket kalder ved navn, fx et jobs `uniqueId(): string` til `ShouldBeUnique`. Sådan en metode kan ikke flyttes til Adapter. Laravel læser også en offentlig property `$uniqueId`, som reglen ikke tjekker. Ellers skal reglen løsnes for `Jobs`.
- M0-T28: RuleTestCase kører kun én regel, men `cboxCms.internalUse` kommer fra mange af PHPStans egne regler. Testen samler derfor alle reglerne i testcontaineren i `RegisteredRules` og registrerer udvidelserne med `internal-use.neon`. Den rigtige neon kan ikke bruges i en RuleTestCase: Larastan starter en Laravel-app og efterlader dens fejlhåndtering, så PHPUnit markerer testen som risky. `LayerTypeRulesTest` tjekker den rigtige registrering.

## Kontroller kørt

Seneste resultat per port:

- Ingen endnu.
