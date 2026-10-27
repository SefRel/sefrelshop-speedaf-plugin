
<?php

require_once __DIR__ . '/../includes/logistics/class-rate-calculator.php';

$calculator = new RateCalculator();
$passed = 0;
$failed = 0;

function checkSame(string $name, $expected, $actual): void
{
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

function checkThrows(string $name, callable $callback): void
{
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

// New agreed tariff: base price plus 7.5% VAT.
$result = quote($calculator, 1, 1, 0.5);
checkSame('Zone 1 base price', 3016, $result['base_amount']);
checkSame('Zone 1 VAT amount', 226, $result['vat_amount']);
checkSame('Zone 1 final price', 3242, $result['amount']);
checkSame('VAT included in returned amount', true, $result['vat_inclusive']);

$result = quote($calculator, 1, 3, 0.5);
checkSame('Higher zone selected', 3, $result['zone']);
checkSame('Zone 3 base price', 6624, $result['base_amount']);
checkSame('Zone 3 final price', 7121, $result['amount']);

$result = quote($calculator, 3, 1, 1.2);
checkSame('1.2 kg rounds to 1.5 kg', 1.5, $result['billable_weight_kg']);
checkSame('1.5 kg Zone 3 base price', 7362, $result['base_amount']);
checkSame('1.5 kg Zone 3 final price', 7914, $result['amount']);

$result = quote($calculator, 1, 4, 10.0);
checkSame('10 kg Zone 4 base price', 18675, $result['base_amount']);
checkSame('10 kg Zone 4 final price', 20076, $result['amount']);

$result = quote($calculator, 2, 4, 9.8);
checkSame('9.8 kg rounds to 10 kg', 10, $result['billable_weight_kg']);

checkThrows('Weight above 10 kg rejected', function () use ($calculator) {
    quote($calculator, 1, 1, 10.1);
});

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

// Existing method remains backward-compatible.
$rates = [
    ['provider' => 'speedaf', 'amount' => 4718],
    ['provider' => 'example', 'amount' => 4500],
];

checkSame('compare() remains unchanged', $rates, $calculator->compare($rates));

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
