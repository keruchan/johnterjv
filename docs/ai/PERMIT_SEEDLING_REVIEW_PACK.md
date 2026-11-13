# Permit and Seedling Review Pack

Original preparation: 2026-11-11
Superseded by implemented delivery: 2026-11-12

**Historical preparation record.** The draft wording, “pending” statements, and pre-migration database observations below describe the earlier review stage. They are not the current implementation status. See [INTAKE_UPDATE_DELIVERY.md](INTAKE_UPDATE_DELIVERY.md) for the delivered workflow, pages, migration and validation, and [PENDING_TASK_CLARIFICATIONS.md](PENDING_TASK_CLARIFICATIONS.md) for resolved decisions.

The authorized batch is now implemented locally: initial scans, tally-based replacement counts from existing matrix rules, request/donation recommendations, annual target, destination maps/insights, reasons/guides, physical tarpaulin issue, and client/EMS final verification. Historical source-location gaps remain explicit. The workbook itself is unchanged and its original coordinates remain invalid for Laguna.

## Historical preparation material

## Subsequently confirmed decisions

The annual distribution target is 15,000, resetting every January 1. The owner asked
for the recommended approach: monitor progress and any amount above the target;
do not automatically block releases at 15,000. Count actual stock deductions at
ready-for-pickup fulfilment, including for barangay distribution, without recounting
at collection. Remove the 50-seedling request policy cap while retaining stock checks.

Tree Origin belongs to each tree entry. Quantity, scientific name, diameter, and
height stay in the application. Remove Property Classification, Lot/Property Reference,
Purpose Details, and Condition or measurement notes; move Additional application
notes after Tree Records and make Detailed property location optional. These are
confirmed requirements, not a claim that this follow-up batch is already implemented.

## Stage instructions for review

Use a persistent instruction card on the transaction detail page after each action,
with a matching short success message. Show the completed action, next responsible
party, next task, relevant deadline only when one is actually recorded, and a link
to the appropriate section. A temporary success alert alone is insufficient.

| Situation | Proposed client instruction | Staff next step |
| --- | --- | --- |
| Draft saved | Your application is saved as a draft. Complete the required information and documents, then submit it for review. | No review queue entry until submission. |
| Application submitted | Your application has been submitted. Keep your transaction ID and check this page for RPS review updates. | RPS reviews the application and submitted documents. |
| Document replacement requested | Review the remarks beside each marked document. Upload a clear, corrected replacement for the affected requirement. | RPS reviews the replacement; retain earlier versions and remarks. |
| Online documents accepted | Your scans have been accepted. Check the original-document and fee instructions shown for your application. | Current system: RPS records original-document and fee verification separately. Retain this instruction only if that step remains required. |
| Site visit scheduled | Your site visit is scheduled for the date shown below. Prepare access to the property and contact RPS if the schedule needs attention. | RPS records site findings. The Excel tally sample is available; upload/import and replacement calculation still require the confirmed rules. |
| Application approved | Your application is approved for the next stage. Review the required replacement seedlings and donation instructions. Await permit release before proceeding with cutting. | EMS receives and verifies the required seedlings; exact calculation and recommendation rules are pending. |
| Partial donation received | Part of your donation has been recorded. Check the quantities received and remaining, then coordinate with EMS to complete the requirement. | EMS records additional receipts and any discrepancies. |
| Donation flagged | Your donation requires clarification. Read the recorded remarks and coordinate with EMS before proceeding. | EMS resolves or documents the discrepancy; no automatic clearance. |
| EMS donation verification complete | EMS has verified your donation. RPS will review donation completion before preparing your permit for release. | RPS confirms donation completion. |
| RPS donation confirmation complete | Your donation requirement has been confirmed. Check this page for permit release and pickup instructions. | RPS prepares and releases the permit. Tarpaulin handling remains pending. |
| Permit ready for pickup | Your permit is ready for pickup. Follow the location, identification, and schedule instructions shown on this page. | RPS records the physical release. |
| Permit released | Your permit has been released. Review its validity dates, approved trees, and conditions before cutting. | Proposed change: client reports cutting completion; current completion authority must be changed only after the final verification rules are confirmed. |
| Client reports cutting complete | Your completion report has been submitted. EMS will verify whether all approved trees were cut and whether other trees were affected. | Proposed EMS counter/final verification; not yet an implemented workflow. |
| EMS confirms completion | Final verification is complete. Review the recorded findings and remarks. | Proposed final closure after confirmed findings; exception handling remains pending. |
| Declined | Your application was declined. Read the reason and remarks below. Contact RPS if you need clarification. | Do not promise an appeal, resubmission, or reopening path that does not exist. |
| Expired or closed | This application is marked as expired or closed. Review its status and contact RPS for guidance on any further request. | Preserve terminal status and history; do not imply automatic extension or reactivation. |

Messages must reflect the actual persisted status. Never display donation completion,
permit release, or EMS final clearance merely because a form is filled in.

## Proposed reason dropdowns

These are suggestions for review, not official rejection criteria. Keep an additional
remarks field so staff explain the particular problem and the correction needed.
Preserve existing role permissions and append-only decision history.

### Document replacement or rejection

- Unclear or unreadable scan
- Incomplete document or missing pages
- Incorrect document uploaded
- Document details do not match the application
- Required signature or certification is missing
- Document validity needs clarification
- Corrected version required following original-document review
- Other — explanation required

Do not automatically reject a document from a selected reason alone. Record the
actual review outcome, reason, reviewer, time, and affected document version.
Accepted documents may retain positive remarks; do not hide remarks when accepted.

### Application decline

- Required documents remain incomplete
- Ownership or authorization could not be verified
- Application details could not be verified
- Site assessment did not meet the applicable requirements
- Proposed activity is outside the applicable processing scope
- Other — explanation required

RPS must explain the basis of the decision. These proposed categories must not create
new automatic eligibility rules or overwrite historical free-text reasons.

## Document-table layout

The client now sees four columns: Requirement; Attachment and scan review; Original
verification; Upload. Preserve requirement grouping/descriptions, file size/date,
download, scan status and remarks, original-review details, replacement eligibility,
private-file checks, CSRF protection, and version history. Tables scroll horizontally
on narrow screens; long descriptions/remarks wrap within bounded columns.

The RPS table retains its existing review controls. Scan and original-review remarks
are clearly labeled. Missing scans, pending reviews, rejection, and acceptance remain
distinct states; do not equate an uploaded file with verified compliance.

## Proposed fee-guide and eligibility placement

- Fee guide: reuse the existing application sidebar, adjacent to the application
  summary. Rows: application fee; inventory assessment basis if applicable; assessed
  inventory fee; total; payment instructions. Use only the confirmed configured
  schedule and the application's recorded assessment. Never invent rates, receipts,
  or a paid status. Final wording/rates await confirmation.
- Eligibility: place a concise requirements panel above the seedling request form.
  Reserve space for eligible applicants, covered locations, quantity limits if any,
  pickup rules, and the relevant office contact. All policy values await confirmation.
- Terms: show the approved terms beside the submission declaration. Proposed topics
  for review are accurate applicant/planting information, intended planting purpose,
  collection instructions, and responsibility for the planting site. Do not introduce
  a mandatory acceptance checkbox until the actual terms and any recording requirement
  are approved.

## Barangay reporting readiness

Run `C:\xampp\php\php.exe scripts/seedling_reporting_readiness.php` for a read-only,
aggregate check of the connected database. It prints no applicant identities or
free-text addresses and makes no schema/data changes. HTTP execution is disabled.

Local findings on 2026-11-11 (not production-wide conclusions):

- All 44 existing requests have planting-location text, but requests have no structured
  barangay, municipality, planting-site ID, or coordinate columns.
- Planting Sites already stores barangay/municipality/province, boundary geometry,
  center coordinates, and species-suitability information. Requests are not linked to
  these sites; name/text matching alone is insufficient evidence for assigning totals.
- Existing release movements total 558 seedlings: 463 linked to 18 collected requests,
  and 95 linked to 5 requests still ready for pickup. These are movement quantities,
  not requested or approved estimates. Stock is deducted at fulfilment; pickup is
  recorded separately. Keep these two meanings distinct in the reporting design.
- Ten active species currently have no releases in the forecast's recent 90-day
  window. Older release records exist, but a finite depletion date must not be invented
  from an empty recent window.

Proposed implementation plan:

1. Capture a structured planting barangay and municipality for future requests, plus
   an optional verified planting-site reference. Assess schema changes separately.
2. Keep historical requests unmatched until their location is verified. Show an
   explicit Unassigned total instead of silently distributing them among barangays.
3. Aggregate the actual negative `released` movement quantities by request/location;
   separately identify collected versus ready-for-pickup requests. Leave movements
   without a request/location outside attributed barangay totals.
4. Apply date bounds to actual stock-release movement dates, as confirmed by the owner.
   Show January–December progress against the 15,000 annual monitoring target without
   double-counting subsequent pickup. Offer municipality, barangay, species,
   and date filters where source fields are verified.
5. Use a verified barangay point or boundary to render the map. Counts belong to
   planting destinations, not automatically the requester's home address. Reuse the
   existing Laguna boundary and map utilities. Do not create guessed centroid pins.
6. Show a numeric legend with explicit ranges and a no-data state, keeping zero
   releases distinct from missing/unassigned location data. Staff reporting must
   respect existing role boundaries and the chosen personal-visibility behavior.

No barangay totals or map layers have been fabricated or published in this batch.

## Analytics reuse and proposed insights

Reuse `includes/analytics.php` and the existing CENRO/EMS report views rather than
introduce another forecast engine. Existing code already supplies submission trends,
status distributions, stock snapshots, release trends, depletion estimates, and
rule-based recommendations. Dates and CSV exports remain shared.

| Proposed insight | Source and calculation | Presentation / limit |
| --- | --- | --- |
| Barangay distribution | Actual release movements plus verified request destination | Top barangays, map, filtered counts; keep unassigned separately. Blocked by structured historical location coverage. |
| Collected versus awaiting pickup | Request-linked releases separated by claimed/ready status | Show movement quantities and pickup counts separately; do not present reserved stock as confirmed collection. |
| Review workload | Existing submission/stage/status aggregates | Show counts and date coverage; do not invent processing-time targets. |
| Low or empty stock | Current available quantity against stored low-stock threshold | Explain which species need attention; stock snapshots remain current balances. |
| Depletion estimate | Existing 90-day release-rate calculation | Display an estimate only with a nonzero rate. Otherwise show insufficient recent release history. |
| Request/donation suggestions | Existing stock and site suitability data | Candidate inputs only; selection/ranking awaits clarification of "highest depletion date" and donation priorities. |

## Implemented in this independent batch

- Client document cards reorganized into a table, preserving upload/download controls,
  review details, and original-document information.
- Existing review remarks made clearer in RPS and client views, including scan
  replacement history.
- Permit wizard field errors gain inline messages, accessible error links, and section
  navigation; save-draft behavior and server validation remain in place.
- Shared coordinate pickers inherit the bundled Laguna boundary and validate typed
  coordinate pairs/out-of-boundary values before submission. Server polygon checks
  remain the final validation.
- Read-only reporting readiness script and this review pack are provided. No new
  eligibility, fee, donation-calculation, decline, or completion rules are enabled.

## Validation and local limitation

- PHP lint passed for both modified permit pages and the new CLI script; both
  JavaScript files passed Node syntax checks.
- Browser checks passed for inline errors, error links, server-error section focus,
  invalid drafts not being saved, coordinate-pair/Laguna validation, inherited map
  bounds, document table structure, mobile page width, upload CSRF denial, anonymous
  redirects, and denial of another client's application. No application records were
  created by these checks.
- Existing map checks passed across Community, RPS, and EMS; the PHP map-scope probe
  passed 28 checks. Existing analytics math/forecast checks passed 37 checks.
- An existing authorized scan download returned the current "document is unavailable"
  response: its private file cannot be resolved in this local checkout. The document
  record, download endpoint, and file-access rules were not changed. That existing
  file could not be used to validate a successful download; restoring deployed private
  files is a separate environment/data task.
