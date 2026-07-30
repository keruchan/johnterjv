<?php
/**
 * CENRO permit matrix: the single source of truth for how an applicant's
 * classification determines the permit type, purpose options, statutory fees,
 * seedling replacement obligation, and requirement checklist.
 *
 * Sourced from the office's PERMIT MATRIX and CHECKLIST OF REQUIREMENTS
 * workbooks. Keep this file aligned with those documents; every downstream
 * screen derives its behaviour from here rather than hardcoding a list.
 */

const PERMIT_FEE_CERTIFICATION = 50.00;
const PERMIT_FEE_OATH = 36.00;
const PERMIT_FEE_INVENTORY_PER_HECTARE = 1200.00;

/** Area at or above which a utilization plan becomes mandatory (private lands). */
const PERMIT_UTILIZATION_PLAN_HECTARES = 10.0;

function permit_matrix_categories(): array
{
    return [
        'nga' => [
            'label' => 'National Government Agency',
            'form_code' => 'RO-F-05',
            'permit_code' => 'STCEBP',
            'permit_title' => 'Special/Tree Cutting and/or Earth Balling Permit for National Government Agencies',
            'classification' => 'Simple',
            'transaction_types' => ['G2G (Government-to-Government)'],
            'subtype_label' => 'National Government Agency',
            'subtypes' => [
                'Department of Public Works and Highways (DPWH)',
                'Department of Transportation (DOTr)',
                'Department of Education (DepEd)',
                'Department of Agriculture (DA)',
                'Department of Health (DOH)',
                'Commission on Higher Education (CHED)',
                'Department of Energy (DOE)',
                'National Irrigation Administration (NIA)',
            ],
            'purpose_group' => 'National Infrastructure Projects',
            'purposes' => [
                'Road Right of Way and Widening',
                'Power Line Corridor Clearing and Substations',
                'Telecommunication Tower and Fiber Installation',
                'Public School and Government Building Construction',
                'Flood Control and Irrigation Development',
            ],
            'inventory_fee_applies' => false,
            'balling_replacement' => '1:0 Replacement',
        ],
        'public_places' => [
            'label' => 'Public Places',
            'form_code' => 'RO-F-06',
            'permit_code' => 'TCP-P',
            'permit_title' => 'Tree Cutting Permit within Public Places',
            'classification' => 'Highly Technical',
            'transaction_types' => ['G2C (Government-to-Citizen)', 'G2B (Government-to-Business)'],
            'subtype_label' => 'Applicant type',
            'subtypes' => [
                'Provincial LGU',
                'City LGU',
                'Municipal LGU',
                'Barangay LGU',
                'School (Public/Private)',
                "Homeowners' Association (HOA)",
                'Individual Filipino Citizen',
                'Other Organized Group / Private Entity',
            ],
            'purpose_group' => 'Public Space Safety & Maintenance',
            'purposes' => [
                'Removal of Hazardous Trees Threatening Public Safety',
                'Trimming Pruning and Maintenance of Public Spaces',
                'Subdivision Access Road Opening',
            ],
            'inventory_fee_applies' => true,
            'balling_replacement' => null,
        ],
        'private_lands' => [
            'label' => 'Private Lands',
            'form_code' => 'RO-F-07',
            'permit_code' => 'S/PLTP',
            'permit_title' => 'Private Land Timber Permit or Special Private Land Timber Permit within Private/Titled Lands',
            'classification' => 'Highly Technical (Multi-Stage Processing)',
            'transaction_types' => ['G2C (Government-to-Citizen)', 'G2B (Government-to-Business)'],
            'subtype_label' => 'Applicant type',
            'subtypes' => [
                'Private Land Owner',
            ],
            'purpose_group' => 'Private Land Development',
            'purposes' => [
                'Private Residential Construction and Subdivisions',
                'Commercial and Industrial Site Establishment',
                'Agricultural Land Clearing and Farming',
                'Inter-cropping and Agricultural Transition',
                'Boundary Line Clearing and Fencing',
            ],
            'inventory_fee_applies' => true,
            'balling_replacement' => null,
        ],
        'forest_lands' => [
            'label' => 'Forest Lands',
            'form_code' => 'RO-F-08',
            'permit_code' => 'S/TCP-F',
            'permit_title' => 'Tree Cutting Permit or Special Tree Cutting Permit within Forest Lands',
            'classification' => 'Highly Technical (Multi-Stage Processing)',
            'transaction_types' => ['G2C (Government-to-Citizen)', 'G2B (Government-to-Business)'],
            'subtype_label' => 'Tenure instrument held by applicant',
            'subtypes' => [
                'Community-Based Forest Mgmt. Agreement (CBFMA)',
                'Industrial Forest Management Agreement (IFMA)',
                'Special Land Use Permit (SLUP)',
                'Forest Land Use Agreement (FLAG)',
                'Forest Land Use Agreement for Tourism (FLAGT)',
                'Certificate of Ancestral Domain Title (CADT)',
                'Mineral Production Sharing Agreement (MPSA)',
                'Financial or Technical Assistance Agreement (FTAA)',
            ],
            'purpose_group' => 'Forestry & Resource Operations',
            'purposes' => [
                'Timber Harvesting and Tenure Area Development',
                'Community-Based Livelihood Harvesting',
                'Special Land Use Permit Site Clearing',
                'Mining, Quarrying, and Mineral Land Development',
            ],
            'inventory_fee_applies' => true,
            'balling_replacement' => null,
        ],
    ];
}

function permit_matrix_category(?string $categoryKey): ?array
{
    if ($categoryKey === null || $categoryKey === '') {
        return null;
    }

    return permit_matrix_categories()[$categoryKey] ?? null;
}

function permit_matrix_category_keys(): array
{
    return array_keys(permit_matrix_categories());
}

function permit_matrix_category_label(?string $categoryKey): string
{
    return permit_matrix_category($categoryKey)['label'] ?? 'Unclassified';
}

/** True when the subtype is one of the category's published options. */
function permit_matrix_subtype_is_valid(string $categoryKey, string $subtype): bool
{
    $category = permit_matrix_category($categoryKey);

    return $category !== null && in_array($subtype, $category['subtypes'], true);
}

function permit_matrix_purpose_is_valid(string $categoryKey, string $purpose): bool
{
    $category = permit_matrix_category($categoryKey);

    return $category !== null && in_array($purpose, $category['purposes'], true);
}

// ---------------------------------------------------------------------------
// Seedling replacement obligation
// ---------------------------------------------------------------------------

function permit_matrix_tree_origins(): array
{
    return [
        'natural' => 'Naturally growing',
        'planted' => 'Planted',
        'balling' => 'Earth-balling (relocation)',
    ];
}

/**
 * Replacement obligation text for a category and tree origin. Earth-balling is
 * only recognised for NGA permits; every other category falls back to N/A.
 */
function permit_matrix_replacement_rule(string $categoryKey, string $treeOrigin): ?string
{
    $category = permit_matrix_category($categoryKey);
    if ($category === null) {
        return null;
    }

    return match ($treeOrigin) {
        'natural' => '1:100 Replacement (Strictly Indigenous Species)',
        'planted' => '1:50 Replacement (Preferably Indigenous Species)',
        'balling' => $category['balling_replacement'],
        default => null,
    };
}

// ---------------------------------------------------------------------------
// Statutory fees
// ---------------------------------------------------------------------------

/**
 * Assessed fees for an application. Inventory fee is charged per hectare and
 * does not apply to NGA permits.
 */
function permit_matrix_assess_fees(string $categoryKey, ?float $areaHectares): array
{
    $category = permit_matrix_category($categoryKey);
    $inventoryApplies = $category !== null && $category['inventory_fee_applies'];

    $inventory = 0.00;
    if ($inventoryApplies && $areaHectares !== null && $areaHectares > 0) {
        $inventory = round(PERMIT_FEE_INVENTORY_PER_HECTARE * $areaHectares, 2);
    }

    return [
        'certification_fee' => PERMIT_FEE_CERTIFICATION,
        'oath_fee' => PERMIT_FEE_OATH,
        'inventory_fee' => $inventory,
        'inventory_applies' => $inventoryApplies,
        'total_fee' => round(PERMIT_FEE_CERTIFICATION + PERMIT_FEE_OATH + $inventory, 2),
    ];
}

function permit_matrix_format_peso(float $amount): string
{
    return 'P' . number_format($amount, 2);
}

// ---------------------------------------------------------------------------
// Requirement checklist
// ---------------------------------------------------------------------------

/**
 * Self-declared situational questions. A "yes" answer switches on the
 * conditional requirements that name the same key in `requires_condition`.
 * Keys are stored on the application as a JSON map.
 */
function permit_matrix_condition_questions(): array
{
    return [
        'within_ancestral_domain' => [
            'label' => 'Does the project fall within an ancestral domain?',
            'categories' => ['nga'],
        ],
        'affects_titled_private_property' => [
            'label' => 'Does the government project affect a titled private property?',
            'categories' => ['nga'],
        ],
        'within_protected_area' => [
            'label' => 'Is the project located within a Protected Area?',
            'categories' => ['nga'],
        ],
        'within_subdivision' => [
            'label' => 'Are the trees located within a subdivision?',
            'categories' => ['public_places'],
        ],
        'school_or_organized_group' => [
            'label' => 'Is a school or an organized group applying?',
            'categories' => ['public_places', 'private_lands'],
        ],
        'covered_by_cloa' => [
            'label' => 'Is the land covered by a CLOA?',
            'categories' => ['private_lands'],
        ],
        'not_registered_owner' => [
            'label' => 'Is the applicant someone other than the registered owner of the property?',
            'categories' => ['private_lands'],
        ],
    ];
}

/** The situational questions that apply to one category, in display order. */
function permit_matrix_condition_questions_for(string $categoryKey): array
{
    $questions = [];
    foreach (permit_matrix_condition_questions() as $key => $question) {
        if (in_array($categoryKey, $question['categories'], true)) {
            $questions[$key] = $question['label'];
        }
    }

    return $questions;
}

/**
 * Full checklist per category. Every entry is an upload slot; nothing here is
 * satisfied by anything other than a submitted file.
 *
 * `group` is one of mandatory | conditional | representative.
 * `requires_condition` names a condition question key (conditional group).
 * `requires_area_at_least` triggers on the declared area instead of a question.
 */
function permit_matrix_requirements(): array
{
    $applicationLetter = [
        'label' => 'Application Letter',
        'copies' => '1 original',
        'group' => 'mandatory',
    ];
    $spa = [
        'label' => 'Special Power of Attorney (SPA)',
        'copies' => '1 original',
        'group' => 'representative',
    ];
    $eccCnc = [
        'label' => 'Environmental Compliance Certificate (ECC) or Certificate of Non-Coverage (CNC)',
        'copies' => '1 certified true copy',
        'group' => 'mandatory',
    ];

    return [
        'nga' => [
            'application_letter' => $applicationLetter,
            'lgu_endorsement' => [
                'label' => 'LGU Endorsement / Certification of No Objection',
                'copies' => '1 original',
                'group' => 'mandatory',
            ],
            'site_development_plan' => [
                'label' => 'Approved Site Development Plan / Infrastructure Plan with tree charting',
                'copies' => '1 certified true copy',
                'group' => 'mandatory',
            ],
            'ecc_cnc' => $eccCnc,
            'ncip_clearance' => [
                'label' => 'NCIP Clearance (FPIC / CP / CNO, whichever is applicable)',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_condition' => 'within_ancestral_domain',
                'condition_note' => 'Required when the project falls within an ancestral domain.',
            ],
            'owner_waiver' => [
                'label' => 'Waiver / Consent of owner(s)',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_condition' => 'affects_titled_private_property',
                'condition_note' => 'Required when the government project affects a titled private property.',
            ],
            'pamb_clearance' => [
                'label' => 'PAMB Clearance / Resolution',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_condition' => 'within_protected_area',
                'condition_note' => 'Required when the project is located within a Protected Area.',
            ],
            'agency_authorization' => [
                'label' => 'Official Agency Authorization or Designation Letter',
                'copies' => '1 original',
                'group' => 'representative',
            ],
        ],

        'public_places' => [
            'application_letter' => $applicationLetter,
            'lgu_endorsement' => [
                'label' => 'LGU Endorsement / Certification of No Objection / Resolution',
                'copies' => '1 original',
                'group' => 'mandatory',
            ],
            'hoa_resolution' => [
                'label' => "Homeowners' Association (HOA) Resolution",
                'copies' => '1 original or 1 certified true copy',
                'group' => 'conditional',
                'requires_condition' => 'within_subdivision',
                'condition_note' => 'Required when the trees are located within a subdivision.',
            ],
            'pta_resolution' => [
                'label' => 'PTA Resolution or Organizational Resolution stating the reason for cutting',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_condition' => 'school_or_organized_group',
                'condition_note' => 'Required when a school or an organized group is applying.',
            ],
            'spa' => $spa,
        ],

        'private_lands' => [
            'application_letter' => $applicationLetter,
            'lgu_endorsement' => [
                'label' => 'LGU Endorsement / Certification of No Objection',
                'copies' => '1 original',
                'group' => 'mandatory',
            ],
            'land_title' => [
                'label' => 'Authenticated copy of Land Title / CLOA issued by the LRA or Registry of Deeds',
                'copies' => '1 copy',
                'group' => 'mandatory',
            ],
            'sketch_map' => [
                'label' => 'Sketch map of the area applied for',
                'copies' => '1 original',
                'group' => 'mandatory',
            ],
            'ecc_cnc' => $eccCnc,
            'utilization_plan' => [
                'label' => 'Utilization Plan',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_area_at_least' => PERMIT_UTILIZATION_PLAN_HECTARES,
                'condition_note' => 'Required when the application covers ten (10) hectares or larger.',
            ],
            'agrarian_endorsement' => [
                'label' => 'Endorsement by the local agrarian reform officer interposing No Objection',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_condition' => 'covered_by_cloa',
                'condition_note' => 'Required when the land is covered by a CLOA.',
            ],
            'pta_resolution' => [
                'label' => 'PTA Resolution or Organizational Resolution of No Objection and Reason for Cutting',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_condition' => 'school_or_organized_group',
                'condition_note' => 'Required when a school or organization is applying.',
            ],
            'deed_of_conveyance' => [
                'label' => 'Deed of Conveyance',
                'copies' => '1 original',
                'group' => 'conditional',
                'requires_condition' => 'not_registered_owner',
                'condition_note' => 'Required when the applicant is not the registered owner of the property.',
            ],
            'spa' => $spa,
        ],

        'forest_lands' => [
            'application_letter' => $applicationLetter,
            'lgu_endorsement' => [
                'label' => 'LGU Endorsement / Certification of No Objection',
                'copies' => '1 original',
                'group' => 'mandatory',
            ],
            'tenure_instrument' => [
                'label' => 'Approved land tenure instrument / management agreement (CBFMA, IFMA, SLUP, FLAG, FLAGT, CADT, MPSA, FTAA) together with its approved development/management plan',
                'copies' => '1 copy',
                'group' => 'mandatory',
            ],
            'ecc_cnc' => [
                'label' => 'Environmental Compliance Certificate (ECC) or Certificate of Non-Coverage (CNC)',
                'copies' => '1 certified copy',
                'group' => 'mandatory',
            ],
            'spa_or_board_resolution' => [
                'label' => "Special Power of Attorney (SPA) or Organizational Board Resolution / Secretary's Certificate",
                'copies' => '1 original',
                'group' => 'representative',
            ],
        ],
    ];
}

/** Every requirement defined for a category, regardless of whether it applies. */
function permit_matrix_requirements_for(string $categoryKey): array
{
    return permit_matrix_requirements()[$categoryKey] ?? [];
}

function permit_matrix_requirement(string $categoryKey, string $requirementKey): ?array
{
    return permit_matrix_requirements_for($categoryKey)[$requirementKey] ?? null;
}

/**
 * The requirements an application must actually satisfy, resolved against the
 * applicant's self-declared conditions, declared area, and whether a
 * representative is filing. Each entry gains an `is_required` flag.
 */
function permit_matrix_resolved_requirements(
    string $categoryKey,
    array $conditionAnswers = [],
    ?float $areaHectares = null,
    bool $filedByRepresentative = false
): array {
    $resolved = [];
    foreach (permit_matrix_requirements_for($categoryKey) as $key => $definition) {
        $group = $definition['group'];

        if ($group === 'mandatory') {
            $definition['is_required'] = true;
        } elseif ($group === 'representative') {
            if (!$filedByRepresentative) {
                continue;
            }
            $definition['is_required'] = true;
        } else {
            $areaThreshold = $definition['requires_area_at_least'] ?? null;
            if ($areaThreshold !== null) {
                if ($areaHectares === null || $areaHectares < $areaThreshold) {
                    continue;
                }
            } else {
                $conditionKey = $definition['requires_condition'] ?? null;
                if ($conditionKey === null || empty($conditionAnswers[$conditionKey])) {
                    continue;
                }
            }
            $definition['is_required'] = true;
        }

        $resolved[$key] = $definition;
    }

    return $resolved;
}

/** Normalizes posted condition checkboxes into a clean boolean map. */
function permit_matrix_normalize_condition_answers(string $categoryKey, array $input): array
{
    $answers = [];
    foreach (array_keys(permit_matrix_condition_questions_for($categoryKey)) as $key) {
        $value = strtolower(trim((string) ($input[$key] ?? '')));
        $answers[$key] = in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    return $answers;
}

function permit_matrix_decode_condition_answers(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);

    return is_array($decoded) ? array_map(static fn ($v): bool => (bool) $v, $decoded) : [];
}
