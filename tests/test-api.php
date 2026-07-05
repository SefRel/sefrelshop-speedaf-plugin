<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../includes/class-speedaf-config.php';
require '../includes/class-speedaf-encryption.php';
require '../includes/class-speedaf-api.php';

$config = new SpeedafConfig();

$encryptor = new SpeedafEncryption(
    $config->get('secretKey')
);

$api = new SpeedafApi(
    $config,
    $encryptor
);

$result = $api->post(
    '/open-api/express/order/createOrder',
    [
        'name' => 'Ridwan',
        'phone' => '08169793233'
    ]
);

echo "<pre>";
print_r($result);
echo "</pre>";