# Sample Excel Tally Reference

Reviewed: 2026-11-11; implementation updated: 2026-11-12
Source: `filesresources/TALLY SHEET.xlsx`, supplied by the project owner.
The supplied workbook remains unchanged. Import is implemented in `includes/permit_tally.php`; see [INTAKE_UPDATE_DELIVERY.md](INTAKE_UPDATE_DELIVERY.md). Its original coordinates are outside Laguna, so it is a structural template rather than an uploadable local-location example.

## Verified workbook structure

- One worksheet, named `create a sheet for this too`.
- Excel table `Table1`, range `A1:K52`: header row 1, 50 tree rows 2–51,
  and a subtotal row at 52. No merged cells.
- Each populated data row represents an individual numbered tree; there is no
  separate quantity column. All 50 tree numbers are distinct in this sample.

| Column | Exact heading | Interpretation for import planning |
| --- | --- | --- |
| A | Tree No. | Tree identifier within this tally; validate duplicates. |
| B | Species | Preserve the supplied species name; do not silently rename it. |
| C | DBH/DAB (cm) | Diameter measurement in centimetres; preserve the stated heading. |
| D | MH (m) | Height field in metres; retain MH without assuming an approved expanded definition. |
| E | TH (m) | Height field in metres; retain TH without assuming an approved expanded definition. |
| F | Volume (m3) | Numeric volume entered for each tree. |
| G | Tree Location: Northing | Sample values are degree-minute-second strings, not projected metre-based northings. |
| H | Tree Location: Easting | Sample values are degree-minute-second strings, not projected metre-based eastings. |
| I | Tree Category | Sample vocabulary: `PLANTED`, `NATURAL`. |
| J | Stem Quality | All sample values are 1; the allowed scale is not established by this file. |
| K | Remarks | All sample values are `for cutting`; other action values/filter rules are not established. |

## Verified sample totals

- 50 tree records: 45 Planted and 5 Natural.
- Species: 40 L.L. Mahogany, 8 Mango, 1 Gmelina, 1 Rubber.
- All 5 Natural trees are L.L. Mahogany; the other 35 Mahogany records are Planted.
- Sum of entered tree volumes: 13.10 m3.
- The workbook's only formula is the volume subtotal in F52:
  `SUM(Table1[Volume (m3)])`. Its cached value is 13.1, matching the entered rows.
- There is no replacement-seedling quantity, ratio, or calculation formula in the
  workbook. Tree volume and tree count must not be treated as replacement quantity.

## Implemented import rules

1. Match the table/header names rather than requiring the sample worksheet name.
   Read data rows separately from headers, subtotal/footer rows, and blank formatting.
2. A row is one candidate tree record. The persisted summary shows counts and the first 50 validated rows. Subtotals do not count; duplicate tree numbers are rejected. Only rows whose normalized remarks are exactly “for cutting” contribute to cutting/replacement totals; other actions are preserved as excluded.
3. Preserve individual rows, source row numbers, uploaded-file version, reviewer,
   and timestamps. Do not overwrite the client's original tree declarations silently.
4. Validate measurements and required values. Flag unknown categories, duplicate IDs,
   malformed coordinates, and unknown remarks instead of guessing their meaning.
5. The sample coordinate strings are around 7 degrees north and 122 degrees east,
   outside Laguna. Treat this file as a structural example, not valid Laguna location
   evidence. Future imports need explicit coordinate-format support and server-side
   polygon validation. Do not relax the province restriction to accept the sample.
6. Do not evaluate workbook formulas as executable expressions. Recalculate aggregates
   from validated numeric tree rows and handle formula-based inputs explicitly.
7. Reuse the existing permit matrix: Natural 1:100, Planted 1:50, NGA earth-balling 1:0. Unsupported category/origin combinations are rejected. The sample’s 45 Planted and 5 Natural cutting rows yield 2,750 replacements under the NGA cutting rules; volume does not determine replacements. Historical version 1 approval policies remain unchanged.
8. Keep source tree species separate from suggested donation species. The sample
   identifies inventoried trees, not which seedlings the client should donate.

## Clarification progress

The template and column structure are implemented with the existing matrix ratios and the row rules above. Request recommendations use the owner-confirmed latest estimated run-out date. Final EMS findings remain open for follow-up until resolved. Current decisions and optional future additions are recorded in `PENDING_TASK_CLARIFICATIONS.md`.
