<?php
/**
 * Community account self-service email verification: token issuance,
 * base-URL/path detection (so links work both at a domain root and
 * from a subfolder such as XAMPP's htdocs/Certreefy), and the emailed
 * HTML itself.
 */

require_once __DIR__ . '/mailer.php';

// A verification link is valid for this many hours after being issued.
define('EMAIL_VERIFY_TTL_HOURS', 24);

/**
 * Detects the site's base path from the currently running script so links
 * resolve correctly whether Certreefy is served from a domain root
 * (production) or a subfolder (e.g. /Certreefy on localhost). Assumes the
 * calling script lives two directories below the app root, which holds for
 * every caller of issueVerificationEmail() (pages/auth/*.php).
 */
function appBasePath(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = dirname($script, 3);

    return $base === '/' || $base === '\\' ? '' : $base;
}

function appBaseUrl(): string
{
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    );
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . appBasePath();
}

/**
 * Generates a fresh one-time verification token for the given user, stores
 * only its SHA-256 hash (the same principle as a password-reset token — if
 * the users table is ever exposed, no valid links can be reconstructed from
 * it), and emails the raw token as a link. Returns true if the email was
 * sent successfully.
 */
function issueVerificationEmail(PDO $pdo, int $userId, string $email, string $firstName): bool
{
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + EMAIL_VERIFY_TTL_HOURS * 3600);

    $stmt = $pdo->prepare(
        'UPDATE tbl_users
         SET email_verify_token = :token_hash, email_verify_expires = :expires_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
        ':id' => $userId,
    ]);

    $verifyLink = appBaseUrl() . '/pages/auth/verify_email.php?token=' . $rawToken;

    return sendAppMail(
        $email,
        $firstName,
        'Verify your CERTREEFY account',
        verification_email_html($firstName, $verifyLink)
    );
}

function verification_email_html(string $firstName, string $verifyLink): string
{
    $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f6f4ec;font-family:Georgia,'Times New Roman',serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4ec;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e2ddca;border-radius:14px;overflow:hidden;max-width:480px;width:100%;">
                    <tr>
                        <td style="background:#1b4332;padding:28px 32px;text-align:center;">
                            <span style="display:inline-block;width:40px;height:40px;line-height:40px;border-radius:50%;background:#2d6a4f;color:#eaf3ec;font-size:20px;">&#127795;</span>
                            <div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:0.03em;margin-top:10px;font-family:Arial,sans-serif;">CERTREEFY</div>
                            <div style="color:#b9c9bd;font-size:12px;margin-top:2px;font-family:Arial,sans-serif;">Districts 3 &amp; 4, Laguna</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;font-family:Arial,sans-serif;color:#202b22;">
                            <h1 style="font-size:20px;margin:0 0 16px;color:#1b4332;">Verify your email address</h1>
                            <p style="font-size:14px;line-height:1.6;margin:0 0 16px;">Hi {$safeName},</p>
                            <p style="font-size:14px;line-height:1.6;margin:0 0 24px;">
                                Thanks for registering a Community account with CERTREEFY. Click the button below to verify
                                your email address and activate your account &mdash; no further waiting required.
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:10px;background:#2d6a4f;">
                                        <a href="{$safeLink}" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;font-family:Arial,sans-serif;">Verify email address</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="font-size:12px;line-height:1.6;color:#7c877e;margin:24px 0 0;">
                                This link expires in 24 hours. If the button doesn't work, copy and paste this URL into your browser:<br>
                                <a href="{$safeLink}" style="color:#2d6a4f;word-break:break-all;">{$safeLink}</a>
                            </p>
                            <p style="font-size:12px;line-height:1.6;color:#7c877e;margin:16px 0 0;">
                                If you didn't create this account, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}
