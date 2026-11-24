<?php
// ARCHIVO: backend/public/api/rutinas.php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';

use App\Controllers\RutinasController;
use App\Middleware\CorsMiddleware;

CorsMiddleware::handle();
RutinasController::handle();
