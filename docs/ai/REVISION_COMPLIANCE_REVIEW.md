# Revision Compliance Review

Updated after implementation: 2026-11-12.

**Current status: all nine remaining tasks are implemented locally for the owner-confirmed dummy database.** The initial findings below are retained as a historical review, not a current outstanding-task list.

| Previously outstanding item | Current status |
| --- | --- |
| Cascading locations | Complete: both forms limited to Laguna, with parent-dependent municipality/barangay options and server hierarchy validation. |
| Area-unit selection and hectare conversion | Complete: ha/sqm/sqkm, server conversion and consistent fee guide. |
| Generated tree-record table | Complete: estimated total generates rows, preserves existing entries and supports grouped quantities. |
| Species dropdown with Other | Complete: active inventory catalogue plus manual Other. |
| Scientific-name autofill | Complete: browser fill and server resolution. |
| Seedling-provider locations | Complete: EMS management, contacts/source/status/map, and provider cards/map in Community permit donation details. |
| Historical destination data | Complete for demonstration: 44 dummy requests assigned sample destinations; EMS correction form requires a reason and preserves audit/owner notification. |
| Indigenous classification | Complete for demonstration: ten species classified, with five indigenous/five non-indigenous; EMS retains editing controls. |
| Depletion release history | Complete for demonstration: ten recent sample releases produce finite forecasts. Fixture script is repeatable without duplicating requests/providers. |

Current sample reporting shows **613 released seedlings across three barangays**, no unassigned current-year releases, three sample providers and ten finite forecasts. Fixtures are synthetic and preserve their source/audit provenance.

See [REVISION_COMPLETION.md](REVISION_COMPLETION.md) for pages, changed files, migration/demo commands, limits, references and validation. The original “Name only” instruction remains superseded by the owner's explicit decision to retain the additional tree fields. No production deployment was performed.

## Original review before the implementation above

Reviewed: 2026-11-12. Scope: current local checkout and connected local database.
No application code or business records were changed during this review.

## Sources and interpretation

- Word document: `C:\Users\APEC Homes\Downloads\COMMENTS AND REVISIONS.docx`. All available body/table text was extracted from its DOCX XML. The file contains no embedded images, screenshot media, Word comments, or tracked deletions. Number-only/blank entries cannot be interpreted as additional specifications.
- The user's 13 numbered notes in the chat.
- Later explicit owner answers take precedence where they conflict with earlier wording: keep quantity/scientific name/diameter/height, use latest estimated run-out dates, count distribution at stock deduction, use the annual target as monitoring, and keep adverse EMS findings open.
- The attachment is a revision checklist to assess. It does not authorize implementing additional requirements during this review.
- Current source and read-only database checks support the findings below. Prior delivery tests are supporting evidence, not proof that newly discovered attachment requirements were implemented.

**Overall: not all attachment requirements are complied with.** Most of the last authorized batch is implemented. Location dropdowns, area-unit conversion, generated tree-entry tables, species selection/autofill, and provider locations remain missing. Some implemented reporting/recommendation features lack the data needed to display useful results.

## The 13 chat notes

| Note | Result | Current behavior / evidence |
| --- | --- | --- |
| 1. Released seedlings per barangay with map, filters and legend | Implemented; current data incomplete | `includes/seedling_distribution.php` and `js/seedling-distribution.js` provide release-movement totals, year/date/species/municipality/barangay filters, pins and legend. Used on Community/EMS request pages and CENRO Analytics. Current 2026 data: 461 released seedlings, all unassigned, zero mapped barangay groups. Existing free-text locations were not guessed into coordinates. |
| 2. Remove Property Class and Property Reference | Implemented for new intake | `pages/community/permit-application.php` removes the inputs; `includes/permit.php` ignores forged removed values for version 2. Historical records/snapshots remain preserved. |
| 3. Initial documents → RPS → tally → replacements → suggestions → RPS confirmation → permit/tarpaulin → client done → EMS final | Implemented with boundaries | Draft scan uploads precede final submission; RPS tally upload creates validated counts and approval-time replacement requirements; client donation suggestions, existing EMS receipts/RPS confirmation, physical permit release, tarpaulin issue record, client report and EMS findings are implemented. Both EMS questions and description are required; adverse findings remain open for follow-up. New completion handoff applies to version 2; version 1 retains its legacy policy. Tarpaulin is a physical issue record, not generated artwork. Strict Natural-tree suggestions need confirmed indigenous inventory data. |
| 4. Highest depletion date for request recommendations | Implemented; forecast data unavailable locally | `includes/seedling_recommendations.php` ranks latest finite estimate first and unknown estimates last, as the owner clarified. All 10 active species currently lack sufficient recent releases for a finite estimate. |
| 5. Fees guide on the side | Implemented | Application Snapshot sidebar shows existing configured fees and live area-based assessment. This follows the later chat placement request rather than the Word document's earlier “under purpose” wording. |
| 6. Tree records: Name only | Superseded by later answer | The owner explicitly requested keeping quantity, scientific name, diameter and height. Current application retains those fields and per-entry origin. This is not treated as a defect. The Word document's separate table/dropdown/autofill requirements remain unimplemented; see below. |
| 7. Accepted/rejected remarks | Implemented | Owner and RPS document tables show scan and original-review remarks; prior document review remarks remain in history. See Community permit page around lines 939–1007 and RPS around lines 566–578. |
| 8. Checks/crosses for complied fields | Implemented as field completion | The editable application shows section-level Complete/Incomplete marks in Field Completion. They describe required inputs/attachments, not RPS approval. Staff verification remains separately displayed. |
| 9. Decline reasons dropdown | Implemented | `includes/permit_guidance.php`, RPS `declineReason` select and server reason/remarks validation provide categories plus Other explanation. |
| 10. Application map restrictions | Implemented | Shared map uses the Laguna polygon, bounds and controlled minimum zoom. Typed/selected coordinates are checked in the browser and on the server. Blank optional coordinates remain allowed; this is not automatic municipality/barangay address validation. |
| 11. Application error marks | Implemented | `js/permit-form-feedback.js` provides field errors, invalid-state association, an error summary and navigation to the relevant wizard section. |
| 12. Seedling eligibility / terms | Basic guide implemented | Community request form shows active-account, accurate-information, EMS stock review, pickup and cancellation guidance. Formal office-specific eligibility criteria, legal wording or a mandatory consent checkbox were not supplied; none is claimed to be implemented. |
| 13. Analytics insights | Implemented | Existing descriptive/predictive/prescriptive reporting is extended by destination totals, highest recorded barangay, unassigned coverage and annual target. Forecast/map insights remain limited by the data described above. |

## Word document requirements beyond / duplicated by the notes

| Requirement | Result | Finding |
| --- | --- | --- |
| Multi-section Client application with Next, Back and progress bar | Implemented | Seven-step wizard with Previous/Next, progress indicator and save/submit controls; documents are a separate step. |
| Laguna-only, larger cutting map | Implemented | Property map uses `geo-map geo-map-tall`; shared desktop tall height is 520px, responsive mobile height 380px. Laguna polygon and zoom controls are shared. |
| Persistent instructions after major permit actions | Implemented for permit details | `permit_next_steps()` and owner/RPS renderers cover submission, documents, inspection, approval, donation, release, completion and follow-up. This is not evidence of state-specific instructions across every unrelated module. |
| Document upload table | Implemented | Owner document table separates requirement, scan/review, original verification and upload. |
| Document replacement reason dropdown | Implemented | RPS replacement reason list, extra remarks and Other explanation are present with server validation. |
| Donation species based on depletion | Implemented; inventory classification incomplete | Ranking considers confirmed indigenous status, low stock and depletion. All 10 active species currently have Unknown indigenous status; strict Natural-tree suggestions therefore have no confirmed candidates. |
| Planting-site locations for seedling requesters | Implemented | Community request page has EMS-recommended site cards/map and optional site selection. New requests can snapshot the selected site's recorded location. |
| Seedling-provider locations for permittees | Not implemented | No provider location directory/map or provider-management flow is exposed in the permit donation panel. Planting sites are a separate feature and do not fulfil this item. |
| Cutting application District → Municipality → Barangay dropdowns | Not implemented | These are still free-text inputs around lines 490–504 of the Community application. Polygon validation does not supply cascading address dropdowns. |
| Seedling request Province → Municipality → Barangay dropdowns | Not implemented | Municipality and barangay are free-text fields. Optional planting-site selection can populate them, but there is no cascading province/municipality/barangay selector. |
| Area-unit dropdown: hectare, square metre, square kilometre; convert to hectares | Not implemented | Only `area_hectares` numeric input exists. No alternate unit selector or conversion workflow is present. |
| Enter estimated total number of trees and automatically generate the table | Not implemented | Users add/remove tree-entry cards manually, with quantity per entry. No estimated-total control generates a tree table. |
| Species dropdown with Other textbox | Not implemented in permit intake | Common name remains a free-text input. Seedling inventory dropdowns elsewhere do not fulfil this tree-record requirement. |
| Scientific name automatic from species | Not implemented | Scientific name remains manually entered; no species-to-scientific-name autofill is wired to permit intake. |
| Tree category Planted/Natural | Implemented per entry | Tree Origin selector is present on every tree entry, retaining existing matrix-supported origins. |
| Remove 50-seedling request cap | Implemented | No 50 policy cap in request form/service; prior real browser test accepted 51. Storage and stock safeguards still apply. |
| Annual seedling distribution quota 15,000 | Implemented per later clarification | Monitoring target, January 1 reset, counted at stock deduction/ready-for-pickup; exceeding target does not block release. |
| Move Additional application notes after Tree Records | Implemented | Notes follow tree-entry controls in the application form. |
| Remove Purpose Details / Condition or measurement notes | Implemented for new intake | Inputs removed and ignored by new-intake normalization. The supported purpose selector remains. |
| Move Tree Origin into Tree Records | Implemented | Origin is per tree entry. |
| Detailed Property optional | Implemented | Detailed location is optional in form and server validation. |
| Property Classification / Lot Reference removal repeated | Duplicate | Covered by note 2; not counted as additional work. |
| Empty numbered Client/RPS/EMS/Admin/System rows | Unverifiable | No revision text or screenshot is embedded for these placeholders. No unstated requirement was inferred. |

## Current local data constraints

Read-only checks on 2026-11-12 found:

- 44 historical requests have planting-location text. New structured destination columns are installed.
- All-time released stock movements total 558 seedlings: 463 associated with claimed requests and 95 associated with ready-for-pickup requests. Those are release quantities, not new claims.
- Current-year (2026) distribution is 461 seedlings, all unassigned to a structured barangay; the current map has no attributed groups/pins.
- All 10 active inventory species have Unknown indigenous classification.
- All 10 have no finite depletion forecast from the current 90-day release window.

These findings describe this checkout, not production data. Fixing presentation code alone will not supply verified historical barangays, coordinates, species classifications or release history. Do not fabricate those values.

## Validation performed for this review

- Extracted the DOCX body/table XML and checked its relationships/archive contents for embedded images and comments.
- Inspected affected form controls, wizard, fees, feedback, map helpers, document views/reasons, tally/donation/completion services, seedling recommendations and distribution/analytics renderers.
- Ran read-only `scripts/seedling_reporting_readiness.php` and aggregate queries for current-year barangay coverage and indigenous classifications.
- Reran stage/status scenarios: 25 passed.
- Reran Laguna/map validation scenarios: 28 passed.
- Previous delivery evidence (225 focused checks and browser validation) is documented in `INTAKE_UPDATE_DELIVERY.md`. That evidence does not cover the missing document-only features listed above.
- No application source files, schema or business data were changed. Only this review report was added.

## Remaining work identified

1. Cascading address selectors for both application/request forms with an approved location dataset and province scope.
2. Area-unit selector and conversion to the existing hectare basis, including server validation.
3. Estimated-tree-count-driven table, species dropdown/Other handling, and scientific-name autofill while retaining fields confirmed by the owner.
4. Provider locations for permittees, with verified provider data and maintenance responsibility.
5. Verified historical destination/coordinate data, inventory classifications and genuine release-history coverage for maps/recommendations.
6. Office-specific eligibility/terms text or generated tarpaulin content only if those additional policies/artifacts are required.
