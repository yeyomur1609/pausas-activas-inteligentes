<?php
// ARCHIVO: backend/public/api/auth.php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';

use App\Controllers\AuthController;
use App\Middleware\CorsMiddleware;

CorsMiddleware::handle();
AuthController::handle();
