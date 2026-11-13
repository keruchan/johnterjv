# Permit and Seedling Intake Delivery

Updated: 2026-11-12. Implemented and tested locally; no production deployment performed.

Subsequent completion of the attachment-only revisions and owner-authorized dummy data is documented in [REVISION_COMPLETION.md](REVISION_COMPLETION.md). Earlier missing-provider/Unknown/unassigned limitations below describe the previous batch and are superseded for this local demonstration.

## Changes and task mapping

| Requested task | Delivered behavior |
| --- | --- |
| Remove 50-seedling limit; annual quota | Requests above 50 are accepted. The annual 15,000 monitoring target resets January 1 and allows releases above target. Counts use negative released stock movements at ready-for-pickup, not collection. Existing stock and authorization checks remain. |
| Simplify permit fields | Removed Property Classification, Lot/Property Reference, Purpose Details, and Condition or measurement notes from new intake. Detailed property location is optional. Each tree has its own origin; quantity, scientific name, diameter, and height remain. Additional application notes follow Tree Records. Historical records and approval snapshots are preserved. |
| Documents in initial application | Save a draft, attach the current matrix's mandatory scans in the documents step, then submit. Server submission checks required attachments. Owner draft downloads remain private from processing roles until submission. Document table, remarks, and replacement history remain available. |
| Site visit tally and replacements | RPS uploads a protected XLSX matching the supplied 11-column template. Validated individual rows marked “for cutting” count toward the replacement total. Existing matrix rules supply Natural 1:100, Planted 1:50, and NGA earth-balling 1:0; unsupported combinations are rejected. Approval snapshots the tally-based requirement. |
| Species recommendations | Request choices prioritize the latest finite estimated run-out date. Unknown estimates are labeled and follow known dates. Donation suggestions prioritize confirmed indigenous species and stock/depletion needs; Natural-tree requirements suggest only confirmed indigenous species. Existing 90-day release forecasts are reused. |
| Donation, release, and completion | Existing EMS receipt verification and RPS donation confirmation remain. RPS releases the physical permit and records physical tarpaulin issue. Client reports cutting completion; EMS records whether all approved trees were cut, whether additional trees were affected, and a description. Adverse findings stay open for follow-up. Closure requires clean findings or an explicit recorded resolution of earlier findings. |
| Instructions, fees, and remarks | Persistent stage instructions identify the next actor/action. The sidebar uses existing configured fees. Replacement and decline dropdowns retain additional remarks and “Other” explanations. Field-completion marks are separate from staff verification. Inline errors navigate to the relevant wizard section. |
| Barangay map and analytics | Structured planting destination and optional planting-site coordinates are captured for new requests. Community and EMS request pages and CENRO Analytics show actual release totals with year/date/species/municipality/barangay filters, map legend, annual target, and unassigned totals. Analytics identifies top destinations and location gaps. EMS Dashboard and Inventory Reports retain existing cards and gain the target panel. |
| Eligibility, terms, and map restrictions | Request guidance describes actual active-account, accurate-information, stock-review, pickup, and cancellation rules. No new unapproved eligibility restriction is introduced. Existing Laguna polygon, coordinate validation, and zoom restrictions are retained. |

## Pages to review

| Account | Page | What to check |
| --- | --- | --- |
| Community | `pages/community/permit-application.php` | New/saved draft fields, per-tree origin, notes placement, fees guide, completion marks, document step and initial uploads. Submitted detail: tally, donation suggestions, instructions, tarpaulin issue, Mark as done and EMS findings. |
| Community | `pages/community/permit-applications.php` | Final-verification and follow-up status labels. |
| Community | `pages/community/seedling-requests.php` | Requests above 50, forecast ranking, planting destination/site, eligibility guidance, barangay distribution and annual target. |
| RPS | `pages/cenro/permit-application.php` | Document reasons/remarks, Excel tally, readiness and calculated requirement, donation confirmation, physical permit/tarpaulin issue, completion history. |
| EMS | `pages/ems/donation-registry.php` | Existing receipt/verification flow with tally-derived quantities and donation suggestions. |
| EMS | `pages/ems/permit-final-verification.php` | New navigation item, dated interactive cards, client reports, adverse findings, follow-up and closure. |
| EMS | `pages/ems/seedling-inventory.php` | Species indigenous classification; existing records start Unknown until staff verifies them. |
| EMS | `pages/ems/seedling-requests.php` | Existing approval/fulfilment flow, barangay map and distribution filters. |
| EMS | `pages/ems/dashboard.php`, `inventory-reports.php` | Annual distribution target alongside existing content. |
| RPS / permitted Superadmin | `pages/cenro/analytics.php` | Barangay totals, map, filters, location coverage insights. Existing permission gates apply to permit actions. |

## Schema and deployment

Run from the project root before the updated pages serve traffic:

```powershell
C:\xampp\php\php.exe scripts/apply_intake_updates.php
```

The additive migration is already installed locally. The CLI runner checks column metadata and can be rerun; local execution was verified on MariaDB 10.4. No MySQL server was available for separate validation.

`config/intake_updates_schema.sql` adds intake/completion flags, per-tree origin, indigenous metadata, planting-destination snapshots, tally versions, client completion reports, EMS verification history, and physical tarpaulin issue dates. It makes the requirement's historical property classification nullable. It does not delete or reclassify historical records. The base schema points to this follow-up migration.

New/edited drafts use `intake_version=2`. Existing applications remain version 1 and retain their original approval/donation/completion policies. Existing original-hardcopy, wet-ink, fee, receipt, release, and validity gates remain. New completion actions require an active released permit; expiry does not automatically reopen or extend it.

The XLSX reader uses existing PharData and SimpleXML support, private storage, bounded archive/XML sizes, no formula execution, content validation, append-only versions, role checks, audit and notifications. No package or PHP extension was installed.

## Validation

- PHP syntax checks passed for all 41 changed PHP files; all four changed/new JavaScript files passed Node syntax checks.
- `tests/intake_updates_probe.php`: 33 checks, database writes rolled back. Includes tally parsing/namespace/formula/coordinates, field rules, actual new-intake approval and immutable requirement, owner/EMS handoff, follow-up, annual rollover, no double counting, and species ranking.
- `tests/seedling_lifecycle_probe.php`: 54 checks.
- `tests/permit_stage_status_probe.php`: 25 scenarios.
- `tests/permit_map_scope_probe.php`: 28 checks.
- `tests/analytics_deep_probe.php`: 37 checks.
- `tests/permit_document_http_validation.ps1 -DatabaseName certreefy`: 48 HTTP checks covering uploads, MIME/size/active-content validation, CSRF, ownership, role denial, protected downloads, batch replacement review, history, and initial draft uploads. The database parameter replaces its old hard-coded local database assumption.
- Local Playwright journey passed actual initial uploads/submission, draft download privacy, two-tree tally calculation, RPS reason controls, physical tarpaulin issue recording, client completion, adverse EMS findings, follow-up resolution, a 51-seedling request, and mobile width checks. No JavaScript errors were recorded. Its released-permit fixture was prepared separately; it does not claim a browser test of every existing approval/receipt/release gate.
- Browser smoke checks passed across 25 Community/RPS/EMS/Superadmin registry and dashboard pages, including date filters/cards, invalid/future dates, role navigation and mobile dashboards, with no JavaScript errors. Anonymous access to all five new protected routes redirected to login.
- The supplied workbook is unchanged. Its original coordinates lie outside Laguna and are correctly rejected; tests used temporary copies with valid coordinates.

## Data and scope limitations

- Historical free-text planting destinations are not automatically matched to barangays. Unassigned totals remain visible. Requests with structured names but no recorded coordinate appear in totals/table without a fabricated map pin.
- Indigenous status starts Unknown. EMS must classify inventory species before strict Natural-tree suggestions can be populated. Suggestions are stock/forecast guidance, not a guarantee of planting suitability.
- The existing configured checklist, replacement matrix, and fees are reused. Any later office-approved policy change needs its own explicit update.
- Tarpaulin handling records physical issue; no artwork or generated tarpaulin document was requested or created.
- A separate seedling-provider directory was mentioned in the earlier brief but lacked provider data/maintenance rules and was absent from the final expanded task list. Planting-site selection is available.
- Final verification requires findings and description; no new mandatory photo policy was invented. Existing legacy completion evidence remains supported.
- Some deployed historical private files are absent from this checkout. New uploads and protected downloads passed; missing historical files still require restoration from deployment storage.

## Changed implementation files

- Schema/config: `config/config.php`, `config/schema.sql`, `config/intake_updates_schema.sql`, `scripts/apply_intake_updates.php`, `scripts/seedling_reporting_readiness.php`.
- Permit services: `includes/permit.php`, `permit_matrix.php`, `permit_workflow.php`, `permit_documents.php`, `permit_decisions.php`, `permit_donations.php`, `permit_donation_view.php`, `permit_release.php`; new `permit_guidance.php`, `permit_tally.php`, `permit_completion.php`, `permit_tarpaulin.php`.
- Seedling/shared services: `includes/seedling.php`, new `seedling_distribution.php` and `seedling_recommendations.php`, plus `registry_filters.php`, `navigation.php`, `notifications.php`.
- Community pages: permit application/list/document-upload and seedling requests.
- CENRO pages: permit application/document-review/decision-action, new tally-upload/tarpaulin-action endpoints, analytics.
- EMS pages: dashboard, inventory reports, seedling inventory/requests, new final-verification registry.
- Protected record endpoints: new `pages/records/permit-tally-download.php` and `permit-completion-action.php`.
- JavaScript: `js/geo-map.js`, `permit-form-feedback.js`, new `seedling-distribution.js` and `permit-reasons.js`.
- Tests: new intake probe; updated seedling lifecycle, stage/status, and document HTTP tests. Context, implementation, clarification, review-pack, and tally-reference notes were updated.
