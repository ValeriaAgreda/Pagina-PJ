<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, 'Método no permitido.');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 10240) {
    respond(413, 'La solicitud es demasiado grande.');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    respond(400, 'Solicitud inválida.');
}

// Los bots suelen completar este campo invisible; se responde sin enviar correo.
if (trim((string) ($payload['website'] ?? '')) !== '') {
    respond(201, 'Solicitud enviada correctamente.');
}

// Máximo cinco intentos por IP cada quince minutos.
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = sys_get_temp_dir() . '/pj_quote_' . hash('sha256', $clientIp) . '.json';
$now = time();
$attempts = [];
$handle = @fopen($rateFile, 'c+');
if ($handle !== false && flock($handle, LOCK_EX)) {
    $stored = stream_get_contents($handle);
    $decoded = json_decode($stored ?: '[]', true);
    $attempts = is_array($decoded) ? array_values(array_filter($decoded, fn ($time) => is_int($time) && $time > $now - 900)) : [];
    if (count($attempts) >= 5) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond(429, 'Has enviado varias solicitudes. Espera 15 minutos antes de intentarlo nuevamente.');
    }
    $attempts[] = $now;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($attempts));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

$labels = [
    'name' => 'Nombre', 'company' => 'Empresa', 'phone' => 'Teléfono', 'email' => 'Correo',
    'origin' => 'Origen', 'destination' => 'Destino', 'cargoType' => 'Tipo de carga',
    'incoterm' => 'Incoterm', 'volume' => 'Volumen (m³)', 'weight' => 'Peso (kg)',
    'estimatedDeliveryDate' => 'Fecha estimada de entrega',
];
$quote = [];
foreach ($labels as $key => $label) {
    $value = trim((string) ($payload[$key] ?? ''));
    if ($value === '') respond(400, "Completa el campo: {$label}.");
    if (mb_strlen($value) > 200) respond(400, "El campo {$label} supera la longitud permitida.");
    $quote[$key] = $value;
}

if (!filter_var($quote['email'], FILTER_VALIDATE_EMAIL)) respond(400, 'Ingresa un correo electrónico válido.');
if (!preg_match('/^\+?\d{7,15}$/', $quote['phone'])) respond(400, 'Ingresa un teléfono válido.');
if (!preg_match('/^\d+(?:[.,]\d+)?$/', $quote['volume']) || (float) str_replace(',', '.', $quote['volume']) <= 0) respond(400, 'Ingresa un volumen válido mayor que cero.');
if (!preg_match('/^\d+(?:[.,]\d+)?$/', $quote['weight']) || (float) str_replace(',', '.', $quote['weight']) <= 0) respond(400, 'Ingresa un peso válido mayor que cero.');

$timezone = new DateTimeZone('America/La_Paz');
$today = new DateTimeImmutable('today', $timezone);
$deliveryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $quote['estimatedDeliveryDate'], $timezone);
if (!$deliveryDate || $deliveryDate->format('Y-m-d') !== $quote['estimatedDeliveryDate'] || $deliveryDate < $today) {
    respond(400, 'La fecha de entrega no puede ser anterior a la fecha actual.');
}

$rows = '';
foreach ($labels as $key => $label) {
    $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeValue = htmlspecialchars($quote[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows .= "<tr><th style=\"padding:8px 12px;text-align:left;background:#f4f6f8\">{$safeLabel}</th><td style=\"padding:8px 12px\">{$safeValue}</td></tr>";
}

$to = 'info@pjservices-srl.com';
$subject = '=?UTF-8?B?' . base64_encode('Nueva solicitud de cotización — ' . $quote['company']) . '?=';
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: PJ Services <info@pjservices-srl.com>',
    'Reply-To: ' . $quote['email'],
];
$body = '<h2>Nueva solicitud de cotización</h2><table style="border-collapse:collapse">' . $rows . '</table>';

if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
    error_log('PJ Services: no se pudo enviar una solicitud de cotización.');
    respond(500, 'No pudimos enviar la solicitud. Inténtalo nuevamente.');
}

respond(201, 'Solicitud enviada correctamente.');
