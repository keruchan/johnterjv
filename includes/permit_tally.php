<?php
/** Bounded, read-only XLSX parsing and append-only inspection tally uploads. */
require_once __DIR__ . '/permit_inspections.php';

function permit_tally_xml(string $xml): SimpleXMLElement
{
    if (strlen($xml) > 8 * 1024 * 1024 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new InvalidArgumentException('The tally contains unsupported XML content.');
    }
    $previous = libxml_use_internal_errors(true);
    try { $result = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    if ($result === false) { throw new InvalidArgumentException('The tally workbook is invalid.'); }
    return $result;
}

function permit_tally_coordinate(string $value, bool $latitude = true): float
{
    $value = trim($value);
    if (is_numeric($value)) { return (float) $value; }
    if (!preg_match('/^(-?\d{1,3})\s*[°º]\s*(\d{1,2})\s*[\x{2032}\x{2019}\x{0027}]\s*(\d{1,2}(?:\.\d+)?)\s*[\x{2033}\x{201D}\x{0022}]?\s*([NSEW])?$/ui', $value, $match)
        || (int) $match[2] >= 60 || (float) $match[3] >= 60) {
        throw new InvalidArgumentException('Use decimal degrees or degree-minute-second coordinates in the tally.');
    }
    $sign = (float) $match[1] < 0 || in_array(strtoupper($match[4] ?? ''), ['S','W'], true) ? -1 : 1;
    $direction = strtoupper($match[4] ?? '');
    if (($latitude && in_array($direction,['E','W'],true)) || (!$latitude && in_array($direction,['N','S'],true))) {
        throw new InvalidArgumentException('Use North/South for latitude and East/West for longitude.');
    }
    return $sign * (abs((float) $match[1]) + (float) $match[2]/60 + (float) $match[3]/3600);
}

/** The supplied template has one tree per row. Totals and formulas never create trees. */
function permit_tally_parse(string $file, string $category): array
{
    if (file_get_contents($file, false, null, 0, 4) !== "PK\x03\x04") { throw new InvalidArgumentException('Upload a valid XLSX ZIP workbook.'); }
    try { $zip = new PharData($file, 0, null, Phar::ZIP); }
    catch (Throwable $e) { throw new InvalidArgumentException('Upload a valid .xlsx tally workbook.'); }
    $size = $entries = 0;
    foreach (new RecursiveIteratorIterator($zip) as $entry) {
        if (preg_match('#vbaProject\.bin|/xl/(?:activeX|embeddings|externalLinks)/#i', str_replace('\\','/',$entry->getPathname()))) {
            throw new InvalidArgumentException('Use a macro-free XLSX tally without embedded objects or external workbook links.');
        }
        if (++$entries > 2000 || $entry->getSize() > 8*1024*1024 || ($size += $entry->getSize()) > 40*1024*1024) {
            throw new InvalidArgumentException('The tally workbook is too large to process.');
        }
    }
    $read = static function (string $path) use ($zip): string {
        if (str_contains($path, '..') || !isset($zip[$path])) { throw new InvalidArgumentException('The workbook structure is unsupported.'); }
        return $zip[$path]->getContent();
    };
    $strings = [];
    if (isset($zip['xl/sharedStrings.xml'])) {
        $shared = permit_tally_xml($read('xl/sharedStrings.xml'));
        foreach ($shared->xpath('//*[local-name()="si"]') as $item) {
            $strings[] = implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]')));
        }
    }
    $workbook = permit_tally_xml($read('xl/workbook.xml'));
    $relations = permit_tally_xml($read('xl/_rels/workbook.xml.rels'));
    $targets = [];
    foreach ($relations->xpath('//*[local-name()="Relationship"]') as $relation) { $targets[(string) $relation['Id']] = (string) $relation['Target']; }
    $sheets = $workbook->xpath('//*[local-name()="sheet"]');
    if (count($sheets) !== 1) { throw new InvalidArgumentException('Use a workbook with one tally worksheet.'); }
    $rid = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    $target = $targets[$rid] ?? '';
    $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
    $sheet = permit_tally_xml($read($path));
    $headers = ['Tree No.','Species','DBH/DAB (cm)','MH (m)','TH (m)','Volume (m3)',
        'Tree Location: Northing','Tree Location: Easting','Tree Category','Stem Quality','Remarks'];
    $rows = []; $ids = []; $natural = $planted = $balling = $replacement = $excluded = 0; $volume = 0.0; $headerSeen = false;
    foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        $cells = []; $formulas = false; $formulaCells = [];
        foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main') as $cell) {
            $attributes = $cell->attributes();
            $column = preg_replace('/[0-9]/', '', (string) $attributes['r']);
            $values = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            if (!preg_match('/^[A-K]$/D', $column)) {
                if (isset($values->f)) { throw new InvalidArgumentException('Unexpected formula outside the tally columns.'); }
                continue;
            }
            $value = (string) $values->v;
            if ((string) $attributes['t'] === 's') { $value = $strings[(int) $value] ?? ''; }
            elseif ((string) $attributes['t'] === 'inlineStr') { $value = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]'))); }
            if (isset($values->f)) { $formulas = true; $formulaCells[$column] = (string)$values->f; }
            $cells[$column] = trim($value);
        }
        if ((int) $row['r'] === 1) {
            if ($formulas) { throw new InvalidArgumentException('Use plain-text tally headings.'); }
            foreach ($headers as $index=>$header) {
                if (strcasecmp(preg_replace('/\s+/', ' ', $cells[chr(65+$index)] ?? ''), $header) !== 0) {
                    throw new InvalidArgumentException('Use the supplied tally columns, beginning with Tree No. and Species.');
                }
            }
            $headerSeen = true;
            continue;
        }
        if (($cells['A'] ?? '') === '' && (($cells['B'] ?? '') === '' || in_array(strtolower($cells['B']), ['subtotal','total'], true))) {
            foreach ($formulaCells as $column=>$formula) {
                if ($column !== 'F' || !preg_match('/^SUM\((?:[A-Za-z_][A-Za-z0-9_]*\[Volume \(m3\)\]|F[0-9]+:F[0-9]+)\)$/i', $formula)) {
                    throw new InvalidArgumentException('Only a volume SUM formula is supported in the subtotal row.');
                }
            }
            continue;
        }
        $line = (int) $row['r'];
        if ($formulas) { throw new InvalidArgumentException('Row '.$line.': enter measured values; formulas are allowed only in the subtotal row.'); }
        $id = filter_var($cells['A'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($id === false || $id < 1 || $id > 100000000 || floor($id) !== $id || isset($ids[(int) $id])) {
            throw new InvalidArgumentException('Row '.$line.': tree numbers must be positive and unique.');
        }
        $ids[(int) $id] = true;
        if (++$entries > 12000 || count($rows) >= 10000) { throw new InvalidArgumentException('Use a tally with at most 10,000 tree rows.'); }
        $species = $cells['B'] ?? '';
        if ($species === '' || mb_strlen($species) > 150) { throw new InvalidArgumentException('Row '.$line.': provide a valid species name.'); }
        foreach (['C','D','E','F'] as $column) {
            $number = filter_var($cells[$column] ?? '', FILTER_VALIDATE_FLOAT);
            if ($number === false || !is_finite($number) || $number < ($column === 'F' ? 0 : 0.000001) || $number > 1000000) {
                throw new InvalidArgumentException('Row '.$line.': provide valid diameter, height, and volume measurements.');
            }
        }
        $origin = match (strtoupper($cells['I'] ?? '')) { 'PLANTED'=>'planted', 'NATURAL'=>'natural', 'BALLING','EARTH-BALLING'=>'balling', default=>'' };
        if ($origin === '' || permit_matrix_replacement_rule($category, $origin) === null) { throw new InvalidArgumentException('Row '.$line.': tree category is not supported for this permit.'); }
        $lat = permit_tally_coordinate($cells['G'] ?? ''); $lng = permit_tally_coordinate($cells['H'] ?? '', false);
        if (!geo_point_in_laguna($lat, $lng)) { throw new InvalidArgumentException('Row '.$line.': the tree coordinates must be within Laguna.'); }
        if (mb_strlen($cells['J'] ?? '') > 50 || mb_strlen($cells['K'] ?? '') > 500) { throw new InvalidArgumentException('Row '.$line.': stem quality or remarks are too long.'); }
        $forCutting = strtolower(preg_replace('/\s+/', ' ', $cells['K'] ?? '')) === 'for cutting';
        $ratio = permit_matrix_replacement_ratio($category,$origin);
        if ($ratio === null) { throw new InvalidArgumentException('The matrix replacement ratio is not configured for row '.$line.'.'); }
        if ($forCutting) {
            if ($origin === 'natural') { $natural++; }
            elseif ($origin === 'planted') { $planted++; }
            else { $balling++; }
            $replacement += $ratio;
        } else { $excluded++; }
        $volume += (float) $cells['F'];
        $rows[] = ['row'=>$line,'tree_no'=>(int)$id,'species'=>$species,'diameter_cm'=>(float)$cells['C'],
            'mh_m'=>(float)$cells['D'],'th_m'=>(float)$cells['E'],'volume_m3'=>(float)$cells['F'],
            'latitude'=>$lat,'longitude'=>$lng,'origin'=>$origin,'stem_quality'=>$cells['J'] ?? '',
            'remarks'=>$cells['K'] ?? '', 'for_cutting'=>$forCutting];
    }
    if (!$headerSeen || $rows === [] || $natural+$planted+$balling < 1) { throw new InvalidArgumentException('The tally must include the template headings and at least one tree marked for cutting.'); }
    return ['rows'=>$rows,'tree_count'=>$natural+$planted+$balling,'natural_count'=>$natural,'planted_count'=>$planted,
        'balling_count'=>$balling,'replacement_count'=>$replacement,'excluded_count'=>$excluded,'volume_total'=>round($volume,4)];
}

function permit_tally_current(PDO $pdo, int $applicationId, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare('SELECT t.*, CONCAT(u.fname, \' \', u.lname) AS uploader_name FROM tbl_permit_tallies t
        INNER JOIN tbl_users u ON u.id = t.uploaded_by_user_id WHERE application_id = ? AND is_current = 1
        ORDER BY t.id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$applicationId]); return $stmt->fetch() ?: null;
}

function permit_tally_upload(PDO $pdo, int $applicationId, int $actorId, array $file): array
{
    if (permit_inspection_actor($pdo, $actorId) === null) { throw new RuntimeException('You cannot upload inspection tallies.'); }
    $application = permit_inspection_application_for_actor($pdo, $applicationId, $actorId, 'view');
    if (!$application || permit_inspection_lock_reason($application) !== null) { throw new RuntimeException('The application is not available for tally uploads.'); }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))
        || (int) ($file['size'] ?? 0) > 10*1024*1024 || strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new InvalidArgumentException('Select an XLSX tally workbook up to 10 MB.');
    }
    $parsed = permit_tally_parse($file['tmp_name'], (string) $application['permit_category']);
    $sha = hash_file('sha256', $file['tmp_name']); $stored = null;
    try {
        $pdo->beginTransaction();
        $application = permit_load_application($pdo, $applicationId, true);
        if (permit_inspection_actor($pdo, $actorId, true) === null || !$application || permit_inspection_lock_reason($application) !== null) {
            throw new RuntimeException('The application changed before the tally could be saved.');
        }
        $existing = $pdo->prepare('SELECT * FROM tbl_permit_tallies WHERE application_id = ? AND sha256 = ? LIMIT 1');
        $existing->execute([$applicationId,$sha]);
        if ($duplicate = $existing->fetch()) {
            if (!(int) $duplicate['is_current']) { throw new InvalidArgumentException('This workbook is an earlier tally version. Upload the corrected current workbook.'); }
            $pdo->commit(); return $duplicate;
        }
        $folder = permit_document_storage_root() . '/tallies/' . $application['transaction_id'];
        if (!is_dir($folder) && !mkdir($folder, 0700, true)) { throw new RuntimeException('Unable to prepare private tally storage.'); }
        $relative = 'tallies/' . $application['transaction_id'] . '/' . bin2hex(random_bytes(16)) . '.xlsx';
        $stored = permit_document_storage_root() . '/' . $relative;
        if (!move_uploaded_file($file['tmp_name'], $stored)) { throw new RuntimeException('Unable to store the tally workbook.'); }
        @chmod($stored, 0600);
        $pdo->prepare('UPDATE tbl_permit_tallies SET is_current = 0 WHERE application_id = ? AND is_current = 1')->execute([$applicationId]);
        $stmt = $pdo->prepare('INSERT INTO tbl_permit_tallies (application_id,uploaded_by_user_id,original_filename,storage_path,sha256,rows_json,tree_count,replacement_count,natural_count,planted_count,volume_total)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$applicationId,$actorId,permit_document_normalize_original_filename($file['name']),$relative,$sha,
            json_encode($parsed['rows'], JSON_THROW_ON_ERROR),$parsed['tree_count'],$parsed['replacement_count'],$parsed['natural_count'],$parsed['planted_count'],$parsed['volume_total']]);
        record_audit_event($pdo, $actorId, 'permit', 'inspection_tally_uploaded', 'permit_application', $applicationId, 'Inspection tally uploaded.',
            ['application_id'=>$applicationId,'trees'=>$parsed['tree_count'],'replacement_seedlings'=>$parsed['replacement_count']]);
        create_notification($pdo, (int) $application['applicant_user_id'], $actorId, 'permit_status', 'Inspection tally recorded',
            'RPS recorded '.$parsed['tree_count'].' trees for cutting. Calculated replacement: '.$parsed['replacement_count'].' seedlings. Await the RPS decision and donation instructions.', 'permit_application', $applicationId);
        $pdo->commit(); return permit_tally_current($pdo,$applicationId);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); if ($stored && is_file($stored)) unlink($stored); throw $e; }
}

function render_permit_tally(PDO $pdo, array $application, bool $canUpload = false): void
{
    $tally = permit_tally_current($pdo, (int) $application['id']);
    ?>
    <section class="docket-panel mb-3" id="inspection-tally" aria-labelledby="inspectionTallyHeading">
        <div class="section-heading"><h2 id="inspectionTallyHeading">Site Visit Tally</h2></div>
        <?php if (isset($_SESSION['permit_tally_flash'])): $flash = $_SESSION['permit_tally_flash']; unset($_SESSION['permit_tally_flash']); ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['message']); ?></div>
        <?php endif; ?>
        <?php if ($tally === null): ?><p class="text-secondary">No site-visit tally uploaded yet.</p><?php else: ?>
            <div class="row g-3 mb-3"><div class="col-sm-4"><div class="small text-secondary">Trees for cutting</div><strong><?php echo (int) $tally['tree_count']; ?></strong></div><div class="col-sm-4"><div class="small text-secondary">Replacement seedlings</div><strong><?php echo number_format((int) $tally['replacement_count']); ?></strong></div><div class="col-sm-4"><div class="small text-secondary">Inventoried volume</div><strong><?php echo e(number_format((float)$tally['volume_total'], 2)); ?> m³</strong></div></div>
            <p class="small text-secondary"><?php echo (int)$tally['natural_count']; ?> Natural × 100 + <?php echo (int)$tally['planted_count']; ?> Planted × 50. Only rows marked “for cutting” contribute to replacement counts.</p>
            <p class="small text-secondary text-break"><?php echo e($tally['original_filename']); ?> · <?php echo e($tally['uploader_name']); ?> · <?php echo e($tally['created_at']); ?></p>
            <a class="btn btn-sm btn-outline-secondary mb-3" href="../records/permit-tally-download.php?id=<?php echo (int)$tally['id']; ?>"><i class="bi bi-download"></i> Download tally</a>
            <details><summary>View tree entries</summary><div class="table-responsive mt-2"><table class="table align-middle"><thead><tr><th>Tree No.</th><th>Species</th><th>Origin</th><th>DBH/DAB (cm)</th><th>MH (m)</th><th>TH (m)</th><th>Remarks</th></tr></thead><tbody><?php foreach (array_slice(json_decode($tally['rows_json'], true),0,50) as $tree): ?><tr><td><?php echo (int)$tree['tree_no']; ?></td><td><?php echo e($tree['species']); ?></td><td><?php echo e(permit_matrix_tree_origins()[$tree['origin']]); ?></td><td><?php echo e((string)$tree['diameter_cm']); ?></td><td><?php echo e((string)$tree['mh_m']); ?></td><td><?php echo e((string)$tree['th_m']); ?></td><td><?php echo e($tree['remarks']); ?></td></tr><?php endforeach; ?></tbody></table></div><p class="small text-secondary">Showing up to 50 entries. Download the workbook for the full tally.</p></details>
        <?php endif; ?>
        <?php if ($canUpload && permit_inspection_lock_reason($application) === null): ?>
            <form method="post" action="permit-tally-upload.php" enctype="multipart/form-data" class="row g-3 mt-2">
                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_permit_inspection_token']); ?>"><input type="hidden" name="application_id" value="<?php echo (int)$application['id']; ?>">
                <div class="col-md-9"><label class="form-label" for="inspectionTallyFile">Excel tally sheet</label><input class="form-control" type="file" id="inspectionTallyFile" name="tally_file" accept=".xlsx" required><div class="form-text">Use the supplied 11-column format. Maximum 10 MB. Tree coordinates must be in Laguna.</div></div><div class="col-md-3 align-self-end"><button class="btn btn-certreefy" type="submit">Upload tally</button></div>
            </form>
        <?php endif; ?>
    </section>
    <?php
}
