# Beacon v2 of the portal

A small [GA4GH Beacon v2](https://docs.genomebeacons.org/) API under `/api/beacon`, for the discovery of what the Swiss FEGA Node
holds: the **published datasets** and the **studies that hold them, as cohorts**. It follows the proposal of the ELIXIR report on
Beacon and the FHD model (entry types limited to cohorts and datasets). It is open (no login, no token) and read only.

Nothing leaves the portal that the catalogue of published datasets does not show already: no draft, submitted, approved or
withdrawn dataset, no sample, no file. The metadata of the samples stays encrypted in FEGA; the Beacon only uses it to count and to
filter (see *Filters*).

## Endpoints

All answers are JSON. `GET` only. The address is the API address plus `beacon`
(`https://<portal>/api/beacon`, on the dev server `https://fhd-portal-dev.fega.swiss/api/api/beacon`).

| Endpoint | Answer |
|---|---|
| `/` and `/info` | the Beacon, its version and its organisation (SIB), the number of datasets and cohorts |
| `/service-info` | the GA4GH service-info object |
| `/configuration` | maturity (`DEV`, `TEST`, `PROD`), security (everything is `PUBLIC`) and the entry types |
| `/map` | the endpoints of the two entry types |
| `/entry_types` | `dataset` and `cohort` |
| `/filtering_terms` | the filters that this Beacon accepts, with their values (`skip`, `limit`) |
| `/datasets`, `/cohorts` | the collections (`filters`, `skip`, `limit`, `requestedGranularity`) |
| `/datasets/{id}`, `/cohorts/{id}` | one entry as a result set, or an empty one when there is no such entry (`requestedGranularity`) |

```bash
curl https://<portal>/api/beacon/datasets
curl 'https://<portal>/api/beacon/datasets?filters=libraryStrategy:WXS,NCIT:C16576&limit=5'
curl 'https://<portal>/api/beacon/cohorts?requestedGranularity=count'
curl https://<portal>/api/beacon/datasets/CHFEGAD00000000054
```

### Parameters

- `skip` and `limit`: the specification counts **pages**: `skip=2&limit=4` gives the entries 9 to 12. Default `limit` 10, `0` means
  all, the maximum is 1000. A value that is not a whole number gives an error 400.
- `requestedGranularity`: `boolean`, `count` or `record` (the default here, since everything is public). `boolean` and `count`
  answer without the `response` block.
- Unknown filters, collections or granularities give an error response with the status 400 (404 for an unknown collection).

## What an entry holds

**Dataset** (`/datasets`), from the published datasets: `id` (public id, `CHFEGAD…`), `name` (title), `description`, `createDateTime`
and `updateDateTime`, `externalUrl` (the page of the dataset in the portal, where access is requested), and in `info`: the study,
the dataset types, the number of runs and analyses, the released date.

**Cohort** (`/cohorts`), from a study that has at least one published dataset: `id` (public id, `CHFEGAS…`), `name` (title),
`cohortType` `study-defined`, `cohortSize` (the number of distinct `subject_id` of its samples, left out when the samples have none),
and in `info`: description, study type, the datasets and the number of samples.

The cohort schema of the specification has no description or dates: they are in `info`. There is no `dataUseConditions`: the policies
are those of the DAC portal and are not coded with DUO terms.

## Filters

The metadata of the portal is not linked to ontologies yet. The filtering terms are therefore:

| Filter | Source | Kind |
|---|---|---|
| `NCIT:C16576` (female), `NCIT:C20197` (male) | `biological_sex` of the samples | ontology |
| `EFO:0009656` (case), `EFO:0009654` (control) | `case_control` of the samples | ontology |
| `libraryStrategy`, `librarySource`, `librarySelection`, `libraryLayout` | the experiments | alphanumeric |
| `platformModel` | `instrument_model_id` of the experiments | alphanumeric |

The terms of the ontologies are those of the mapping table of the report (`BeaconFilters::ONTOLOGY_TERMS`), and only the terms that
a published dataset has are listed by `/filtering_terms`.

Syntax of `filters` (comma separated, all must match, there are no boolean expressions):

- ontology: `NCIT:C16576`
- alphanumeric: `libraryStrategy:WXS` (also `libraryStrategy:=WXS`), the case is ignored; `libraryStrategy:!WGS` excludes a value;
  `%` is a wildcard (`platformModel:ILLUMINA%`).

A **dataset** has the values of the samples and experiments of **its own runs and analyses**; a **cohort** has those of the whole
study. The descendants of an ontology term are not included (the specification sets that in POST requests, not offered here).

## Settings

Everything has a default derived from `FEGA_API_URL`; set these variables to change it:

| Variable | Default |
|---|---|
| `BEACON_ID` | the host of `FEGA_API_URL`, reversed (`swiss.fega.fhd-portal-dev`) |
| `BEACON_NAME`, `BEACON_DESCRIPTION` | Swiss FEGA Node (FHDportal) Beacon, and a description |
| `BEACON_ENVIRONMENT` | `prod` (`dev`, `test` or `prod`) |
| `BEACON_PORTAL_URL` | `FEGA_API_URL` without its `/api` part |
| `BEACON_BASE_URL` | `FEGA_API_URL` + `beacon` |

The model (the datasets, cohorts and filtering terms) is built from the database and kept for **5 minutes**
(`BeaconRepository::CACHE_SECONDS`): a dataset that is published shows up within that time. The answers carry
`Cache-Control: public, max-age=60`. The usual rate limit of anonymous requests applies (60 per minute and address). The CORS rule
`^/api/beacon` of `config/packages/nelmio_cors.yaml` lets browsers of any site read it.

## Code

`BeaconController` (routes) → `BeaconService` (the responses) → `BeaconRepository` (database, cache) and `BeaconFilters` (terms and
filters); `BeaconConfig` for the settings. The tests are in `tests/Service/Beacon` and `tests/Controller/BeaconControllerTest.php`.

## Specification

The responses follow the schemas of the default model v2.0.0 (`apiVersion` `v2.0.0`; the latest release of the specification is
2.2.0, the schema ids still say 2.0.0). They were checked against the JSON schemas of the specification (the framework responses,
the dataset and the cohort schemas): clone https://github.com/ga4gh-beacon/beacon-v2 and validate the answers with a
JSON Schema 2020-12 validator, with `framework/json/responses/*.json` for the envelopes and
`models/json/beacon-v2-default-model/{datasets,cohorts}/defaultSchema.json` for the entries.

## Not done

- No record entry types below the collections (individuals, biosamples, runs, analyses, genomic variants): the data of the samples
  stays in FEGA. The report describes the mapping of the FHD model to these types for a later step.
- No POST requests, no `includeDescendantTerms`, no handovers.
- Cohort inclusion criteria (age, disease, ...): they need descriptors in the study metadata, as the report proposes, with the new
  FEGA metadata schema that is linked to ontologies. The filtering terms will then use those ontology terms.
- The Beacon is not registered in the ELIXIR Beacon network yet.
