<?php

namespace Tey\LaravelDDD\Support\Layout;

use Illuminate\Container\Container;
use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Path;
use Tey\LaravelDDD\ValueObjects\DomainObject;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\ModException;

/**
 * Maps existing classes back to the Laravel-DDD layout through mod's
 * reverse mapping (CompiledLayout::locate()): which domain, layer and object type a class is, and the
 * factory or policy that goes with a model.
 *
 * Seam: current(), objectFromClass(), factoryFor(), policyFor().
 */
final class DddReverse
{
    public function __construct(private readonly DddLayout $layout) {}

    public static function current(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            $container->instance(self::class, new self(DddLayout::current()));
        }

        /** @var self $reverse */
        $reverse = $container->make(self::class);

        return $reverse;
    }

    /**
     * The domain object a class is, or null when no root of the layout owns
     * it (or when it is not of the requested type).
     *
     * `name` keeps the folders below the type folder as namespace segments
     * ("Archived\Invoice"), `namespace` is the type folder as configured
     * ("Models", "Database\Factories", '' for objects at the layer root),
     * `domain` is the domain with its subdomains ("Invoicing\Billing"; the
     * layer key for a custom layer).
     *
     * By default only the domain root is considered, as v3 did; pass
     * `anyRoot` to map application-layer and custom-layer classes too.
     */
    public function objectFromClass(string $fqcn, ?string $type = null, bool $anyRoot = false): ?DomainObject
    {
        $artifact = $this->layout->compiled()->locate(ltrim($fqcn, '\\'));

        if ($artifact === null) {
            return null;
        }

        if (! $anyRoot && $this->layout->rootOf($artifact->fileType->id) !== 'domain') {
            return null;
        }

        $root = str_ends_with($artifact->fileType->id, '-root');

        // v3 (DomainObject::fromClass): a requested type WITH a configured namespace is the only
        // namespace tried; a class elsewhere is then described as a root-level object of that type.
        if ($type !== null && filled(config("ddd.namespaces.{$type}"))) {
            return $this->layout->placesType($artifact->fileType->id, $type) ? $this->describe($artifact, $type) : $this->rootLevel($artifact, $type);
        }

        // v3: no (or an unconfigured) requested type scans every configured namespace, first match
        // wins; an object below the layer root with no type folder keeps the requested type, if any.
        return $this->describe($artifact, $root ? $type : $this->layout->typeOf($artifact->fileType->id));
    }

    /**
     * v3's description of a class that is not in the requested type's folder: everything below the
     * domain is the name, the object namespace is empty and the type is the one requested.
     */
    private function rootLevel(ResolvedArtifact $artifact, string $type): DomainObject
    {
        $fqcn = (string) $artifact->fqcn();
        $domain = $artifact->context->get(DddLayout::DIMENSION);
        $name = $fqcn;

        foreach ($this->layout->compiled()->roots() as $root) {
            $remainder = $root->isClassRoot() ? $root->namespaceRemainder($fqcn) : null;

            if ($remainder !== null) {
                $name = $domain === null ? $remainder : substr($remainder, strlen(str_replace('/', '\\', $domain)) + 1);

                break;
            }
        }

        return new DomainObject(
            name: $name,
            domain: $domain !== null ? str_replace('/', '\\', $domain) : $this->customLayerKey($artifact),
            namespace: '',
            fullyQualifiedName: $fqcn,
            path: Path::normalize($artifact->path()),
            type: $type,
        );
    }

    /**
     * The factory class of a domain model: the one the layout places beside
     * the model when it exists, else the conventional Database\Factories
     * fallback, or null when the class is not a domain model.
     */
    public function factoryFor(string $model, string $fallbackNamespace = 'Database\\Factories\\'): ?string
    {
        $resolved = $this->related($model, 'factory', 'Factory');

        if ($resolved === null) {
            return null;
        }

        [$object, $factory] = $resolved;

        if (class_exists($factory)) {
            return $factory;
        }

        return $fallbackNamespace.$object->domain.'\\'.$object->name.'Factory';
    }

    /**
     * The policy class the layout places for a domain model, or null when the class is not a domain model.
     */
    public function policyFor(string $model): ?string
    {
        return $this->related($model, 'policy', 'Policy')[1] ?? null;
    }

    /**
     * @return array{0: DomainObject, 1: string}|null
     */
    private function related(string $model, string $type, string $suffix): ?array
    {
        $artifact = $this->layout->compiled()->locate(ltrim($model, '\\'));

        if ($artifact === null || $this->layout->typeOf($artifact->fileType->id) !== 'model') {
            return null;
        }
        $object = $this->describe($artifact, 'model');
        $layer = DomainResolver::resolveLayer(str_replace('/', '\\', $artifact->context->get(DddLayout::DIMENSION) ?? $object->domain), $type);

        if ($layer === null) {
            return null;
        }

        try {
            $target = $this->layout->compiled()->place(
                $this->layout->kindIdFor($type, $layer),
                implode('/', [...$artifact->nested, $artifact->name.$suffix]),
                $this->layout->placementFor(new Domain(str_replace('/', '.', $artifact->context->get(DddLayout::DIMENSION) ?? $object->domain)), $layer),
            );
        } catch (ModException) {
            return null;
        }

        return [$object, (string) $target->fqcn()];
    }

    private function describe(ResolvedArtifact $artifact, ?string $type): DomainObject
    {
        $context = $artifact->context->get(DddLayout::DIMENSION);
        $domain = $context !== null ? str_replace('/', '\\', $context) : $this->customLayerKey($artifact);
        $namespace = $type === null ? '' : Path::normalizeNamespace(DomainResolver::getRelativeObjectNamespace($type));
        $namespace = trim($namespace, '\\');

        return new DomainObject(
            name: implode('\\', [...$artifact->nested, $artifact->name]),
            domain: $domain,
            namespace: $namespace,
            fullyQualifiedName: (string) $artifact->fqcn(),
            path: Path::normalize($artifact->path()),
            type: $type,
        );
    }

    private function customLayerKey(ResolvedArtifact $artifact): string
    {
        $namespace = (string) $artifact->namespace();

        foreach ((array) config('ddd.layers', []) as $key => $path) {
            if (is_string($key) && is_string($path) && (Path::normalizeNamespace($key) === $namespace || str_starts_with($namespace, Path::normalizeNamespace($key).'\\'))) {
                return $key;
            }
        }

        return explode('\\', $namespace)[0];
    }
}
