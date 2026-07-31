<?php
/**
 * ============================================================
 * File     : config/seed_demo_permits.php
 * Project  : CERTREEFY
 * Purpose  : Seeds submitted Tree Cutting Permit applications whose scans are
 *            all still UNVERIFIED, so the one-click RPS document review and the
 *            one-click original-documents-and-fees confirmation can be tested
 *            against realistic data.
 *
 * The applications themselves are created through the normal service layer
 * (submit_permit_application), so transaction IDs, status history, and audit
 * rows are produced exactly as a real submission would produce them.
 *
 * Document rows are written directly because upload_permit_document() relies on
 * is_uploaded_file()/move_uploaded_file(), which only work inside a real HTTP
 * upload. This script mirrors that function's insert and file layout.
 *
 * Usage (CLI only):
 *   php config/seed_demo_permits.php
 *
 * Re-runnable: each run creates a fresh batch. Delete this file before
 * deploying to production.
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This seeding utility may only be run from the command line.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/permit.php';
require_once __DIR__ . '/../includes/permit_documents.php';
require_once __DIR__ . '/../includes/permit_matrix.php';

/**
 * Each scenario deliberately resolves to a different requirement checklist so
 * the reviewer sees mandatory, conditional, and representative documents.
 */
$scenarios = [
    [
        'category' => 'private_lands',
        'subtype' => 'Private Land Owner',
        'purpose' => 'Agricultural Land Clearing and Farming',
        'tree_origin' => 'natural',
        'area' => 12.5,
        'relationship' => 'owner',
        'conditions' => ['covered_by_cloa' => '1'],
        'property' => ['Barangay Bagumbayan farm lot', 'Bagumbayan', 'Sta. Cruz', '4', 'Lot 221-B'],
        'trees' => [['Narra', 'Pterocarpus indicus', 6, 42.5, 12.0]],
        'note' => 'Expansion of an existing rice and vegetable farm.',
    ],
    [
        'category' => 'public_places',
        'subtype' => "Homeowners' Association (HOA)",
        'purpose' => 'Removal of Hazardous Trees Threatening Public Safety',
        'tree_origin' => 'planted',
        'area' => 0.75,
        'relationship' => 'owner',
        'conditions' => ['within_subdivision' => '1'],
        'property' => ['Villa Esperanza Subdivision, Phase 2', 'Poblacion', 'Pila', '4', 'Blk 7 Lot 12'],
        'trees' => [['Acacia', 'Samanea saman', 3, 88.0, 18.5]],
        'note' => 'Two acacia trees are leaning over the village access road.',
    ],
    [
        'category' => 'forest_lands',
        'subtype' => 'Community-Based Forest Mgmt. Agreement (CBFMA)',
        'purpose' => 'Community-Based Livelihood Harvesting',
        'tree_origin' => 'planted',
        'area' => 30.0,
        'relationship' => 'authorized_representative',
        'conditions' => [],
        'property' => ['CBFMA area, upland sitio', 'Santiago', 'Victoria', '3', 'CBFMA-2019-014'],
        'trees' => [['Gmelina', 'Gmelina arborea', 40, 35.0, 14.0]],
        'note' => 'Scheduled thinning under the approved CBFMA management plan.',
    ],
    [
        'category' => 'nga',
        'subtype' => 'Department of Public Works and Highways (DPWH)',
        'purpose' => 'Road Right of Way and Widening',
        'tree_origin' => 'balling',
        'area' => null,
        'relationship' => 'owner',
        'conditions' => ['within_protected_area' => '1', 'within_ancestral_domain' => '1'],
        'property' => ['National road widening, Km 82', 'San Antonio', 'Kalayaan', '4', 'RROW Sta. 82+400'],
        'trees' => [['Mahogany', 'Swietenia macrophylla', 15, 55.0, 16.0]],
        'note' => 'Earth-balling and relocation along the road right of way.',
    ],
    [
        'category' => 'private_lands',
        'subtype' => 'Private Land Owner',
        'purpose' => 'Private Residential Construction and Subdivisions',
        'tree_origin' => 'planted',
        'area' => 2.0,
        'relationship' => 'owner',
        'conditions' => [],
        'property' => ['Residential lot along Barangay road', 'Malinao', 'Nagcarlan', '3', 'Lot 45-A'],
        'trees' => [['Mango', 'Mangifera indica', 4, 60.0, 11.0]],
        'note' => 'Clearing for a single-detached family residence.',
    ],
];

/** Minimal, genuinely valid one-page PDF so the RPS download link works. */
function seed_demo_pdf_bytes(string $title): string
{
    $text = str_replace(['(', ')', '\\'], '', $title);
    $stream = "BT /F1 12 Tf 60 760 Td (CERTREEFY sample document) Tj "
        . "0 -22 Td (" . $text . ") Tj "
        . "0 -22 Td (Seeded test scan - not a real document.) Tj ET";
    $objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[$index] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefPosition = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n"
        . $xrefPosition . "\n%%EOF\n";

    return $pdf;
}

try {
    // Reuse existing active client accounts so no new logins are invented.
    $applicantStmt = $pdo->query(
        "SELECT id, username, fname, lname, contact, address
         FROM tbl_users
         WHERE role = 'community' AND status = 'active'
         ORDER BY id ASC
         LIMIT " . count($scenarios)
    );
    $applicants = $applicantStmt->fetchAll();
    if (count($applicants) < count($scenarios)) {
        exit('Not enough active client accounts to seed ' . count($scenarios) . " applications.\n");
    }

    $classify = $pdo->prepare(
        'UPDATE tbl_users
         SET applicant_category = :category, applicant_subtype = :subtype
         WHERE id = :id AND role = \'community\''
    );
    $insertDocument = $pdo->prepare(
        'INSERT INTO tbl_permit_documents
            (application_id, document_type, storage_path, original_filename,
             mime_type, file_size_bytes, uploaded_by_user_id,
             replaces_document_id, is_current, verification_status)
         VALUES
            (:application_id, :document_type, :storage_path, :original_filename,
             :mime_type, :file_size_bytes, :uploaded_by_user_id,
             NULL, 1, \'pending\')'
    );

    $created = 0;
    foreach ($scenarios as $index => $scenario) {
        $applicant = $applicants[$index];
        $userId = (int) $applicant['id'];
        $category = permit_matrix_category($scenario['category']);

        // The permit type is taken from the account, so classify first.
        $classify->execute([
            ':category' => $scenario['category'],
            ':subtype' => $scenario['subtype'],
            ':id' => $userId,
        ]);

        [$address, $barangay, $municipality, $district, $lot] = $scenario['property'];
        $applicationInput = [
            'applicant_type' => 'individual',
            'property_relationship' => $scenario['relationship'],
            'authorization_details' => $scenario['relationship'] === 'authorized_representative'
                ? 'Authorized under a notarized Special Power of Attorney dated ' . date('F j, Y', strtotime('-2 months')) . '.'
                : '',
            'property_classification' => $scenario['category'] === 'private_lands'
                ? 'private_property'
                : 'public_domain',
            'property_owner_name' => trim((string) $applicant['fname'] . ' ' . (string) $applicant['lname']),
            'property_address' => $address,
            'lot_number' => $lot,
            'district' => $district,
            'barangay' => $barangay,
            'municipality' => $municipality,
            'province' => 'Laguna',
            'cutting_purpose' => $scenario['note'],
            'purpose_option' => $scenario['purpose'],
            'tree_origin' => $scenario['tree_origin'],
            'area_hectares' => $scenario['area'] === null ? '' : (string) $scenario['area'],
            'application_notes' => 'Seeded sample application for one-click verification testing.',
            'declaration_confirmed' => '1',
        ] + $scenario['conditions'];

        $trees = [];
        foreach ($scenario['trees'] as [$common, $scientific, $quantity, $diameter, $height]) {
            $trees[] = [
                'common_name' => $common,
                'scientific_name' => $scientific,
                'quantity' => (string) $quantity,
                'diameter_cm' => (string) $diameter,
                'estimated_height_m' => (string) $height,
                'condition_notes' => 'Healthy standing tree recorded during self-assessment.',
            ];
        }

        $result = submit_permit_application(
            $pdo,
            $userId,
            new_permit_submission_key(),
            $applicationInput,
            $trees
        );
        $applicationId = (int) $result['application_id'];
        $transactionId = (string) $result['transaction_id'];

        // Attach one pending scan per resolved requirement.
        $application = permit_load_application($pdo, $applicationId);
        $checklist = permit_document_type_catalog($application);
        $documentCount = 0;
        foreach ($checklist as $documentType => $definition) {
            $bytes = seed_demo_pdf_bytes((string) $definition['label']);
            $storage = permit_document_relative_storage_path($transactionId, 'pdf');
            if (file_put_contents($storage['absolute_path'], $bytes) === false) {
                throw new RuntimeException('Unable to write the seeded scan for ' . $documentType . '.');
            }
            @chmod($storage['absolute_path'], 0600);

            $insertDocument->execute([
                ':application_id' => $applicationId,
                ':document_type' => $documentType,
                ':storage_path' => $storage['relative_path'],
                ':original_filename' => $documentType . '.pdf',
                ':mime_type' => 'application/pdf',
                ':file_size_bytes' => strlen($bytes),
                ':uploaded_by_user_id' => $userId,
            ]);
            $documentCount++;
        }

        $created++;
        printf(
            "%-16s %-9s %-14s %2d pending scan(s)  fees %s  (%s)\n",
            $transactionId,
            (string) $category['permit_code'],
            (string) $applicant['username'],
            $documentCount,
            permit_matrix_format_peso((float) $application['total_fee']),
            $scenario['category']
        );
    }

    echo "\nSeeded {$created} submitted application(s) with unverified scans.\n";
    echo "Log in as RPS and open each application's Documents tab to test the one-click review.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . "\n");
    exit(1);
}
