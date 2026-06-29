<?php

ini_set('display_errors',1);
error_reporting(E_ALL);

require 'includes/class-speedaf-config.php';
require 'includes/class-speedaf-encryption.php';

$config = new SpeedafConfig();

$encryptor = new SpeedafEncryption(
    $config->get('secretKey')
);

$order = [
    "name" => "Ridwan",
    "phone" => "08169793233"
];

$json = json_encode(
    $order,
    JSON_UNESCAPED_UNICODE
);

$timestamp = $encryptor->generateTimestamp();

$payload = $encryptor->buildPayload(
    $timestamp,
    $json
);

echo "<h2>Speedaf Payload Test</h2>";

echo "<b>Timestamp</b><br>";
echo htmlspecialchars($timestamp);

echo "<br><br>";

echo "<b>Original JSON</b><br>";
echo htmlspecialchars($json);

echo "<br><br>";

echo "<b>Payload</b><br>";
echo "<pre>";
echo htmlspecialchars($payload);
echo "</pre>";