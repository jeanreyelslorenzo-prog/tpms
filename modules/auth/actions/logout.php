<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
logout();
redirect(APP_URL . '/login');
