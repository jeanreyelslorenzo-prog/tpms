<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
header('Location: ' . APP_URL . '/dashboard.php?open_tala=1');
exit;
