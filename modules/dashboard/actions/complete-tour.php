<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
verifyCsrf();

$userId = (int)(currentUser()['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare('UPDATE users SET dashboard_tour_completed = 1, updated_at = NOW() WHERE id = ?');
$stmt->execute([$userId]);
echo json_encode(['success' => true]);

