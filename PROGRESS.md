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

- Ingen endnu.

## Til review af Sylvester

Ændringer af kontroller, afvigelser fra GUARDRAILS og andet der skal ses af et menneske:

- Ingen endnu.

## Kontroller kørt

Seneste resultat per port:

- Ingen endnu.
