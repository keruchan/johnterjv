<?php
/**
 * ============================================================
 * File     : config/create_users.php
 * Project  : CERTREEFY
 * Purpose  : One-time utility to create default system users.
 *
 * IMPORTANT:
 * - Run ONLY once.
 * - Delete this file after successful execution.
 * ============================================================
 */

require_once __DIR__ . '/config.php';

$users = [
    [
        'fname'    => 'CENRO',
        'mname'    => '',
        'lname'    => 'Administrator',
        'email'    => 'admin@certreefy.gov.ph',
        'contact'  => '09123456789',
        'address'  => 'CENRO Sta. Cruz, Laguna',
        'username' => 'admin',
        'password' => 'Admin@123',
        'role'     => 'superadmin',
        'status'   => 'active'
    ],
    [
        'fname'    => 'RPS',
        'mname'    => '',
        'lname'    => 'Officer',
        'email'    => 'rps@certreefy.gov.ph',
        'contact'  => '09123456780',
        'address'  => 'CENRO Sta. Cruz, Laguna',
        'username' => 'rps',
        'password' => 'RPS@1234',
        'role'     => 'rps',
        'status'   => 'active'
    ],
    [
        'fname'    => 'EMS',
        'mname'    => '',
        'lname'    => 'Officer',
        'email'    => 'ems@certreefy.gov.ph',
        'contact'  => '09123456782',
        'address'  => 'CENRO Sta. Cruz, Laguna',
        'username' => 'ems',
        'password' => 'EMS@1234',
        'role'     => 'ems',
        'status'   => 'active'
    ],
    [
        'fname'    => 'Community',
        'mname'    => '',
        'lname'    => 'User',
        'email'    => 'community@certreefy.gov.ph',
        'contact'  => '09123456781',
        'address'  => 'Sta. Cruz, Laguna',
        'username' => 'community',
        'password' => 'Community@123',
        'role'     => 'community',
        'status'   => 'active'
    ]
];

try {

    $check = $pdo->prepare("
        SELECT COUNT(*)
        FROM tbl_users
        WHERE username = ?
           OR email = ?
    ");

    $insert = $pdo->prepare("
        INSERT INTO tbl_users
        (
            fname,
            mname,
            lname,
            email,
            contact,
            address,
            username,
            password,
            role,
            status
        )
        VALUES
        (
            :fname,
            :mname,
            :lname,
            :email,
            :contact,
            :address,
            :username,
            :password,
            :role,
            :status
        )
    ");

    echo "<h2>CERTREEFY - Default User Creation</h2>";
    echo "<hr>";

    foreach ($users as $user) {

        $check->execute([
            $user['username'],
            $user['email']
        ]);

        if ($check->fetchColumn() > 0) {

            echo "⚠ User <strong>{$user['username']}</strong> already exists.<br>";
            continue;
        }

        $insert->execute([
            ':fname'    => $user['fname'],
            ':mname'    => $user['mname'],
            ':lname'    => $user['lname'],
            ':email'    => $user['email'],
            ':contact'  => $user['contact'],
            ':address'  => $user['address'],
            ':username' => $user['username'],
            ':password' => password_hash($user['password'], PASSWORD_DEFAULT),
            ':role'     => $user['role'],
            ':status'   => $user['status']
        ]);

        echo "✅ User <strong>{$user['username']}</strong> created successfully.<br>";
    }

    echo "<hr>";
    echo "<strong>Default Accounts</strong><br><br>";

    echo "
    <table border='1' cellpadding='8' cellspacing='0'>
        <tr>
            <th>Role</th>
            <th>Username</th>
            <th>Password</th>
        </tr>
        <tr>
            <td>Superadmin</td>
            <td>admin</td>
            <td>Admin@123</td>
        </tr>
        <tr>
            <td>RPS User</td>
            <td>rps</td>
            <td>RPS@1234</td>
        </tr>
        <tr>
            <td>EMS User</td>
            <td>ems</td>
            <td>EMS@1234</td>
        </tr>
        <tr>
            <td>Community</td>
            <td>community</td>
            <td>Community@123</td>
        </tr>
    </table>
    ";

    echo "<br><br>";
    echo "<strong style='color:red'>IMPORTANT:</strong> Delete <code>create_users.php</code> after successfully creating the accounts.";

} catch (PDOException $e) {

    die("Database Error: " . $e->getMessage());
}
