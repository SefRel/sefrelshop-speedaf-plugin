<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/class-speedaf-config.php';
require 'includes/class-speedaf-encryption.php';

$config = new SpeedafConfig();

$encryptor = new SpeedafEncryption(
    $config->get('secretKey')
);

$order = [
    'name' => 'Ridwan',
    'phone' => '08169793233',
    'weight' => 2
];

$json = json_encode($order, JSON_UNESCAPED_UNICODE);

echo "<h2>Round Trip Encryption Test</h2>";

echo "<strong>Original:</strong><br>";
echo htmlspecialchars($json);

echo "<br><br>";

$encrypted = $encryptor->encrypt($json);

echo "<strong>Encrypted:</strong><br>";
echo htmlspecialchars($encrypted);

echo "<br><br>";

$decrypted = $encryptor->decrypt($encrypted);

echo "<strong>Decrypted:</strong><br>";
echo htmlspecialchars($decrypted);

echo "<br><br>";

if ($json === $decrypted) {
    echo "<h3 style='color:green;'>✅ SUCCESS - Encryption and Decryption Match</h3>";
} else {
    echo "<h3 style='color:red;'>❌ FAILED - Data Does Not Match</h3>";
}