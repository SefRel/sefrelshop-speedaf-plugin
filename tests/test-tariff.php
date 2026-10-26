
<?php

require_once __DIR__ . '/../includes/logistics/class-rate-calculator.php';

$calculator = new RateCalculator();
$passed = 0;
$failed = 0;

function checkSame(
    string $name,
    $expected,
    $actual
): void {
    global $passed, $failed;

    if ($expected === $actual) {
        $passed++;
        echo "PASS: {$name}\n";
        return;
    }

    $failed++;
    echo "FAIL: {$name}\n";
    echo '  Expected: ' . var_export($expected, true) . "\n";
    echo '  Actual:   ' . var_export($actual, true) . "\n";
}

function checkThrows(
    string $name,
    callable $callback
): void {
    global $passed, $failed;

    try {
        $callback();
        $failed++;
        echo "FAIL: {$name} (no exception thrown)\n";
    } catch (InvalidArgumentException $exception) {
        $passed++;
        echo "PASS: {$name}\n";
    }
}

function quote(
    RateCalculator $calculator,
    int $pickupZone,
    int $deliveryZone,
    float $weight
): array {
    return $calculator->calculate([
        'pickup_zone' => $pickupZone,
        'delivery_zone' => $deliveryZone,
        'chargeable_weight_kg' => $weight,
    ]);
}

// Zone selection and published VAT-inclusive rates.
$result = quote($calculator, 1, 3, 0.5);
checkSame('Zone 3, 0.5 kg', 4718, $result['amount']);
checkSame('VAT is included', true, $result['vat_inclusive']);
checkSame('Billable weight is 0.5 kg', 0.5, $result['billable_weight_kg']);

$result = quote($calculator, 3, 1, 1.0);
checkSame('Reverse route uses higher zone', 4979, $result['amount']);

$result = quote($calculator, 3, 3, 1.2);
checkSame('1.2 kg rounds up to 1.5 kg', 5241, $result['amount']);
checkSame('Billable weight rounds up', 1.5, $result['billable_weight_kg']);

$result = quote($calculator, 1, 1, 1.5);
checkSame('Zone 1, 1.5 kg', 3145, $result['amount']);

$result = quote($calculator, 2, 4, 2.0);
checkSame('Zone 4, 2 kg', 6552, $result['amount']);

$result = quote($calculator, 1, 4, 20.0);
checkSame('Zone 4, 20 kg parcel rate', 21508, $result['amount']);

// Bulk rate checks from the supplied tariff.
$result = quote($calculator, 1, 1, 25.0);
checkSame('Zone 1 bulk rate per kg', 655, $result['rate_per_kg']);
checkSame('Zone 1, 25 kg bulk amount', 16375, $result['amount']);

$result = quote($calculator, 1, 3, 75.0);
checkSame('Zone 3, 51–100 kg rate per kg', 736, $result['rate_per_kg']);
checkSame('Zone 3, 75 kg bulk amount', 55200, $result['amount']);

$result = quote($calculator, 1, 4, 150.0);
checkSame('Zone 4, 101–5000 kg rate per kg', 736, $result['rate_per_kg']);
checkSame('Zone 4, 150 kg bulk amount', 110400, $result['amount']);

// Weight boundaries.
$result = quote($calculator, 1, 1, 19.8);
checkSame('19.8 kg rounds to 20 kg', 20.0, $result['billable_weight_kg']);

$result = quote($calculator, 1, 1, 20.1);
checkSame('20.1 kg uses bulk pricing', 'bulk', $result['rate_type']);
checkSame('20.1 kg uses 20–50 kg band', 655, $result['rate_per_kg']);

// Invalid inputs must fail explicitly.
checkThrows('Zero weight rejected', function () use ($calculator) {
    quote($calculator, 1, 1, 0.0);
});

checkThrows('Negative weight rejected', function () use ($calculator) {
    quote($calculator, 1, 1, -1.0);
});

checkThrows('Unsupported zone rejected', function () use ($calculator) {
    quote($calculator, 1, 5, 1.0);
});

checkThrows('Missing weight rejected', function () use ($calculator) {
    $calculator->calculate([
        'pickup_zone' => 1,
        'delivery_zone' => 2,
    ]);
});

checkThrows('Weight above 5000 kg rejected', function () use ($calculator) {
    quote($calculator, 1, 1, 5001.0);
});

// Existing method remains backward-compatible.
$rates = [
    ['provider' => 'speedaf', 'amount' => 4718],
    ['provider' => 'example', 'amount' => 4500],
];

checkSame('compare() remains unchanged', $rates, $calculator->compare($rates));

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
