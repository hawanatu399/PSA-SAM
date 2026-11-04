<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$fullName = getenv('PSA_BOOTSTRAP_NAME');
$email = getenv('PSA_BOOTSTRAP_EMAIL');
$password = getenv('PSA_BOOTSTRAP_PASSWORD');

if (!is_string($fullName) || trim($fullName) === ''
    || !is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || !is_string($password)) {
    fwrite(STDERR, "Set PSA_BOOTSTRAP_NAME, PSA_BOOTSTRAP_EMAIL, and PSA_BOOTSTRAP_PASSWORD before running this command.\n");
    exit(1);
}

$fullName = trim($fullName);
$email = trim($email);

if (strlen($fullName) > 150 || strlen($email) > 190) {
    fwrite(STDERR, "The name or email address exceeds the allowed length.\n");
    exit(1);
}

if (strlen($password) < 14 || strlen($password) > 72
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[0-9]/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)) {
    fwrite(STDERR, "Use a unique password of 14-72 bytes including uppercase, lowercase, a number, and a symbol.\n");
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
putenv('PSA_BOOTSTRAP_PASSWORD');
unset($password);

try {
    $pdo = Database::connection();
} catch (PDOException | RuntimeException $exception) {
    fwrite(STDERR, "Administrator setup could not connect to the database. Check the database settings.\n");
    error_log('PSA-SAMS initial administrator database connection failed: ' . $exception->getMessage());
    exit(1);
}

$lock = $pdo->query("SELECT GET_LOCK('psa_sams_initial_admin', 10)")->fetchColumn();

if ((int) $lock !== 1) {
    fwrite(STDERR, "Could not obtain the administrator setup lock. Try again.\n");
    exit(1);
}

$alreadyExists = false;
try {
    $pdo->beginTransaction();
    $activeAdmin = $pdo->query(
        "SELECT id FROM users WHERE role = 'super_admin' AND status = 'Active' LIMIT 1 FOR UPDATE"
    )->fetchColumn();

    if ($activeAdmin !== false) {
        $pdo->rollBack();
        $alreadyExists = true;
    } else {
        $statement = $pdo->prepare(
            "INSERT INTO users (
                full_name, email, password_hash, role, department_id, status, must_change_password
             ) VALUES (:full_name, :email, :password_hash, 'super_admin', NULL, 'Active', 1)"
        );
        $statement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        $pdo->commit();
    }
} catch (PDOException | RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Administrator setup failed. Check the database connection and whether the email is already in use.\n");
    error_log('PSA-SAMS initial administrator setup failed: ' . $exception->getMessage());
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('psa_sams_initial_admin')");
}

if ($alreadyExists) {
    fwrite(STDERR, "An active super administrator already exists. No account was created.\n");
    exit(1);
}

fwrite(STDOUT, "Initial super administrator created successfully. Sign in using the configured email.\n");
