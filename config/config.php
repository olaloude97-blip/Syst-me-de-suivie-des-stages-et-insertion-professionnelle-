<?php
declare(strict_types=1);

define('APP_NAME', 'MemoThèque');
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: '');
define('STORAGE_PATH', dirname(__DIR__) . '/storage');
define('MAX_MEMORY_SIZE', 50 * 1024 * 1024);
define('MAX_PROOF_SIZE', 5 * 1024 * 1024);

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $name = getenv('DB_NAME') ?: 'memotheque';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(APP_BASE_URL, '/') . '/'. ltrim($path, '/');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
        session_unset(); session_destroy(); session_start();
    }
    $_SESSION['last_activity'] = time();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Votre session a expiré. Rechargez la page.');
    }
}

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_role(string ...$roles): bool { return in_array(current_user()['role'] ?? '', $roles, true); }
function refresh_session_user(): void
{
    if (empty($_SESSION['user']['id'])) return;
    $stmt = db()->prepare('SELECT id,nom,prenom,email,role,niveau_etudes,statut_compte,motif_refus FROM utilisateurs WHERE id=?');
    $stmt->execute([$_SESSION['user']['id']]);
    $fresh = $stmt->fetch();
    if (!$fresh) { unset($_SESSION['user']); return; }
    $_SESSION['user'] = $fresh;
}
function require_login(): void
{
    if (!current_user()) { header('Location: index.php?page=connexion'); exit; }
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = [$message, $type]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

function upload_file(string $field, string $kind): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$field];
    $limit = $kind === 'memoire' ? MAX_MEMORY_SIZE : MAX_PROOF_SIZE;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $limit || $file['size'] < 1) throw new RuntimeException('Fichier invalide ou trop volumineux.');
    if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Téléversement non valide.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = $kind === 'certificat' ? ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'] : ['application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Format de fichier non autorisé.');
    $bytes = file_get_contents($file['tmp_name'], false, null, 0, 8);
    $signatureOk = $mime === 'application/pdf' ? str_starts_with($bytes, '%PDF-')
        : ($mime === 'image/png' ? str_starts_with($bytes, "\x89PNG\r\n\x1a\n") : str_starts_with($bytes, "\xFF\xD8\xFF"));
    if (!$signatureOk) throw new RuntimeException('Le contenu du fichier ne correspond pas à son type.');
    if (!is_dir(STORAGE_PATH) && !mkdir(STORAGE_PATH, 0700, true)) throw new RuntimeException('Stockage indisponible.');
    $name = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], STORAGE_PATH . '/' . $name)) throw new RuntimeException('Impossible d’enregistrer le fichier.');
    return $name;
}

function delete_stored(?string $name): void
{
    if ($name && preg_match('/\A[a-f0-9]{48}\.(pdf|jpg|png)\z/', $name)) @unlink(STORAGE_PATH . '/' . $name);
}