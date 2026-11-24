<?php
// ARCHIVO: backend/public/api/mapa.php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';

use App\Controllers\MapaController;
use App\Middleware\CorsMiddleware;

CorsMiddleware::handle();
MapaController::handle();
