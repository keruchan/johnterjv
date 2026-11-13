# Completion of Remaining Revisions

Updated: 2026-11-12. Local implementation and demonstration data only; not deployed.

The owner confirmed that every database record is dummy data and requested both forms be restricted to Laguna. That clarification authorizes the demonstration data below; none is presented as verified operational history.

## All nine requested tasks

| Task | Delivered |
| --- | --- |
| 1. Cascading locations | Permit: Province fixed to Laguna, then District → Municipality → Barangay. Request: Province fixed to Laguna → Municipality → Barangay. The bundled 30-town/681-barangay catalogue works without a runtime API. Server checks the hierarchy. Parent changes clear dependent selections. |
| 2. Area units | Hectares, square metres and square kilometres. Browser and server convert to the existing hectare field and fee basis, rounded to its four-decimal storage precision. Forged conversions, unsupported units and invalid areas are rejected. |
| 3. Generated tree table | Estimated total generates rows while preserving existing entries. Quantities still allow grouping multiple trees of the same species. Practical table transport limit: 2,000 rows; the bounded JSON submission avoids PHP max_input_vars truncation. |
| 4. Species dropdown / Other | Active inventory is reused as the species catalogue. Other opens a manual species name. Existing names outside the catalogue remain supported. |
| 5. Scientific-name autofill | Selected inventory species fills its scientific name; PHP resolves selected IDs again. Other permits manual entry. |
| 6. Provider locations | EMS Seedling Providers registry supports create/edit, status, contacts, collection hours, source, Laguna address and map pin. Active providers appear on Community permit details once a donation requirement exists. Three clearly named demonstration providers are installed. |
| 7. Historical destinations | 44 old dummy requests received synthetic destinations across three real barangay names, using sample coordinates and audit provenance. EMS can correct a destination from Seedling Request detail with a required reason and owner notification. Original free-text description, quantities and release dates are preserved. |
| 8. Indigenous classifications | Existing EMS classification controls remain. The explicit demo script populated five indigenous and five non-indigenous classifications for the ten dummy species. These are demonstration classifications, not an independent botanical determination. |
| 9. Release history | Ten sample claimed requests and linked release ledger entries were added within the recent 90-day window. Balances change through the existing locked stock-movement helper. All ten species now have finite forecasts. No old release quantity was rewritten. |

## Current demonstration result

- 2026 released total: **613 seedlings**.
- Assigned destinations: **613**; unassigned: **0**.
- Pagsanjan / Sabang: 247; Santa Cruz / Bubukal: 216; Liliw / Kanlurang Bukal: 150.
- Three active sample providers.
- Ten classified inventory species, all with finite depletion estimates.

Destinations, provider locations and release history are synthetic fixtures. Exact coordinates do not assert a surveyed barangay boundary. The original 50-seedling cap remains removed; the annual 15,000 target remains monitoring, not a release block.

## Pages to check

- Community → Tree Permit → New application or editable draft: address dropdowns, area units/fee guide, Tree Records table, Generate rows, species and Other.
- Community → submitted permit with a donation requirement: Where to Obtain Seedlings map/cards.
- Community → Seedling Request: Laguna address selectors, sorted forecast estimates, planting-site selection, populated distribution map.
- EMS → Seedling Providers: new sidebar item, provider form, active/inactive cards, registry and map.
- EMS → Seedling Requests → open a record → Planting Destination: correction form, required reason, map pin.
- EMS → Seed Inventory: indigenous classification edits.
- EMS → Inventory Reports and RPS → Analytics: forecasts and destination totals/insights.

## Deployment and data commands

Schema additions are appended to `config/intake_updates_schema.sql`: `tbl_seedling_providers`, request planting province and destination source. The existing CLI migration runner is reused and already applied locally.

```powershell
C:\xampp\php\php.exe scripts/apply_intake_updates.php
```

**Dummy database only:** the separate fixture script is explicit and repeatable. It preserves existing rows, records provenance/audit, checks provider/request keys and skips duplicate demo releases/providers. A second local execution created no additional requests or destination updates.

```powershell
C:\xampp\php\php.exe scripts/seed_revision_demo.php --apply-demo-data
```

Running the migration never runs the demo seed automatically.

## Validation

- `tests/revision_fields_probe.php`: 24 conversion, hierarchy, species resolution, payload-size and coordinate checks.
- Updated existing fixture inputs to real Laguna names where the new hierarchy validation applies; retained the assertions.
- Existing intake probe: 33; seedling lifecycle: 54; stage/status: 25; Laguna map scope: 28; analytics math: 37; document HTTP: 48. Combined focused checks: 249.
- `tests/revision_fields_browser.cjs`: actual cascading selections, unit conversion and fee display, generated rows, species autofill/Other, draft persistence, a 130-row save, JSON transport, provider creation, CSRF/role/coordinate denial, destination correction/reason/audit (including a 1,000-character Unicode reason), and responsive layouts, including a mobile permit table that scrolls inside its own wrapper. Test fixtures are removed.
- Prior full browser journey was adapted to the new selectors and passed initial uploads/submission, tally calculation, private draft downloads, RPS reasons, tarpaulin issue, client completion, EMS follow-up/closure, request above 50 and mobile pages.
- Syntax checks passed for all 17 modified/new PHP files, all four modified/new JavaScript files, and the browser test script.
- Leaflet and Bootstrap remain the existing libraries; no application dependency or framework was added.

The browser probe uses an externally installed Playwright through `NODE_PATH`, with optional `CERTREEFY_PHP` and `CERTREEFY_TEST_URL` overrides.

## Main changed files

- New reusable helpers: `includes/locations.php`, `includes/intake_fields.php`, `includes/seedling_locations.php`.
- Reused services/components: `includes/permit.php`, `seedling.php`, `planting_sites.php`, `navigation.php`.
- New EMS pages/actions: `pages/ems/seedling-providers.php`, `seedling-provider-action.php`, `seedling-request-destination-action.php`; extended `seedling-request-detail.php`.
- Community pages: `pages/community/permit-application.php`, `seedling-requests.php`.
- JavaScript: `js/intake-fields.js`, `location-selects.js`, `seedling-locations.js`, `permit-form-feedback.js`.
- Data/schema/styles: `assets/geo/laguna-locations.json`, `assets/geo/README.md`, `config/intake_updates_schema.sql`, `css/dashboard.css`.
- Demo and validation: `scripts/seed_revision_demo.php`, `tests/revision_fields_probe.php`, `tests/revision_fields_browser.cjs`, updated intake and seedling lifecycle probes.
- Documentation: project context, implementation status, clarification record and compliance review.

## Location reference

Bundled names/codes were retrieved from the public [PSGC API mirror](https://psgc.gitlab.io/api/provinces/043400000/cities-municipalities/); city/municipality names and totals were checked against the [official PSA Laguna listing](https://psa.gov.ph/classification/psgc/citimuni/0403400000). The mirror snapshot is not represented as PSA's authenticated API or a full latest-publication import. See the asset README for provenance.
