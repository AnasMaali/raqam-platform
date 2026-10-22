<?php

// The repository is public: every amount a test, browser test or document gives a price is an obviously made-up one, so
// nothing in it can be read as what the platform charges. Real prices are typed by the admin in the panel only.

const MADE_UP_AMOUNTS = [
    // as typed / shown
    '123.45', '99.99', '1234.56', '1,234.56', '111.11', '135.70', '234.56', '9.99', '16.12', '123', '123.4', '123.456',
    // in agorot / cents
    '12345', '9999', '123456', '11111', '13570', '23456', '999', '1612', '12300', '12340', '2345', '10001', '12346', '1975', '14320',
];

/** @return list<string> "file:line amount" for every price-like amount outside the made-up set */
function realLookingPrices(string $root, array $dirs): array
{
    $context = '/(?:price|sale_price|price_amount|sale_price_amount|unit_amount|amount|minPrice|was)[\'"]?\s*(?:=>|=|:)\s*[\'"]?(-?[0-9][0-9.,]*)'
        .'|form\.(?:sale_)?price"\]\'\)\.fill\(\'(-?[0-9][0-9.,]*)\'\)'
        .'|(-?[0-9][0-9.,]*) (?:₪|\$(?!\w)|ILS|USD)/u';
    $found = [];
    foreach ($dirs as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.(php|ts|sql|md|yaml)$/', $file->getFilename()) || $file->getFilename() === basename(__FILE__)) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                preg_match_all($context, $line, $m, PREG_SET_ORDER);
                foreach ($m as $hit) {
                    $amount = ltrim(rtrim(end($hit) ?: '', '.,'), '-');
                    $value = (float) str_replace(',', '', $amount);
                    // Tiny amounts (0, 1, a cent) and amounts far beyond any price (refusal checks) are not prices.
                    if ($amount === '' || $value < 10 || $value >= 1000000 || in_array($amount, MADE_UP_AMOUNTS, true)) {
                        continue;
                    }
                    $found[] = substr($file->getPathname(), strlen($root) + 1).':'.($n + 1).' '.$amount;
                }
            }
        }
    }

    return $found;
}

test('every price in tests, browser tests and documents is a made-up amount', function () {
    $root = dirname(base_path());

    expect(realLookingPrices($root, ['backend/tests', 'e2e/tests', 'e2e/support', 'database/tests', 'docs']))->toBe([]);
});

test('the check notices a price that looks real', function () {
    $dir = storage_path('framework/testing/prices-'.bin2hex(random_bytes(3)));
    mkdir($dir.'/t', 0775, true);
    file_put_contents($dir.'/t/a.php', "saveService(['price' => '777']);\n\$x = ['price_amount' => 77700];\n");
    file_put_contents($dir.'/t/b.ts', "await page.locator('[id=\"form.sale_price\"]').fill('555');\nexpect(text).toBe('888.00 ₪');\n");
    file_put_contents($dir.'/t/c.sql', "INSERT INTO services (price_amount) VALUES (1);  -- amount = 66600\n");
    file_put_contents($dir.'/t/d.md', "price: 123.45 and 99.99 ₪ are fine\n");

    try {
        expect(realLookingPrices($dir, ['t']))->toEqualCanonicalizing(['t/a.php:1 777', 't/a.php:2 77700', 't/b.ts:1 555', 't/b.ts:2 888.00', 't/c.sql:1 66600']);
    } finally {
        array_map('unlink', glob($dir.'/t/*'));
        rmdir($dir.'/t');
        rmdir($dir);
    }
});
