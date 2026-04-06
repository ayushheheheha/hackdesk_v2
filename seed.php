<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pdo = Database::getConnection();

$email = 'admin@hackdesk.local';
$password = 'Admin@1234';
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);

$checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$checkStmt->execute([$email]);
$existingUser = $checkStmt->fetch();

if ($existingUser !== false) {
    echo "Super admin already exists for {$email}." . PHP_EOL;
    exit(0);
}

$insertStmt = $pdo->prepare(
    'INSERT INTO users (name, email, password_hash, role, is_active, login_attempts, locked_until)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$insertStmt->execute([
    'HackDesk Super Admin',
    $email,
    $passwordHash,
    'super_admin',
    1,
    0,
    null,
]);

echo 'Super admin created successfully.' . PHP_EOL;
echo 'Email: admin@hackdesk.local' . PHP_EOL;
echo 'Password: Admin@1234' . PHP_EOL;
