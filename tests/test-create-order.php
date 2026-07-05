<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/class-speedaf-config.php';
require_once __DIR__ . '/../includes/class-speedaf-encryption.php';
require_once __DIR__ . '/../includes/class-speedaf-api.php';

echo "<h2>Speedaf Create Order Test</h2>";

$config = new SpeedafConfig();

$encryptor = new SpeedafEncryption(
    $config->get('secretKey')
);

$api = new SpeedafApi(
    $config,
    $encryptor
);

/**
 * Sample Order
 */
$order = [

    // ==========================
    // Receiver
    // ==========================

    "acceptAddress"      => "15 Admiralty Way",
    "acceptCityName"     => "Lagos",
    "acceptCountryCode"  => "NG",
    "acceptCountryName"  => "Nigeria",
    "acceptEmail"        => "test@sefrelshop.com",
    "acceptMobile"       => "08012345678",
    "acceptName"         => "Ridwan Test",
    "acceptPhone"        => "08012345678",
    "acceptProvinceName" => "Lagos",
    "acceptStreetName"   => "Admiralty Way",

    // ==========================
    // Order
    // ==========================

    "customOrderNo" => "TEST-" . time(),
    "customerCode"  => $config->get('customerCode'),

    "currencyType" => "NGN",

    "deliveryType" => "DE01",
    "shipType"     => "ST01",
    "transportType"=> "TT02",

    "payMethod" => "PA01",

    "changeLable" => 0,
    "isAllowOpen" => 0,

    "goodsQTY"    => 1,
    "goodsWeight" => 1,

    "parcelWeight" => 1,
    "piece"        => 1,

    "shippingFee" => 0,
    "codFee"      => 100,

    "insurePrice" => 0,

    // ==========================
    // Parcel
    // ==========================

    "parcelType"         => "PT01",
    "parcelCurrencyType" => "NGN",

    "parcelLength" => 10,
    "parcelWidth"  => 10,
    "parcelHigh"   => 10,

    "parcelVolume" => 1,
    "parcelValue"  => 100,

    // ==========================
    // Pickup
    // ==========================

    "pickupType"   => 0,
    "pickUpAging"  => 0,
    "prePickUpTime"=> date('Y-m-d H:i:s', strtotime('+1 day')),

    // ==========================
    // Sender
    // ==========================

    "sendAddress"      => "No. 1 Test Street",
    "sendCityName"     => "Lagos",
    "sendCompanyName"  => "SefrelShop",
    "sendCountryCode"  => "NG",
    "sendCountryName"  => "Nigeria",
    "sendMail"         => "support@sefrelshop.com",
    "sendMobile"       => "08011111111",
    "sendName"         => "SefrelShop Warehouse",
    "sendPhone"        => "08011111111",
    "sendProvinceName" => "Lagos",

    // ==========================
    // Platform
    // ==========================

    "platformSource" => $config->get('platformSource'),
    "warehouseCode"  => "GZ01",

    "remark" => "Speedaf API Test",

    // ==========================
    // Item List
    // ==========================

    "itemList" => [

        [

            "sku" => "TEST001",

            "goodsName" => "SefrelShop Test Product",

            "goodsType" => "IT01",

            "goodsQTY" => 1,

            "goodsWeight" => 1,

            "goodsLength" => 10,

            "goodsWidth" => 10,

            "goodsHigh" => 10,

            "goodsValue" => 100,

            "currencyType" => "NGN",

            "unit" => "pcs",

            "battery" => 0,

            "blInsure" => 0

        ]

    ]

];

echo "<h3>Sending Request...</h3>";

$result = $api->post(
    "/open-api/express/order/createOrder",
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

echo "<h3>Decrypted Response</h3>";
echo "<pre>";

if (!empty($result['decrypted'])) {

    $pretty = json_decode($result['decrypted'], true);

    if ($pretty !== null) {

        echo htmlspecialchars(
            json_encode(
                $pretty,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );

    } else {

        echo htmlspecialchars($result['decrypted']);

    }

}

echo "</pre>";