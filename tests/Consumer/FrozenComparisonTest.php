<?php

use Illuminate\Filesystem\Filesystem;
use Tey\LaravelDDD\Tests\Consumer\Comparison;

it('compares bounded consumer behavior with the frozen v3 source', function () {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ddd-consumer-'.bin2hex(random_bytes(8));
    $mutant = $root.'-candidate';
    $files = new Filesystem;

    try {
        Comparison::archive($root);
        $reference = Comparison::capture($root);
        $candidate = Comparison::capture(dirname(__DIR__, 2));

        expect($reference['provenance']['package_root'])->toBe(realpath($root))
            ->and($candidate['provenance']['package_root'])->toBe(realpath(dirname(__DIR__, 2)))
            ->and($candidate['provenance']['php'])->toBe($reference['provenance']['php'])
            ->and($candidate['provenance']['laravel'])->toBe($reference['provenance']['laravel']);

        foreach ([$reference, $candidate] as $capture) {
            expect(array_column($capture['observations']['commands'], 'exit'))->toBe([0, 0, 0])
                ->and($capture['observations']['files'])->toHaveKey('src/Domain/Comparison/Models/Ledger.php')
                ->and($capture['observations']['files'])->toHaveKey('src/Application/Invoicing/Requests/Billing/StoreInvoiceRequest.php')
                ->and($capture['observations']['discovery']['providers'])->toContain('Domain\\Invoicing\\Providers\\InvoiceServiceProvider')
                ->and($capture['observations']['discovery']['commands'])->toContain('Domain\\Invoicing\\Commands\\InvoiceDeliver')
                ->and($capture['observations']['discovery']['listeners']['listeners'])->toHaveKey('Domain\\Invoicing\\Events\\InvoiceCreated')
                ->and($capture['observations']['discovery']['listeners']['subscribers'])->toContain('Domain\\Invoicing\\Listeners\\InvoiceEventSubscriber');
        }

        expect($candidate['observations'])->toBe($reference['observations']);

        // Prove the comparison can see a candidate-only layout regression. The
        // frozen reference and real checkout are never modified.
        $checkout = dirname(__DIR__, 2);
        foreach (['src', 'config', 'resources', 'database', 'routes', 'stubs'] as $directory) {
            if (is_dir($checkout.'/'.$directory)) {
                $files->copyDirectory($checkout.'/'.$directory, $mutant.'/'.$directory);
            }
        }
        $files->copy($checkout.'/composer.json', $mutant.'/composer.json');
        $config = $files->get($mutant.'/config/ddd.php');
        $changed = str_replace("'class' => ''", "'class' => 'UnexpectedClasses'", $config, $replacements);
        expect($replacements)->toBe(1);
        $files->put($mutant.'/config/ddd.php', $changed);

        $mutated = Comparison::capture($mutant);
        expect($mutated['observations']['files'])->toHaveKey('src/Domain/Comparison/UnexpectedClasses/Probe.php')
            ->and($mutated['observations'])->not->toBe($reference['observations']);
    } finally {
        $files->deleteDirectory($root);
        $files->deleteDirectory($mutant);
        $files->delete($root.'.zip');
    }
});
