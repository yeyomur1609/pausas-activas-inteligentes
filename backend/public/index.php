<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Recurso API no encontrado.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
