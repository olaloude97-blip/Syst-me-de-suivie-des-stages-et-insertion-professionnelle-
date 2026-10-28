<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
start_session();
refresh_session_user();
require_login();
$user = current_user();
$type = $_GET['type'] ?? '';
if (isset($_GET['user']) && $type === 'certificat') {
    $ownerId = (int)$_GET['user'];
    if (!is_role('administrateur') && (int)$user['id'] !== $ownerId) { http_response_code(403); exit('Accès refusé.'); }
    $stmt = db()->prepare('SELECT certificat_fichier FROM utilisateurs WHERE id=?');
    $stmt->execute([$ownerId]);
    $filename = $stmt->fetchColumn();
} else {
    $stmt = db()->prepare('SELECT * FROM memoires WHERE lien_partage=?');
    $stmt->execute([$_GET['id'] ?? '']);
    $memo = $stmt->fetch();
    if (!$memo) { http_response_code(404); exit('Fichier introuvable.'); }
    $staff = is_role('bibliothecaire','administrateur');
    $owner = (int)$memo['deposant_id'] === (int)$user['id'];
    if ($type === 'memoire') {
        if ($memo['statut'] !== 'valide' && !$owner && !$staff) { http_response_code(403); exit('Accès refusé.'); }
        $filename = $memo['fichier_pdf'];
        if ($memo['statut'] === 'valide') {
            $limit = db()->prepare('SELECT COUNT(*) FROM telechargements WHERE utilisateur_id=? AND cree_le >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $limit->execute([$user['id']]);
            if ((int)$limit->fetchColumn() >= 20) { http_response_code(429); exit('Limite de 20 téléchargements par heure atteinte. Réessayez plus tard.'); }
            db()->prepare('INSERT INTO telechargements (utilisateur_id,memoire_id) VALUES (?,?)')->execute([$user['id'],$memo['id']]);
        }
    } elseif ($type === 'attestation') {
        if (!$owner && !$staff) { http_response_code(403); exit('Accès refusé.'); }
        $filename = $memo['attestation_fichier'];
    } else { http_response_code(400); exit('Type de fichier invalide.'); }
}
if (!$filename || !preg_match('/\A[a-f0-9]{48}\.(pdf|jpg|png)\z/', $filename)) { http_response_code(404); exit('Fichier introuvable.'); }
$path = STORAGE_PATH . '/' . $filename;
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Fichier introuvable.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
header('Content-Type: ' . ($mime === 'application/pdf' ? 'application/pdf' : ($mime === 'image/png' ? 'image/png' : 'image/jpeg')));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="document-' . bin2hex(random_bytes(4)) . '.' . pathinfo($filename, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);