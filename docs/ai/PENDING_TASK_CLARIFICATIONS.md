# Permit and Seedling Decisions

Updated: 2026-11-12
Status: The authorized intake/workflow batch is implemented locally. No answer is required to finish this batch.
Delivery and validation: [INTAKE_UPDATE_DELIVERY.md](INTAKE_UPDATE_DELIVERY.md).

## Confirmed owner answers

- Annual distribution monitoring target: 15,000, resetting every January 1. The owner accepted the recommendation to track progress and excess without blocking releases.
- Count at ready-for-pickup / actual stock deduction, including barangay distribution. Do not count again at collection.
- Remove the 50-seedling request limit.
- Tree Origin belongs to each tree entry.
- Quantity, scientific name, diameter and height stay in the application. This supersedes the earlier “Name only” wording.
- Remove Property Classification, Lot/Property Reference, Purpose Details, and Condition or measurement notes.
- Detailed property location is optional. Additional application notes follow Tree Records.
- “Highest depletion date” means the latest estimated run-out date. Unknown estimates must not become invented dates.
- EMS final findings involving uncut approved trees or additional affected trees stay open for follow-up; resolve findings before closure.

## Applied implementation decisions using existing project rules

- Initial documents use the existing category/conditional requirement matrix. Original hardcopy, wet-ink, and fee verification remain distinct from scan acceptance.
- Replacement calculations use the existing matrix: Natural 1:100, Planted 1:50, NGA earth-balling 1:0. The supplied workbook has no replacement formula. Each validated individual row marked “for cutting” contributes according to its category; other rows remain preserved as excluded.
- Site-visit XLSX uploads preserve source versions/rows, validate Laguna coordinates and measurements, reject unsafe content, and keep client-declared tree records separate.
- Requests rank the latest finite depletion estimate first. Donations prioritize confirmed indigenous species and stock/depletion needs. Natural-tree requirements show only confirmed indigenous suggestions. EMS maintains classification; no species was auto-classified.
- The fees sidebar uses existing configured rates. Eligibility/terms guidance states actual account, accurate-information, review, stock, pickup and cancellation rules without introducing an unapproved restriction.
- Field-completion checks/crosses describe completed inputs; staff verification remains separately labeled.
- Reason dropdowns use the prepared practical categories plus remarks/Other explanation. They do not automatically decline applications.
- Physical permit release remains the established workflow. Tarpaulin issue is recorded as a physical issue date, not generated artwork.
- The client submits completion details, then EMS verifies both required questions with a description. Adverse findings remain open; subsequent clean findings or explicit resolution of earlier findings permit closure.
- Barangay reporting uses actual release movements and new structured destination/site snapshots. Historical free-text locations remain unassigned. No coordinates or barangay assignments were guessed.
- Existing application progress, stock/depletion analytics, and new destination/coverage insights are reused.

## Subsequent owner clarification and completion

The owner confirmed that all current records are dummy data and both permit/request forms are limited to Laguna. All nine remaining items are now implemented or populated as explicit sample fixtures; see [REVISION_COMPLETION.md](REVISION_COMPLETION.md). The provider directory and EMS maintenance controls exist; synthetic destinations, five/five species classifications and ten recent sample releases now populate the local demonstration. These facts supersede the earlier data-blocking statements.

## Optional future scope requiring additional information

These do not block the completed final task list:

1. Real provider names, contact details and surveyed locations would replace the clearly named demonstration providers for operational use. EMS can maintain provider records.
2. If the office wants revised official fee schedules, document checklists, eligibility terms or replacement policies, supply the approved values. The current implementation reuses configured rules.
3. A generated/uploaded tarpaulin design and mandatory final-verification photos would need explicit content/policy requirements. Current delivery records physical issue and required written findings.
4. Verified real destinations, classifications and release history would replace the sample fixtures when real operational records are introduced. Staff correction/classification controls are present.

Authentication, role permissions, record ownership, private files, status/audit history, historical policy snapshots, and personal archive/delete behavior remain protected.
