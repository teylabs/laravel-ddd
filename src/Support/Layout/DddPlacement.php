<?php

namespace Tey\LaravelDDD\Support\Layout;

use Illuminate\Container\Container;
use Tey\LaravelDDD\Enums\LayerType;
use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\Layer;
use Tey\LaravelDDD\Support\Path;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Placement\PlacementContext;

/**
 * Conventional placement of Laravel-DDD objects through mod's placement (CompiledLayout::place()).
 *
 * Laravel-DDD keeps its own name grammar (the layer is chosen live per type,
 * `/`-`\`-`.` folding, the `Factory` suffix, "/Name" absolute and "\Name"
 * namespace-relative names); mod decides where the resulting artifact lives.
 */
final class DddPlacement
{
    public function __construct(private readonly DddLayout $layout) {}

    public static function current(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            $container->instance(self::class, new self(DddLayout::current()));
        }

        /** @var self $placement */
        $placement = $container->make(self::class);

        return $placement;
    }

    /**
     * Place an object of a type in a domain.
     *
     * An absolute name ("/Nested/Probe") and a namespace-relative name
     * ("\Nested\Probe") land at the layer root (no type folder); any other
     * name lands in the type's folder, nested folders kept.
     *
     * The layer is resolved live for the type (the application-layer filter
     * is a consumer callback, so it runs exactly once per placement); a
     * caller that already resolved it passes it in.
     */
    public function resolve(string $type, Domain $domain, string $name, bool $absolute = false, ?Layer $layer = null): ResolvedArtifact
    {
        $layer ??= $domain->intendedLayerFor($type);
        $kind = $this->layout->kindIdFor($type, $layer);

        if (! $absolute && $type !== 'migration') {
            // A name that already carries the type's namespace ("Domain\Billing\Models\Invoice", as
            // --model values arrive qualified) is placed as the name below it, not prefixed again.
            $conventional = $this->namespaceIn($type, $layer).'\\';
            $qualified = ltrim(str_replace('/', '\\', $name), '\\');

            if (str_starts_with($qualified, $conventional)) {
                $name = substr($qualified, strlen($conventional));
            }
        }

        if ($absolute || str_starts_with($name, '\\')) {
            $kind = $this->layout->rootKindIdFor($layer, $type);
        }

        return $this->place($kind, $this->normaliseName($type, $name), $this->layout->placementFor($domain, $layer));
    }

    /**
     * The namespace of a type's folder in a layer ("Domain\Billing\Models"):
     * the namespace every object of that type shares before its own nested folders.
     */
    public function namespaceIn(string $type, Layer $layer): string
    {
        $artifact = $this->place($this->layout->kindIdFor($type, $layer), 'probe', $this->contextOf($layer));

        if ($artifact->namespace() !== null) {
            return self::namespaceOf($artifact);
        }

        // A file kind (migrations): the namespace its directory would have under the owning root.
        $directory = dirname($artifact->path());

        foreach ($this->layout->compiled()->roots() as $root) {
            $remainder = $root->isClassRoot() ? $root->pathRemainder($directory.'/probe.php') : null;

            if ($remainder !== null) {
                return rtrim($root->namespace.str_replace('/', '\\', dirname($remainder)), '\\.');
            }
        }

        return '';
    }

    /**
     * Resolve one artifact; timestamped (file) kinds get a placeholder stamp
     * because callers only use their directory and the native creator stamps the file.
     */
    private function place(string $kind, string $name, PlacementContext $context): ResolvedArtifact
    {
        $attributes = [];

        if ($this->layout->compiled()->fileType($kind)->isTimestamped()) {
            $attributes['timestamp'] = '0000_00_00_000000';
        }

        return $this->layout->compiled()->place($kind, $name, $context, $attributes);
    }

    /**
     * The path of a class the layer owns, through mod's reverse mapping, or null when no rule owns it.
     */
    public function pathOfClass(string $fqcn): ?string
    {
        $artifact = $this->layout->compiled()->locate(ltrim($fqcn, '\\'));

        return $artifact !== null ? Path::normalize($artifact->path()) : null;
    }

    /**
     * The namespace of an artifact without its nested folders ("Domain\Billing\Models"
     * for Models\Nested\Probe). For a namespace-relative input ("\Custom\Invoice") the
     * folders are the namespace the caller asked for, so they stay in it.
     */
    public static function namespaceOf(ResolvedArtifact $artifact, bool $namespaceRelative = false): string
    {
        if ($namespaceRelative) {
            return (string) $artifact->namespace();
        }

        $fqcn = (string) $artifact->fqcn();
        $nested = str_replace('/', '\\', $artifact->nestedName());

        return rtrim(substr($fqcn, 0, strlen($fqcn) - strlen($nested)), '\\');
    }

    /**
     * The nested name in namespace form ("Nested\Probe"), or the basename for a namespace-relative input.
     */
    public static function nameOf(ResolvedArtifact $artifact, bool $namespaceRelative = false): string
    {
        return $namespaceRelative ? $artifact->name : str_replace('/', '\\', $artifact->nestedName());
    }

    /**
     * Whether a name is namespace-relative to the layer root ("\Custom\Invoice").
     */
    public static function isNamespaceRelative(string $name): bool
    {
        return str_starts_with($name, '\\');
    }

    /**
     * The placement context a layer stands for: its domain path below the
     * domain or application root, none for a custom layer.
     */
    public function contextOf(Layer $layer): PlacementContext
    {
        if ($layer->type === LayerType::Custom) {
            return PlacementContext::none();
        }

        foreach ($this->layout->compiled()->roots() as $root) {
            if (! $root->isClassRoot()) {
                continue;
            }

            $remainder = $root->namespaceRemainder(Path::normalizeNamespace((string) $layer->namespace).'\\Probe');

            if ($remainder !== null && $remainder !== 'Probe') {
                $segments = explode('\\', $remainder);
                array_pop($segments);

                return PlacementContext::of([DddLayout::DIMENSION => implode('/', $segments)]);
            }
        }

        return PlacementContext::none();
    }

    private function normaliseName(string $type, string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');

        if ($type === 'factory' && ! str_ends_with($name, 'Factory')) {
            $name .= 'Factory';
        }

        return $name;
    }
}
