
<?php

/**
 * Standalone tests for Speedaf tariff CSV parsing.
 *
 * Run:
 * php tests/test-tariff-csv.php
 */

// Minimal WordPress guard stub.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

require_once __DIR__ . '/../includes/class-speedaf-tariff-manager.php';

$manager = new SpeedafTariffManager();

$parser = new ReflectionMethod(
    SpeedafTariffManager::class,
    'parseTariffCsv'
);
$parser->setAccessible(true);

$testsPassed = 0;
$testsFailed = 0;

function csvAssert(bool $condition, string $description): void
{
    global $testsPassed, $testsFailed;

    if ($condition) {
        echo "PASS: {$description}\n";
        $testsPassed++;
    } else {
        echo "FAIL: {$description}\n";
        $testsFailed++;
    }
}

function makeTariffCsv(array $rows, ?array $header = null): string
{
    $header ??= [
        'Weight (kg)',
        'Zone 1 Base Price (NGN, excl VAT)',
        'Zone 2 Base Price (NGN, excl VAT)',
        'Zone 3 Base Price (NGN, excl VAT)',
        'Zone 4 Base Price (NGN, excl VAT)',
    ];

    $path = tempnam(sys_get_temp_dir(), 'speedaf-csv-');

    if ($path === false) {
        throw new RuntimeException('Could not create temporary CSV.');
    }

    $handle = fopen($path, 'w');

    if ($handle === false) {
        throw new RuntimeException('Could not open temporary CSV.');
    }

    // Match the export format, including UTF-8 BOM.
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $header, ',', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '\\');
    }

    fclose($handle);

    return $path;
}

function standardTariffRows(): array
{
    $rows = [];

    for ($unit = 1; $unit <= 20; $unit++) {
        $rows[] = [
            number_format($unit / 2, 1, '.', ''),
            1000 + $unit,
            2000 + $unit,
            3000 + $unit,
            4000 + $unit,
        ];
    }

    return $rows;
}


function parserAccepts(
    ReflectionMethod $parser,
    SpeedafTariffManager $manager,
    string $path
): bool {
    try {
        $parser->invoke($manager, $path);
        return true;
    } catch (Throwable $exception) {
        echo "  Parser error: " . $exception->getMessage() . "\n";
        return false;
    }
}


function runCsvCase(
    ReflectionMethod $parser,
    SpeedafTariffManager $manager,
    array $rows,
    bool $shouldPass,
    string $description,
    ?array $header = null
): void {
    $path = makeTariffCsv($rows, $header);

    try {
        $accepted = parserAccepts($parser, $manager, $path);
        csvAssert($accepted === $shouldPass, $description);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

// 1. Valid complete tariff.
$validRows = standardTariffRows();

runCsvCase(
    $parser,
    $manager,
    $validRows,
    true,
    'Valid 20-band CSV is accepted'
);

// 2. Missing row.
$rows = $validRows;
array_pop($rows);

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'CSV missing a weight band is rejected'
);

// 3. Out-of-order bands.
$rows = $validRows;
[$rows[0], $rows[1]] = [$rows[1], $rows[0]];



runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Out-of-order weight bands are rejected'
);

// 4. Duplicate weight band.
$rows = $validRows;
$rows[1][0] = $rows[0][0];

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Duplicate weight band is rejected'
);

// 5. Incorrect header.
$badHeader = [
    'Weight',
    'Zone 1',
    'Zone 2',
    'Zone 3',
    'Zone 4',
];

runCsvCase(
    $parser,
    $manager,
    $validRows,
    false,
    'Incorrect CSV header is rejected',
    $badHeader
);

// 6. Decimal price.
$rows = $validRows;
$rows[0][1] = '1234.50';

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Decimal price is rejected'
);

// 7. Zero price.
$rows = $validRows;
$rows[0][1] = '0';

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Zero price is rejected'
);

// 8. Excessively high price.
$rows = $validRows;
$rows[0][1] = '100000001';

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Price above permitted maximum is rejected'
);

// 9. Non-numeric price.
$rows = $validRows;
$rows[0][1] = 'invalid';

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Non-numeric price is rejected'
);

// 10. Extra data row.
$rows = $validRows;
$rows[] = ['10.5', 1000, 2000, 3000, 4000];

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Unexpected extra data row is rejected'
);

// 11. Missing zone price.
$rows = $validRows;
array_pop($rows[0]);

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Row missing a zone price is rejected'
);

// 12. Non-half-kilogram weight.
$rows = $validRows;
$rows[0][0] = '0.6';

runCsvCase(
    $parser,
    $manager,
    $rows,
    false,
    'Unsupported weight increment is rejected'
);

// 13. A trailing blank line should be accepted.
$path = makeTariffCsv($validRows);

try {
    $handle = fopen($path, 'a');

    if ($handle !== false) {
        fwrite($handle, "\n");
        fclose($handle);
    }

    csvAssert(
        parserAccepts($parser, $manager, $path),
        'Trailing blank line is accepted'
    );
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}

echo "\nResults: {$testsPassed} passed, {$testsFailed} failed.\n";

exit($testsFailed === 0 ? 0 : 1);
