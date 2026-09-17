<?php

namespace Tey\LaravelDDD\Support;

use Illuminate\Console\Command;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Lorisleiva\Lody\Lody;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Builds discovery results without registering them with the application.
 *
 * @internal
 */
class DomainDiscovery
{
    public function providers(Finder $finder): array
    {
        return $this->classes($finder, ServiceProvider::class);
    }

    public function commands(Finder $finder): array
    {
        return $this->classes($finder, Command::class);
    }

    protected function classes(Finder $finder, string $baseClass): array
    {
        return Lody::classesFromFinder($finder)
            ->isNotAbstract()
            ->isInstanceOf($baseClass)
            ->values()
            ->toArray();
    }

    public function listeners(array $paths, string $basePath): array
    {
        DiscoverEvents::guessClassNamesUsing(
            fn (SplFileInfo $file, string $base) => Lody::resolveClassname($file)
        );

        $discoveredEvents = rescue(
            fn () => DiscoverEvents::within($paths, $basePath),
            [],
            false
        );

        DiscoverEvents::$guessClassNamesUsingCallback = null;

        $listeners = [];
        $subscriberCandidates = [];

        collect($discoveredEvents)->each(function (array $eventListeners, string $event) use (&$listeners, &$subscriberCandidates) {
            collect($eventListeners)->each(function (string $listenerMethod) use ($event, &$listeners, &$subscriberCandidates) {
                [$listener, $method] = Str::contains($listenerMethod, '@')
                    ? explode('@', $listenerMethod)
                    : [$listenerMethod, 'handle'];

                $subscriberCandidates[$listener] = true;

                $resolved = $method === 'handle' || $method === '__invoke'
                    ? $listener
                    : [$listener, $method];

                if (! in_array($resolved, $listeners[$event] ?? [], true)) {
                    $listeners[$event][] = $resolved;
                }
            });
        });

        $subscribers = collect(array_keys($subscriberCandidates))
            ->filter(fn (string $class) => rescue(function () use ($class) {
                $method = (new ReflectionClass($class))->getMethod('subscribe');

                return $method->isPublic() && $method->getNumberOfParameters() === 1;
            }, false, false))
            ->values()
            ->toArray();

        return [
            'listeners' => $listeners,
            'subscribers' => $subscribers,
        ];
    }
}
