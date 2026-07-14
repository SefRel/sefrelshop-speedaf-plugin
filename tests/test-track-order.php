<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "File started<br>";

require_once __DIR__ . '/../includes/class-speedaf-config.php';
echo "Config loaded<br>";

require_once __DIR__ . '/../includes/class-speedaf-encryption.php';
echo "Encryption loaded<br>";

require_once __DIR__ . '/../includes/class-speedaf-api.php';
echo "API loaded<br>";

$config = new SpeedafConfig();
echo "Config Object Created<br>";

$encryptor = new SpeedafEncryption(
    $config->get('secretKey')
);
echo "Encryption Object Created<br>";

$api = new SpeedafApi(
    $config,
    $encryptor
);
echo "API Object Created<br>";

/**
 * Replaced with an actual billcode number
 * returned by Speedaf after creating an order. You can also use the order number from the test order created in tests/test-create-order.php
 */
$order = [

    "customerCode" => $config->get('customerCode'),

    "customerOrderNos" => "TEST-1783274442"

];

echo "<h3>Sending Request...</h3>";

$result = $api->post(
    "/open-api/express/track/customer/order/query",
    $order
);

echo "<h3>Request URL</h3>";
echo "<pre>{$result['url']}</pre>";

echo "<h3>Original JSON</h3>";
echo "<pre>";
echo htmlspecialchars($result['json']);
echo "</pre>";

echo "<h3>Payload Before Encryption</h3>";
echo "<pre>";
echo htmlspecialchars($result['payload']);
echo "</pre>";

echo "<h3>Encrypted Request</h3>";
echo "<pre>";
echo htmlspecialchars($result['encrypted']);
echo "</pre>";

echo "<h3>HTTP Status</h3>";
echo "<pre>{$result['status']}</pre>";

echo "<h3>cURL Error</h3>";
echo "<pre>{$result['error']}</pre>";

echo "<h3>Speedaf Response</h3>";
echo "<pre>";
echo htmlspecialchars($result['response']);
echo "</pre>";

/**
 * Automatically decrypt the response
 */
$response = json_decode($result['response'], true);

if (
    isset($response['success']) &&
    $response['success'] === true &&
    !empty($response['data'])
) {

    echo "<h3>Decrypted Response</h3>";

    try {

        $decrypted = $encryptor->decrypt(
            $response['data']
        );

        echo "<pre>";
        echo json_encode(
            json_decode($decrypted, true),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        echo "</pre>";

    } catch (Exception $e) {

        echo "<pre>";
        echo $e->getMessage();
        echo "</pre>";

    }

}