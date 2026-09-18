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

    /**
     * Discover listeners and subscribers under the given paths.
     *
     * The framework's DiscoverEvents::within() scans the paths with a Finder of
     * its own and offers no hook to narrow it, so the caller's finder cannot be
     * handed down. It is applied to the result instead: whatever the finder
     * admits is the set allowed to register, which is how ddd.autoload_ignore,
     * a custom autoload filter and a subclassed finder reach listener discovery
     * at all. Passing no finder keeps the unfiltered behaviour.
     */
    public function listeners(array $paths, string $basePath, ?Finder $finder = null): array
    {
        // DiscoverEvents::$guessClassNamesUsingCallback is process-global framework
        // state a consumer may also have set. Borrow it for the scan and hand back
        // whatever was there before, rather than clearing someone else's callback.
        $previousCallback = DiscoverEvents::$guessClassNamesUsingCallback;

        DiscoverEvents::guessClassNamesUsing(
            fn (SplFileInfo $file, string $base) => Lody::resolveClassname($file)
        );

        try {
            $discoveredEvents = rescue(
                fn () => DiscoverEvents::within($paths, $basePath),
                [],
                false
            );
        } finally {
            DiscoverEvents::$guessClassNamesUsingCallback = $previousCallback;
        }

        if ($finder !== null) {
            $discoveredEvents = $this->onlyAllowedListeners($discoveredEvents, $finder);
        }

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

    /**
     * Drop discovered handlers whose class the finder did not admit.
     *
     * Subscribers need no separate pass: they are derived from this same set, so
     * a class filtered out here can never become a subscriber either.
     */
    protected function onlyAllowedListeners(array $discoveredEvents, Finder $finder): array
    {
        // Resolved with the same callback the scan itself uses, so the names
        // being compared are produced identically on both sides.
        $allowed = collect(iterator_to_array($finder, false))
            ->map(fn (SplFileInfo $file) => Lody::resolveClassname($file))
            ->filter()
            ->flip();

        return collect($discoveredEvents)
            ->map(fn (array $eventListeners) => array_values(array_filter(
                $eventListeners,
                fn (string $listenerMethod) => $allowed->has(Str::before($listenerMethod, '@')),
            )))
            ->filter()
            ->all();
    }

    public static function withoutSubscriberListeners(array $listeners, array $subscribers): array
    {
        return collect($listeners)->map(fn (array $eventListeners) => array_values(array_filter(
            $eventListeners,
            function ($listener) use ($subscribers) {
                $class = is_array($listener)
                    ? $listener[0]
                    : (is_string($listener) ? Str::before($listener, '@') : null);

                return ! in_array($class, $subscribers, true);
            }
        )))->filter()->all();
    }
}
