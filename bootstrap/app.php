<?php

declare(strict_types=1);

use App\Core\Env;

require_once __DIR__ . '/autoload.php';

Env::load(base_path('.env'));

date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Shanghai'));

$sessionName = (string) env('SESSION_NAME', 'oc_invest_session');
if (session_status() === PHP_SESSION_NONE) {
    session_name($sessionName);
    session_start();
}
