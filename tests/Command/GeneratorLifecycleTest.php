<?php

use Illuminate\Console\CommandMutex;
use Illuminate\Console\ManuallyFailedException;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Tey\LaravelDDD\Commands\DomainClassMakeCommand;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Tests\Fixtures\Commands\LifecycleGenerator;

beforeEach(function () {
    $this->generator = new LifecycleGenerator(app(Filesystem::class));
    Artisan::registerCommand($this->generator);
});

it('prepares domain input before a custom handler and preserves container injection and status', function () {
    $status = Artisan::call('ddd:lifecycle', ['name' => 'Billing:CreateInvoice']);

    expect($status)->toBe(17)
        ->and($this->generator->invocations[0])->toMatchArray([
            'name' => 'CreateInvoice',
            'domain' => 'Billing',
            'class' => 'Domain\\Billing\\Lifecycles\\CreateInvoice',
        ])
        ->and($this->generator->invocations[0]['files'])->toBeInstanceOf(Filesystem::class)
        ->and($this->generator->blueprint())->toBeNull();
});

it('resolves fresh context when artisan reuses a command', function () {
    Artisan::call('ddd:lifecycle', ['name' => 'Billing:First']);
    Artisan::call('ddd:lifecycle', ['name' => 'Ignored:Second', '--domain' => 'Shipping']);

    expect(array_column($this->generator->invocations, 'class'))->toBe([
        'Domain\\Billing\\Lifecycles\\First',
        'Domain\\Shipping\\Lifecycles\\Second',
    ])->and($this->generator->blueprint())->toBeNull();
});

it('releases context when a handler throws and can run again', function () {
    $this->generator->callback = function () {
        throw new RuntimeException('Handler failed');
    };

    expect(fn () => Artisan::call('ddd:lifecycle', ['name' => 'Billing:First']))
        ->toThrow(RuntimeException::class, 'Handler failed');

    expect($this->generator->blueprint())->toBeNull();

    $this->generator->callback = null;
    Artisan::call('ddd:lifecycle', ['name' => 'Shipping:Second']);

    expect($this->generator->invocations[1]['class'])->toBe('Domain\\Shipping\\Lifecycles\\Second');
});

it('preserves laravel manual failure handling', function () {
    $this->generator->callback = fn ($command) => $command->fail('Generation refused');

    expect(Artisan::call('ddd:lifecycle', ['name' => 'Billing:First']))->toBe(1)
        ->and(Artisan::output())->toContain('Generation refused')
        ->and($this->generator->blueprint())->toBeNull();
});

it('does not retain context after schema resolution fails', function () {
    DDD::resolveObjectSchemaUsing(function () {
        throw new RuntimeException('Schema failed');
    });

    expect(fn () => Artisan::call('ddd:lifecycle', ['name' => 'Billing:First']))
        ->toThrow(RuntimeException::class, 'Schema failed');

    expect($this->generator->blueprint())->toBeNull()
        ->and($this->generator->invocations)->toBeEmpty();
});

it('preserves laravel manual failures during domain preparation', function () {
    DDD::resolveObjectSchemaUsing(function () {
        throw new ManuallyFailedException('Schema refused');
    });

    expect(Artisan::call('ddd:lifecycle', ['name' => 'Billing:First']))->toBe(1)
        ->and(Artisan::output())->toContain('Schema refused')
        ->and($this->generator->blueprint())->toBeNull()
        ->and($this->generator->invocations)->toBeEmpty();
});

it('prepares once when a custom handler delegates to the parent and retains legacy hooks', function () {
    $command = new class(app(Filesystem::class)) extends DomainClassMakeCommand
    {
        public array $steps = [];

        public function handle()
        {
            $this->steps[] = ['handle', $this->blueprint?->schema->fullyQualifiedName];

            return parent::handle();
        }

        protected function beforeHandle()
        {
            parent::beforeHandle();
            $this->steps[] = ['before', $this->blueprint?->schema->fullyQualifiedName];
        }

        protected function afterHandle()
        {
            $this->steps[] = ['after', $this->blueprint?->schema->fullyQualifiedName];
        }

        public function hasBlueprint(): bool
        {
            return $this->blueprint !== null;
        }
    };

    $resolutions = 0;
    DDD::resolveObjectSchemaUsing(function () use (&$resolutions) {
        $resolutions++;

        return null;
    });
    Artisan::registerCommand($command);

    expect(Artisan::call('ddd:class', ['name' => 'Billing:Invoice']))->toBe(0)
        ->and($command->steps)->toBe([
            ['before', 'Domain\\Billing\\Invoice'],
            ['handle', 'Domain\\Billing\\Invoice'],
            ['after', 'Domain\\Billing\\Invoice'],
        ])
        ->and($resolutions)->toBe(1)
        ->and($command->hasBlueprint())->toBeFalse();
});

it('honors legacy input customization before resolving the blueprint', function () {
    $command = new class(app(Filesystem::class)) extends DomainClassMakeCommand
    {
        protected function beforeHandle()
        {
            $this->input->setOption('domain', 'Shipping');
            parent::beforeHandle();
        }
    };
    Artisan::registerCommand($command);

    expect(Artisan::call('ddd:class', ['name' => 'Billing:Invoice']))->toBe(0)
        ->and(file_exists(base_path('src/Domain/Shipping/Invoice.php')))->toBeTrue()
        ->and(file_exists(base_path('src/Domain/Billing/Invoice.php')))->toBeFalse();
});

it('preserves the existing duplicate-file exit codes', function ($command, $expectedStatus) {
    $arguments = ['name' => 'Billing:Invoice'];

    expect(Artisan::call($command, $arguments))->toBe(0)
        ->and(Artisan::call($command, $arguments))->toBe($expectedStatus)
        ->and(Artisan::output())->toContain('already exists');
})->with([
    'model' => ['ddd:model', 0],
    'class' => ['ddd:class', 1],
]);

it('keeps the parent context while a different domain command runs', function () {
    $this->generator->callback = function ($command) {
        $before = $command->blueprint();
        $status = $command->call('ddd:class', ['name' => 'Shipping:Parcel']);

        expect($command->blueprint())->not->toBeNull()->toBe($before)
            ->and($command->blueprint()->schema->fullyQualifiedName)->toBe('Domain\\Billing\\Lifecycles\\Invoice');

        return $status;
    };

    expect(Artisan::call('ddd:lifecycle', ['name' => 'Billing:Invoice']))->toBe(0)
        ->and(file_exists(base_path('src/Domain/Shipping/Parcel.php')))->toBeTrue()
        ->and($this->generator->blueprint())->toBeNull();
});

it('leaves domain preparation behind laravels isolation gate', function (bool $acquired) {
    $command = new class(app(Filesystem::class)) extends DomainClassMakeCommand implements Isolatable {};
    Artisan::registerCommand($command);

    $mutex = Mockery::mock(CommandMutex::class);
    $mutex->shouldReceive('create')->once()->with($command)->andReturn($acquired);
    if ($acquired) {
        $mutex->shouldReceive('forget')->once()->with($command)->andReturn(true);
    } else {
        $mutex->shouldNotReceive('forget');
    }
    app()->instance(CommandMutex::class, $mutex);

    $resolutions = 0;
    DDD::resolveObjectSchemaUsing(function () use (&$resolutions) {
        $resolutions++;

        return null;
    });

    expect(Artisan::call('ddd:class', ['name' => 'Billing:Invoice', '--isolated' => 23]))
        ->toBe($acquired ? 0 : 23)
        ->and($resolutions)->toBe($acquired ? 1 : 0)
        ->and(file_exists(base_path('src/Domain/Billing/Invoice.php')))->toBe($acquired);
})->with(['acquired' => true, 'already locked' => false]);
