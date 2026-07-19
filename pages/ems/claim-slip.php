<?php
/** EMS printable seedling claim slip for a single request (ready for pickup or claimed). */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/seedling.php';
require_once __DIR__ . '/../../includes/view.php';

require_role($pdo, 'ems');

$userId = (int) $_SESSION['id'];
$requestValue = trim((string) ($_GET['request'] ?? ''));
$requestId = ctype_digit($requestValue) ? (int) $requestValue : 0;

if ($requestId < 1) {
    http_response_code(400);
    die('A valid request is required.');
}

try {
    $request = seedling_request_for_actor($pdo, $requestId, $userId);
    if ($request === null) {
        http_response_code(404);
        die('The seedling request was not found.');
    }
    $items = seedling_request_items($pdo, $requestId);
} catch (PDOException $e) {
    error_log('[CERTREEFY CLAIM SLIP LOAD ERROR] ' . $e->getMessage());
    http_response_code(500);
    die('Unable to load the claim slip at this time.');
}

$status = (string) $request['current_status'];
$isEligible = in_array($status, ['ready_for_pickup', 'claimed'], true);
$reference = (string) ($request['request_reference'] ?? ('#' . $requestId));
$totalApproved = 0;
foreach ($items as $item) {
    $totalApproved += (int) ($item['quantity_approved'] ?? 0);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claim Slip <?php echo e($reference); ?> | CERTREEFY</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #202b22; --muted: #566058; --line: #ccd2c9; --fern: #2d6a4f; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef1ec;
            color: var(--ink);
            font-family: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
            padding: 2rem 1rem;
        }
        .slip {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 2.25rem 2.5rem 2.5rem;
            box-shadow: 0 12px 40px -24px rgba(18, 36, 29, 0.4);
        }
        .toolbar { max-width: 720px; margin: 0 auto 1rem; display: flex; gap: 0.5rem; justify-content: flex-end; }
        .btn {
            font: inherit; font-weight: 600; cursor: pointer;
            border-radius: 8px; padding: 0.5rem 1rem; border: 1.5px solid var(--fern);
            background: var(--fern); color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .btn.ghost { background: #fff; color: var(--fern); }
        .slip-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; border-bottom: 2px solid var(--fern); padding-bottom: 1rem; margin-bottom: 1.25rem; }
        .org { font-family: "Fraunces", Georgia, serif; }
        .org h1 { margin: 0; font-size: 1.15rem; }
        .org p { margin: 0.15rem 0 0; color: var(--muted); font-size: 0.85rem; }
        .doc-title { text-align: right; }
        .doc-title .label { font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); font-weight: 700; }
        .doc-title .ref { font-family: "Fraunces", Georgia, serif; font-size: 1.35rem; font-weight: 700; }
        .status-pill { display: inline-block; margin-top: 0.35rem; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; }
        .status-ready { background: #f6ead2; color: #8a5a12; }
        .status-claimed { background: #dcede9; color: #1f6a68; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1.5rem; margin-bottom: 1.5rem; }
        .field .k { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700; }
        .field .v { font-size: 0.95rem; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
        th, td { text-align: left; padding: 0.55rem 0.65rem; border-bottom: 1px solid var(--line); font-size: 0.9rem; }
        th { background: #f2f6f1; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        td.qty, th.qty { text-align: right; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: 700; border-top: 2px solid var(--line); border-bottom: none; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; margin-top: 2.5rem; }
        .sign .line { border-top: 1px solid var(--ink); padding-top: 0.35rem; font-size: 0.8rem; color: var(--muted); }
        .note { margin-top: 1.5rem; padding: 0.75rem 1rem; background: #f6f4ec; border: 1px solid var(--line); border-radius: 8px; font-size: 0.85rem; color: var(--muted); }
        .foot { margin-top: 1.5rem; font-size: 0.75rem; color: var(--muted); text-align: center; }
        @media (max-width: 575.98px) {
            body { padding: 1rem 0.75rem; }
            .toolbar { max-width: 100%; }
            .slip { padding: 1.5rem 1.25rem; }
            .slip-head { flex-direction: column; }
            .doc-title { text-align: left; }
            .grid { grid-template-columns: 1fr; gap: 0.85rem; }
            .sign { grid-template-columns: 1fr; gap: 2rem; }
        }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .slip { border: none; box-shadow: none; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a class="btn ghost" href="claim-slips.php">&larr; Back</a>
        <button class="btn" type="button" onclick="window.print()">Print slip</button>
    </div>

    <div class="slip">
        <div class="slip-head">
            <div class="org">
                <h1>CENRO Sta. Cruz, Laguna</h1>
                <p>Community Environment and Natural Resources Office</p>
                <p>Seedling Distribution Program</p>
            </div>
            <div class="doc-title">
                <div class="label">Seedling Claim Slip</div>
                <div class="ref"><?php echo e($reference); ?></div>
                <?php if ($status === 'claimed'): ?>
                    <span class="status-pill status-claimed">Claimed</span>
                <?php elseif ($status === 'ready_for_pickup'): ?>
                    <span class="status-pill status-ready">Ready for pickup</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isEligible): ?>
            <div class="note">This request is currently <strong><?php echo e(seedling_request_status_label($status)); ?></strong>. A claim slip is issued once the request is approved and ready for pickup.</div>
        <?php endif; ?>

        <div class="grid">
            <div class="field"><div class="k">Requester</div><div class="v"><?php echo e((string) ($request['requester_full_name'] ?? $request['requester_name'])); ?></div></div>
            <div class="field"><div class="k">Contact</div><div class="v"><?php echo $request['requester_contact'] !== null && $request['requester_contact'] !== '' ? e((string) $request['requester_contact']) : '&mdash;'; ?></div></div>
            <div class="field"><div class="k">Planting purpose</div><div class="v"><?php echo e((string) $request['planting_purpose']); ?></div></div>
            <div class="field"><div class="k">Planting location</div><div class="v"><?php echo e((string) $request['planting_location']); ?></div></div>
            <div class="field"><div class="k">Ready since</div><div class="v"><?php echo $request['fulfilled_at'] !== null ? e(date('F j, Y', strtotime((string) $request['fulfilled_at']))) : '&mdash;'; ?></div></div>
            <div class="field"><div class="k">Pickup location</div><div class="v"><?php echo e(seedling_claim_location()); ?></div></div>
        </div>

        <table>
            <thead>
                <tr><th>Species</th><th class="qty">Approved quantity</th></tr>
            </thead>
            <tbody>
                <?php if ($items === []): ?>
                    <tr><td colspan="2" style="text-align:center;color:var(--muted);">No approved items recorded.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo e((string) $item['common_name']); ?></td>
                        <td class="qty"><?php echo (int) ($item['quantity_approved'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td>Total seedlings</td><td class="qty"><?php echo (int) $totalApproved; ?></td></tr>
            </tfoot>
        </table>

        <?php if ($status === 'claimed'): ?>
            <div class="grid">
                <div class="field"><div class="k">Claimed by</div><div class="v"><?php echo e((string) ($request['claimed_by_name'] ?? '&mdash;')); ?></div></div>
                <div class="field"><div class="k">Date claimed</div><div class="v"><?php echo $request['claimed_on'] !== null ? e(date('F j, Y', strtotime((string) $request['claimed_on']))) : '&mdash;'; ?></div></div>
            </div>
        <?php endif; ?>

        <div class="sign">
            <div class="line">Released by (EMS personnel)</div>
            <div class="line">Received by (claimant signature over printed name)</div>
        </div>

        <div class="foot">Generated <?php echo e(date('F j, Y g:i A')); ?> &middot; CERTREEFY &middot; This slip is a program record and is not a tree-cutting permit.</div>
    </div>
</body>
</html>
