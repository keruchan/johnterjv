# Laguna map boundary

`laguna.geojson` is the Laguna feature extracted from geoBoundaries gbOpen,
PHL ADM2, simplified geometry at commit `41af8f1` (boundary year: 2020).

- Source data: NAMRIA, Philippine Statistics Authority, OCHA Philippines.
- Dataset: https://www.geoboundaries.org/api/current/gbOpen/PHL/ADM2/
- Download: https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/41af8f1/releaseData/gbOpen/PHL/ADM2/geoBoundaries-PHL-ADM2_simplified.geojson
- License: CC BY 3.0 IGO, https://creativecommons.org/licenses/by/3.0/igo/

The feature geometry is unchanged. Source and license metadata were added to
its properties. This is a province-level map selection boundary, not a cadastral
survey. Simplification can affect locations immediately along the border.
Permit maps and PHP validation use the same bundled geometry without making a
runtime request to a boundary or reverse-geocoding service.


## Laguna address dropdown snapshot (2026-11-12)

`laguna-locations.json` bundles 30 city/municipality records and 681 barangays, with PSGC correspondence codes and names retrieved from the public PSGC API mirror at https://psgc.gitlab.io/api/provinces/043400000/cities-municipalities/ and its per-town barangay endpoints. Totals and city/municipality names were cross-checked against the official PSA Laguna listing: https://psa.gov.ph/classification/psgc/citimuni/0403400000 (30 LGUs, 681 barangays). This is a local name/code snapshot, not a claim that the mirror is PSA's authenticated API or the latest official full publication. Runtime forms do not call the API.

District labels group the province's four numbered districts and the separate city districts of Biñan, Calamba and Santa Rosa. They support address selection and do not change CENRO jurisdiction or establish cadastral boundaries. Barangay names and province-level geometry do not establish an exact barangay point; destination/provider coordinates are recorded separately.

Existing spelling aliases such as Sta. Cruz and Los Banos resolve to the bundled names. Ambiguous Poblacion/Bukal entries must be selected explicitly; there is no fuzzy assignment of a historical address.
