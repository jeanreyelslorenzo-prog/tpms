<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
verifyCsrf();

$raw = (string)($_POST['preferences'] ?? '');
$preferences = json_decode($raw, true);
if (!is_array($preferences)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid preferences.']);
    exit;
}

$allowed = [
    'theme' => ['glass', 'frosted-glass', 'ios', 'pastel-sky', 'pastel-sunset'],
    'density' => ['comfortable', 'compact'],
    'layout' => ['default', 'app'],
    'bgPalette' => ['theme', 'custom-color'],
    'bgEffects' => ['off', 'soft', 'vivid', 'immersive', 'color-flow'],
    'glassTone' => ['soft', 'balanced', 'strong'],
];
$clean = [];
foreach ($allowed as $key => $values) {
    $value = (string)($preferences[$key] ?? '');
    if (in_array($value, $values, true)) $clean[$key] = $value;
}
foreach (['accentColor', 'bgTintColor', 'teacherTagColor', 'schoolHeadColor'] as $key) {
    $value = strtolower(trim((string)($preferences[$key] ?? '')));
    if (preg_match('/^#[0-9a-f]{6}$/', $value)) $clean[$key] = $value;
}

$json = json_encode($clean, JSON_UNESCAPED_SLASHES);
$db = getDB();
$db->prepare('UPDATE users SET preferred_theme=?, preferred_layout=?, preferred_appearance_json=?, updated_at=NOW() WHERE id=?')
    ->execute([$clean['theme'] ?? null, $clean['layout'] ?? null, $json, (int)(currentUser()['id'] ?? 0)]);
$_SESSION['preferred_theme'] = $clean['theme'] ?? '';
$_SESSION['preferred_layout'] = $clean['layout'] ?? '';
$_SESSION['preferred_appearance_json'] = $json;
echo json_encode(['ok' => true]);

