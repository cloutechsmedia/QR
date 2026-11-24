<?php
/**
 * Health Check Endpoint for Render.com & UptimeRobot Keep-Awake Pings
 */

declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(['status' => 'ok', 'service' => 'techzo-kochi-scratch-win']);
