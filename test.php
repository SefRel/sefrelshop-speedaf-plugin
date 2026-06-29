<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/class-speedaf-config.php';
require 'includes/class-speedaf-encryption.php';

$config = new SpeedafConfig();

$encryptor = new SpeedafEncryption(
    $config->get('secretKey')
);

$data = [
    'name' => 'Ridwan',
    'phone' => '08169793233'
];

$json = json_encode($data, JSON_UNESCAPED_UNICODE);

$timestamp = round(microtime(true) * 1000);

echo "<h2>Signature Test</h2>";

echo "<strong>Timestamp:</strong><br>";
echo $timestamp;

echo "<br><br>";

echo "<strong>JSON:</strong><br>";
echo htmlspecialchars($json);

echo "<br><br>";

echo "<strong>Signature:</strong><br>";
echo $encryptor->generateSignature(
    (string)$timestamp,
    $json
);