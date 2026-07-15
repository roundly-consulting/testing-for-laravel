<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigLeaves;

it('flattens nested config into prefixed leaf keys', function (): void {
    $leaves = ConfigLeaves::forFile(configContractFixture('green/config/shop.php'), 'shop');

    expect($leaves)->toEqualCanonicalizing([
        'shop.payments.gateway',
        'shop.payments.currency',
        'shop.allow_store_credit',
    ]);
});

it('treats an empty array and a list as single leaves', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'leaves').'.php';
    file_put_contents($file, "<?php\n\nreturn ['allow' => [], 'hosts' => ['a', 'b']];\n");

    try {
        expect(ConfigLeaves::forFile($file, 'shop'))->toEqualCanonicalizing(['shop.allow', 'shop.hosts']);
    } finally {
        @unlink($file);
    }
});

it('fails when the config file does not return an array', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'leaves').'.php';
    file_put_contents($file, "<?php\n\nreturn 'not-an-array';\n");

    try {
        expect(fn (): mixed => ConfigLeaves::forFile($file, 'shop'))->toThrow(AssertionFailedError::class);
    } finally {
        @unlink($file);
    }
});

it('fails when the config file is missing', function (): void {
    expect(fn (): mixed => ConfigLeaves::forFile(configContractFixture('green/config/missing.php'), 'shop'))
        ->toThrow(AssertionFailedError::class);
});
