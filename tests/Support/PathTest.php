<?php

use Tey\LaravelDDD\Support\Path;

it('resolves artifact paths while preserving absolute roots', function (string $path, string $expected) {
    expect(Path::absolute($path, '/workspace'))->toBe(Path::normalize($expected));
})->with([
    'relative' => ['src/Domain/Billing/Invoice.php', '/workspace/src/Domain/Billing/Invoice.php'],
    'Unix root' => ['/custom/Invoice.php', '/custom/Invoice.php'],
    'Windows root' => ['C:\\custom\\Invoice.php', 'C:/custom/Invoice.php'],
]);
