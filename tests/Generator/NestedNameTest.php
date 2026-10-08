<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

it('can generate nested objects', function ($type, $configuredNamespace, $nameInput, $expectedNamespace, $expectedPath) {
    if (in_array($type, ['class', 'enum', 'interface', 'trait'])) {
        skipOnLaravelVersionsBelow('11');
    }

    $domainPath = 'src/Domain';
    $domainRoot = 'Domain';

    Config::set('ddd.domain_path', $domainPath);
    Config::set('ddd.domain_namespace', $domainRoot);
    Config::set("ddd.namespaces.{$type}", $configuredNamespace);

    $slug = Str::slug($type);

    $command = "ddd:{$slug} {$nameInput}";

    expect($command)->toGenerateFileWithNamespace($expectedPath, $expectedNamespace);
})->with([
    'model Invoicing:Deep/Nested/SomeModel' => ['model', 'Models', 'Invoicing:Deep/Nested/SomeModel', 'Domain\Invoicing\Models\Deep\Nested', 'src/Domain/Invoicing/Models/Deep/Nested/SomeModel.php'],
    'action Invoicing:Deep/Nested/SomeAction' => ['action', 'Actions', 'Invoicing:Deep/Nested/SomeAction', 'Domain\Invoicing\Actions\Deep\Nested', 'src/Domain/Invoicing/Actions/Deep/Nested/SomeAction.php'],
    'dto Invoicing:Deep/Nested/InvoiceData' => ['dto', 'Data', 'Invoicing:Deep/Nested/InvoiceData', 'Domain\Invoicing\Data\Deep\Nested', 'src/Domain/Invoicing/Data/Deep/Nested/InvoiceData.php'],
    'view_model Invoicing:Deep/Nested/InvoiceViewModel' => ['view_model', 'ViewModels', 'Invoicing:Deep/Nested/InvoiceViewModel', 'Domain\Invoicing\ViewModels\Deep\Nested', 'src/Domain/Invoicing/ViewModels/Deep/Nested/InvoiceViewModel.php'],
    'value_object Invoicing:Deep/Nested/InvoiceNumber' => ['value_object', 'ValueObjects', 'Invoicing:Deep/Nested/InvoiceNumber', 'Domain\Invoicing\ValueObjects\Deep\Nested', 'src/Domain/Invoicing/ValueObjects/Deep/Nested/InvoiceNumber.php'],
    'provider Invoicing:Deep/Nested/InvoiceProvider' => ['provider', 'Providers', 'Invoicing:Deep/Nested/InvoiceProvider', 'Domain\Invoicing\Providers\Deep\Nested', 'src/Domain/Invoicing/Providers/Deep/Nested/InvoiceProvider.php'],
    'exception Deep/Nested/InvoiceNotFoundException' => ['exception', 'Exceptions', 'Invoicing:Deep/Nested/InvoiceNotFoundException', 'Domain\Invoicing\Exceptions\Deep\Nested', 'src/Domain/Invoicing/Exceptions/Deep/Nested/InvoiceNotFoundException.php'],
    'cast Invoicing:Deep/Nested/InvoiceCast' => ['cast', 'Casts', 'Invoicing:Deep/Nested/InvoiceCast', 'Domain\Invoicing\Casts\Deep\Nested', 'src/Domain/Invoicing/Casts/Deep/Nested/InvoiceCast.php'],
    'channel Invoicing:Deep/Nested/InvoiceChannel' => ['channel', 'Channels', 'Invoicing:Deep/Nested/InvoiceChannel', 'Domain\Invoicing\Channels\Deep\Nested', 'src/Domain/Invoicing/Channels/Deep/Nested/InvoiceChannel.php'],
    'command Invoicing:Deep/Nested/InvoiceCommand' => ['command', 'Commands', 'Invoicing:Deep/Nested/InvoiceCommand', 'Domain\Invoicing\Commands\Deep\Nested', 'src/Domain/Invoicing/Commands/Deep/Nested/InvoiceCommand.php'],
    'class Invoicing:Deep/Nested/InvoiceClass' => ['class', '', 'Invoicing:Deep/Nested/InvoiceClass', 'Domain\Invoicing\Deep\Nested', 'src/Domain/Invoicing/Deep/Nested/InvoiceClass.php'],
]);

it('places a namespace-relative name once', function (string $command, string $name, string $path, string $namespace) {
    $this->artisan($command, ['name' => $name, '--domain' => 'Billing'])
        ->assertSuccessful()
        ->execute();

    expect(file_exists(base_path($path)))->toBeTrue()
        ->and(file_get_contents(base_path($path)))->toContain("namespace {$namespace};");
})->with([
    'model' => ['ddd:model', '\\Custom\\Invoice', 'src/Domain/Billing/Custom/Invoice.php', 'Domain\\Billing\\Custom'],
    'class' => ['ddd:class', '\\Custom\\Thing', 'src/Domain/Billing/Custom/Thing.php', 'Domain\\Billing\\Custom'],
]);
