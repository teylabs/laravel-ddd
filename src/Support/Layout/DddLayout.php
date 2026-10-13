<?php

namespace Tey\LaravelDDD\Support\Layout;

use Composer\Autoload\ClassLoader;
use Illuminate\Container\Container;
use Tey\LaravelDDD\Enums\LayerType;
use Tey\LaravelDDD\Facades\DDD;
use Tey\LaravelDDD\Support\Domain;
use Tey\LaravelDDD\Support\DomainResolver;
use Tey\LaravelDDD\Support\Layer;
use Tey\LaravelDDD\Support\Path;
use Tey\LaravelDDD\ValueObjects\ObjectSchema;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\CompiledRoot;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Placement\PlacementContext;

/**
 * The Laravel-DDD configuration (config/ddd.php) compiled into one mod layout.
 *
 * Roots: `domain` (domain_namespace → domain_path), `application`
 * (application_namespace → application_path) and `layer-<n>` per
 * string-valued custom layer. One placement dimension, `{domain+}`, carries
 * the domain and its subdomains ("Invoicing/Billing").
 *
 * It extends mod's built-in `ddd` layout, which ships Laravel-DDD's defaults:
 * a type's home root (application for application_objects, domain
 * otherwise) uses mod's kind id (`model`, `dto`, `controller`, …) and mod's
 * relation ids (`model-factory`, `controller-store-request`, …), so with the
 * default configuration ddd:* and mod:* place files alike. Every other root
 * gets its own kind per type (`application-model`, `domain-controller`,
 * `layer-1-model`, …) placed at `{domain+}/<namespaces.type>` (custom layers
 * have no domain folder); types without a folder share the root's catch-all
 * kind (`domain-root`). Kinds and relations of mod's layout that Laravel-DDD
 * has no type for stay (`test`, `job-middleware`, below every other kind) or
 * are switched off (relations, mode none). A configuration the extension
 * cannot express (an empty domain or application namespace, or the two
 * roots sharing a namespace or path) compiles standalone instead, under the
 * name `laravel-ddd`. Kinds accept nested names and declare no mod:* command;
 * the discovered types are declared `discoverAnywhere: true` with
 * no `except` (the discovery candidate source applies ddd.autoload_ignore
 * and the DDD::filterAutoloadPathsUsing hook itself, with v3 semantics).
 *
 * The layout is compiled lazily, cached per application, and recompiled when
 * the relevant configuration changes or an unseen object type is requested,
 * because Laravel-DDD reads its configuration live. Which root a type goes
 * to (application precedence, custom layer) stays Laravel-DDD's decision:
 * callers resolve the Layer with DomainResolver and ask kindIdFor().
 *
 * Seam: current(), compiled(), kindIds(), kindIdFor(),
 * rootKindIdFor(), discoveryRoots(), withDiscoveryPaths(),
 * unmappedDiscoveryPaths(), placementFor(), artifactFromSchema(), typeOf().
 */
final class DddLayout
{
    /** The built-in mod layout Laravel-DDD extends. */
    public const EXTENDS = 'ddd';

    /** The layout's name when the configuration cannot extend mod's `ddd` layout. */
    public const NAME = 'laravel-ddd';

    /** The kind id of each object type in mod's `ddd` layout (its home root). */
    public const MOD_KINDS = [
        'model' => 'model', 'data_transfer_object' => 'dto', 'value_object' => 'value-object', 'view_model' => 'view-model',
        'action' => 'action', 'cast' => 'cast', 'channel' => 'channel', 'command' => 'command', 'enum' => 'enum',
        'event' => 'event', 'exception' => 'exception', 'factory' => 'factory', 'job' => 'job', 'listener' => 'listener',
        'mail' => 'mail', 'migration' => 'migration', 'notification' => 'notification', 'observer' => 'observer',
        'policy' => 'policy', 'provider' => 'provider', 'resource' => 'resource', 'rule' => 'rule', 'scope' => 'scope',
        'seeder' => 'seeder', 'class' => 'class', 'interface' => 'interface', 'trait' => 'trait',
        'controller' => 'controller', 'request' => 'request', 'middleware' => 'middleware',
    ];

    /**
     * Laravel-DDD's own stub for the kinds mod's ddd layout gives a starter stub,
     * so mod never chooses a starter variant or writes a starter base for them.
     */
    private const STUBS = ['dto' => 'dto.stub', 'view-model' => 'view-model.stub', 'value-object' => 'value-object.stub', 'action' => 'action.stub'];

    /** Kinds of mod's `ddd` layout with no Laravel-DDD type: kept, below every other kind. */
    private const MOD_ONLY_KINDS = ['job-middleware', 'test', 'component', 'mcp-server', 'mcp-tool', 'mcp-resource', 'mcp-prompt'];

    public const DIMENSION = 'domain';

    /** The object types every compiled layout declares, whatever the configuration lists. */
    public const TYPES = [
        'model', 'data_transfer_object', 'view_model', 'value_object', 'action', 'cast', 'class', 'channel',
        'command', 'controller', 'enum', 'event', 'exception', 'factory', 'interface', 'job', 'listener', 'mail',
        'middleware', 'migration', 'notification', 'observer', 'policy', 'provider', 'resource', 'request', 'rule',
        'scope', 'seeder', 'trait', 'subscriber',
    ];

    /** Discovered as classes anywhere below their domain folder. */
    private const DISCOVERED = ['provider', 'command', 'listener', 'subscriber'];

    /** The ddd:* generator of each object type; types without one (subscriber, consumer-configured) have none. */
    private const COMMANDS = [
        'model' => 'ddd:model', 'data_transfer_object' => 'ddd:dto', 'view_model' => 'ddd:view-model', 'value_object' => 'ddd:value',
        'action' => 'ddd:action', 'cast' => 'ddd:cast', 'class' => 'ddd:class', 'channel' => 'ddd:channel', 'command' => 'ddd:command',
        'controller' => 'ddd:controller', 'enum' => 'ddd:enum', 'event' => 'ddd:event', 'exception' => 'ddd:exception',
        'factory' => 'ddd:factory', 'interface' => 'ddd:interface', 'job' => 'ddd:job', 'listener' => 'ddd:listener', 'mail' => 'ddd:mail',
        'middleware' => 'ddd:middleware', 'migration' => 'ddd:migration', 'notification' => 'ddd:notification', 'observer' => 'ddd:observer',
        'policy' => 'ddd:policy', 'provider' => 'ddd:provider', 'resource' => 'ddd:resource', 'request' => 'ddd:request', 'rule' => 'ddd:rule',
        'scope' => 'ddd:scope', 'seeder' => 'ddd:seeder', 'trait' => 'ddd:trait',
    ];

    private ?CompiledLayout $compiled = null;

    private ?string $fingerprint = null;

    /** @var array<string, true> object types requested beyond TYPES and the configured namespaces */
    private array $extraTypes = [];

    /** @var array<string, string> root name => layer key ('' for domain/application) */
    private array $rootLayers = [];

    /** @var array<string, array{0: string, 1: string}> declared kind id => [root name, first type it generates] */
    private array $kindTypes = [];

    /** @var array<string, array<string, string>> root name => type => kind id (types sharing a folder share a kind) */
    private array $typeKinds = [];

    /** @var array<string, array<string, string>> discovery-only root => type => kind id, from the last withDiscoveryPaths() */
    private array $pathKinds = [];

    /** @var list<string> problems the last compilation worked around (duplicate layer namespaces/paths, empty namespaces) */
    private array $issues = [];

    /** Whether the last compilation extends mod's ddd layout (else it is standalone). */
    private bool $extended = false;

    /** @var list<string>|null */
    private static ?array $modRelationIds = null;

    /** @var array<string, string> root name => configuration key, for roots left out because their namespace is empty */
    private array $emptyRoots = [];

    /**
     * The layout of the current application, compiled on first use.
     */
    public static function current(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            $container->instance(self::class, new self);
        }

        /** @var self $layout */
        $layout = $container->make(self::class);

        return $layout;
    }

    /**
     * The compiled layout for the live configuration.
     *
     * @throws InvalidLayout when the configuration cannot be a layout (empty namespaces, escaping paths)
     */
    public function compiled(): CompiledLayout
    {
        $fingerprint = $this->fingerprint();

        if ($this->compiled === null || $this->fingerprint !== $fingerprint) {
            $this->compileLayout($this->layout());
            $this->fingerprint = $fingerprint;
        }

        /** @var CompiledLayout $compiled */
        $compiled = $this->compiled;

        return $compiled;
    }

    /**
     * Problems the last compilation worked around: a custom layer whose
     * namespace or path duplicates another root is left out of the layout.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        $this->compiled();

        return $this->issues;
    }

    /**
     * Every kind id for an object type, one per root (domain, application, custom layers).
     *
     * @return list<string>
     */
    public function kindIds(string $type): array
    {
        $this->requireType($type);
        $ids = [];

        foreach ([...$this->typeKinds, ...$this->pathKinds] as $kinds) {
            if (isset($kinds[$type])) {
                $ids[] = $kinds[$type];
            }
        }

        return $ids;
    }

    /**
     * The kind id for an object type in its home root (application for
     * application_objects, domain otherwise), with no domain resolved yet.
     */
    public function homeKindIdFor(string $type): string
    {
        $this->requireType($type);
        $root = $this->homeRoot($type);

        return $this->typeKinds[$root][$type] ?? throw InvalidLayoutUse::noKind($type, $root);
    }

    /**
     * The kind id for an object type in a resolved layer.
     */
    public function kindIdFor(string $type, Layer $layer): string
    {
        $this->requireType($type);

        if ($type === 'migration' && $layer->type !== LayerType::Custom) {
            $root = $layer->type === LayerType::Application ? 'application' : 'domain';

            if (isset($this->emptyRoots[$root])) {
                return $this->typeKinds[$root][$type];
            }
        }

        $root = $this->rootFor($layer);

        return $this->typeKinds[$root][$type] ?? throw InvalidLayoutUse::noKind($type, $root);
    }

    /**
     * The catch-all kind of a layer's root: objects placed at the layer root ("/Name" input).
     */
    public function rootKindIdFor(Layer $layer, ?string $type = null): string
    {
        $this->compiled();

        if ($type === 'migration' && $layer->type !== LayerType::Custom) {
            $root = $layer->type === LayerType::Application ? 'application' : 'domain';

            if (isset($this->emptyRoots[$root])) {
                return $root.'-migrations-root';
            }
        }

        return $this->rootFor($layer).'-root';
    }

    /**
     * The ddd:* command that generates an object type, or null when there is none.
     *
     * mod requires command names to be unique per kind, so in the compiled
     * layout only the type's home root (application for application_objects,
     * domain otherwise) carries it; kinds of the other roots have none and a
     * caller dispatching by kind falls back to this map through typeOf().
     */
    public function commandFor(string $type): ?string
    {
        return self::COMMANDS[$type] ?? null;
    }

    /**
     * The object type a kind id generates ('model', 'data_transfer_object', …), or null for a root kind.
     */
    public function typeOf(string $kindId): ?string
    {
        $this->compiled();

        if (str_starts_with($kindId, 'path-')) {
            return isset($this->pathKinds[substr($kindId, 0, -5)]) ? self::DISCOVERED[0] : null;
        }

        return $this->kindTypes[$kindId][1] ?? null;
    }

    /**
     * Whether a kind places classes in a type's folder: one of the type's own
     * kinds, or another type's kind for the same folder of the same root
     * (types sharing a folder each have a kind, and reverse mapping finds the
     * one configured first).
     */
    public function placesType(string $kindId, string $type): bool
    {
        if (in_array($kindId, $this->kindIds($type), true)) {
            return true;
        }

        [$root, $owner] = $this->kindTypes[$kindId] ?? [null, null];

        return $owner !== null && isset($this->typeKinds[$root][$type]) && $this->folder($owner) === $this->folder($type);
    }

    /**
     * The root a kind id belongs to ('domain', 'application', 'layer-<n>'), or null for a kind Laravel-DDD did not declare.
     */
    public function rootOf(string $kindId): ?string
    {
        $this->compiled();

        return $this->kindTypes[$kindId][0] ?? (str_ends_with($kindId, '-root') && isset($this->rootLayers[substr($kindId, 0, -5)]) ? substr($kindId, 0, -5) : null);
    }

    /**
     * The placement context for a parsed domain: none in a custom layer, the
     * domain with its subdomains otherwise ("Invoicing/Billing").
     */
    public function placementFor(Domain $domain, Layer $layer): PlacementContext
    {
        if ($layer->type === LayerType::Custom) {
            return PlacementContext::none();
        }

        return PlacementContext::of([self::DIMENSION => str_replace('\\', '/', $domain->domainWithSubdomain)]);
    }

    /**
     * The roots discovery scans, in v3 order: domain, application, then the custom layers in configuration order.
     *
     * @return list<CompiledRoot>
     */
    public function discoveryRoots(): array
    {
        $compiled = $this->compiled();
        $roots = [];

        foreach (['domain', 'application', ...array_keys(array_filter($this->rootLayers, static fn (string $key): bool => $key !== ''))] as $name) {
            if (isset($compiled->roots()[$name])) {
                $roots[] = $compiled->roots()[$name];
            }
        }

        return $roots;
    }

    /**
     * The layout plus one discovery-only root per manual autoload path
     * (ddd.autoload.* given as an array of paths), with the PSR-4 prefix
     * Composer maps for that path. Paths without a prefix are left out; see
     * unmappedDiscoveryPaths(). Duplicate paths map to one root.
     *
     * Each such root `path-<n>` has one catch-all kind `path-<n>-root`,
     * discovered anywhere below it as every discovered type; after this call
     * kindIds('provider'|'command'|'listener'|'subscriber') include it, so a
     * discovery built from the returned layout and kindIds() scans the manual
     * paths too. The regular compiled() is unaffected.
     *
     * @param  list<string>  $paths  absolute or base-relative directories
     */
    public function withDiscoveryPaths(array $paths): CompiledLayout
    {
        $this->compiled();
        $layout = $this->layout();
        $this->pathKinds = [];
        $index = 0;

        foreach ($this->discoveryRootsFor($paths) as [$namespace, $relative]) {
            $index++;
            $name = 'path-'.$index;
            $layout->mounts($name, $namespace, $relative);
            $layout->generates($name.'-root', in: $name.':', nested: true, command: false, discover: 'anywhere', discoverExcept: []);

            foreach (self::DISCOVERED as $type) {
                $this->pathKinds[$name][$type] = $name.'-root';
            }
        }

        return $layout->compiled();
    }

    /**
     * Manual autoload paths no Composer PSR-4 prefix covers, so discovery cannot resolve their classes.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function unmappedDiscoveryPaths(array $paths): array
    {
        $unmapped = [];

        foreach (array_unique($paths) as $path) {
            if ($this->psr4For($path) === null) {
                $unmapped[] = $path;
            }
        }

        return $unmapped;
    }

    /**
     * A resolved artifact for a schema a consumer's resolveObjectSchemaUsing
     * callback returned: the callback's identity, verbatim, as the kind it
     * generates.
     */
    public function artifactFromSchema(ObjectSchema $schema, string $type, Layer $layer): ResolvedArtifact
    {
        $namespace = trim(Path::normalizeNamespace($schema->namespace), '\\');

        return ResolvedArtifact::phpClass($this->kindIdFor($type, $layer), $namespace, class_basename($schema->fullyQualifiedName), $schema->path);
    }

    /**
     * Compile a fresh layout for the live configuration. It has its own name:
     * mod 0.1.0 ships a built-in `ddd` layout, and asking the registry for
     * that name would start from its definition.
     */
    private function layout(): Layout
    {
        $this->rootLayers = [];
        $this->kindTypes = [];
        $this->typeKinds = [];
        $this->issues = [];

        $roots = [
            'domain' => [$this->namespace(config('ddd.domain_namespace')), $this->path(config('ddd.domain_path')), ''],
            'application' => [$this->namespace(config('ddd.application_namespace')), $this->path(config('ddd.application_path')), ''],
        ];

        // An empty namespace cannot be a PSR-4 root. The other roots still work; an object that
        // needs this one is refused with the configuration key (rootFor), not a cascade of kind errors.
        $this->emptyRoots = [];

        foreach (['domain' => 'ddd.domain_namespace', 'application' => 'ddd.application_namespace'] as $name => $key) {
            if ($roots[$name][0] === '\\') {
                $this->emptyRoots[$name] = $key;
                $this->issues[] = InvalidLayoutUse::emptyNamespace($key)->getMessage();
                unset($roots[$name]);
            }
        }

        $index = 0;

        foreach ((array) config('ddd.layers', []) as $key => $path) {
            if (! is_string($path) || ! is_string($key)) {
                continue; // v3: non-string layer paths are not layers
            }

            $index++;
            $roots['layer-'.$index] = [$this->namespace($key), $this->path($path), $key];
        }

        // mod's ddd layout declares both roots; a configuration without either, or with the two
        // sharing a namespace or path, cannot extend it and compiles standalone.
        $this->extended = isset($roots['domain'], $roots['application'])
            && $roots['domain'][0] !== $roots['application'][0]
            && $roots['domain'][1] !== $roots['application'][1];
        $layout = Layout::fresh($this->extended ? self::EXTENDS : self::NAME)->withoutCommands();

        if ($this->extended) {
            $inherited = $layout->compiled();

            foreach (self::MOD_ONLY_KINDS as $kind) {
                if ($inherited->hasFileType($kind)) {
                    $layout->generates($kind, priority: -100);
                }
            }
        }

        $namespaces = [];
        $paths = [];

        foreach ($roots as $name => [$namespace, $path, $key]) {
            if (isset($namespaces[$namespace]) || isset($paths[$path])) {
                $this->issues[] = sprintf(
                    'Layer [%s] (%s → %s) duplicates root [%s] and is not part of the layout.',
                    $key === '' ? $name : $key,
                    $namespace,
                    $path,
                    $namespaces[$namespace] ?? $paths[$path],
                );

                continue;
            }

            $namespaces[$namespace] = $name;
            $paths[$path] = $name;
            $this->rootLayers[$name] = $key;
            $layout->mounts($name, $namespace, $path);
        }

        // Both inherited and standalone layouts name groups under the host's configured root.
        $layout->path($this->path(config('ddd.domain_path')).'/{domain}')->allowsNesting();

        // Kinds after every root is known: a type's home root decides which kind carries its command.
        foreach ($this->rootLayers as $name => $key) {
            $this->declareKinds($layout, $name, $key !== '');
        }

        // Anonymous migrations need a path even when the class namespace is empty.
        foreach ($this->emptyRoots as $root => $configKey) {
            $fileRoot = $root.'-migrations';
            $kind = $root.'-migration';
            $layout->mounts($fileRoot, null, $this->path(config('ddd.'.$root.'_path')));
            $layout->generates($fileRoot.'-root', in: $fileRoot.':{domain+}', nested: true, command: false);
            $layout->generates($kind, in: $fileRoot.':{domain+}/'.$this->folder('migration'), timestamped: true, command: false);
            $this->typeKinds[$root]['migration'] = $kind;
            $this->kindTypes[$kind] = [$root, 'migration'];
        }

        $this->declareRelations($layout);

        return $layout;
    }

    private function compileLayout(Layout $layout): void
    {
        $this->compiled = $layout->compiled();
    }

    /**
     * One kind per object type below a root; identical folders share a kind.
     */
    private function declareKinds(Layout $layout, string $root, bool $customLayer): void
    {
        $prefix = $customLayer ? '' : '{'.self::DIMENSION.'+}';
        $declared = [];

        // Negative, like mod's own catch-alls, so a folder of classes is no evidence of a domain
        // (mod's new-domain notice); every typed kind still ranks above it.
        $layout->generates($root.'-root', in: $root.':'.$prefix, nested: true, command: false, priority: -1);
        $declared[''] = $root.'-root';
        $rootCommand = null;
        // Reverse mapping: when configured folders overlap (Models and Models\Nested), the type configured
        // first wins, as v3's first-match scan did; hence a priority that decreases with the type order.
        $types = $this->types();
        $priority = count($types) + 1;

        foreach ($types as $type) {
            $folder = $this->folder($type);

            $command = $this->homeRoot($type) === $root ? ($this->commandFor($type) ?? false) : false;
            $priority--;

            if ($folder === '' && $command !== false && $rootCommand === null) {
                $rootCommand = $command;
                $layout->generates($root.'-root', command: $command);
            }

            $in = $root.':'.trim($prefix.'/'.$folder, '/');
            $modKind = $this->modKindFor($type, $root);

            if ($modKind !== null) {
                // The type's kind in mod's layout: its own kind even where a folder is shared, below the
                // root's catch-all when it has no folder (as mod's class/interface/trait are), so reverse
                // mapping still finds the catch-all and the first type configured for a folder.
                $this->declareKind($layout, $modKind, $type, $in, $command, $folder === '' ? $priority - 1000 : $priority);

                if (isset(self::STUBS[$modKind])) {
                    $layout->generates($modKind, stub: Stub::file(DDD::packagePath('stubs/'.self::STUBS[$modKind])));
                }

                $declared[$folder] ??= $modKind;
                $this->kindTypes[$declared[$folder]] ??= [$root, $type];
                $this->kindTypes[$modKind] = [$root, $type];
                $this->typeKinds[$root][$type] = $modKind;

                continue;
            }

            if (! isset($declared[$folder])) {
                $id = $root.'-'.self::slug($type);
                $this->declareKind($layout, $id, $type, $in, $command, $priority);
                $declared[$folder] = $id;
            }

            $this->kindTypes[$declared[$folder]] ??= [$root, $type];
            $this->typeKinds[$root][$type] = $declared[$folder];
        }
    }

    /**
     * One kind of a type. Every setting is given, so a kind of mod's layout keeps
     * none of its own (no suffix, no folder discovery); its stub and label stay.
     */
    private function declareKind(Layout $layout, string $id, string $type, string $in, string|false $command, int $priority): void
    {
        if (in_array($type, self::DISCOVERED, true)) {
            $layout->generates($id, in: $in, timestamped: false, nested: true, command: $command, priority: $priority, discover: 'anywhere', discoverExcept: []);
        } elseif ($type === 'migration') {
            $layout->generates($id, in: $in, timestamped: true, nested: false, command: $command, priority: $priority);
        } else {
            $layout->generates($id, in: $in, timestamped: false, nested: true, command: $command, priority: $priority, discover: 'folder');
        }
    }

    /**
     * The kind id mod's ddd layout gives a type in its home root, or null (another root, a standalone layout, a type mod lacks).
     */
    private function modKindFor(string $type, string $root): ?string
    {
        return $this->extended && $this->homeRoot($type) === $root ? (self::MOD_KINDS[$type] ?? null) : null;
    }

    /**
     * Relations between the kinds of each root, with the target root decided
     * by the configured application objects (recompiled when they change).
     */
    private function declareRelations(Layout $layout): void
    {
        $declared = [];
        // mod's relation ids where the source kind is mod's (its home root), root-prefixed elsewhere.
        $id = fn (string $from, string $relation, string $root): string => $this->modKindFor($from, $root) !== null ? $relation : $root.'-'.$relation;

        foreach ($this->rootLayers as $root => $key) {
            $layer = $key === '' ? null : new Layer($key, (string) config('ddd.layers.'.$key), LayerType::Custom);

            $target = function (string $type) use ($root, $layer): ?string {
                $targetLayer = $layer ?? ($this->isApplication($type) ? LayerType::Application : LayerType::Domain);
                $targetRoot = $targetLayer instanceof LayerType ? ($targetLayer === LayerType::Application ? 'application' : 'domain') : $root;

                return isset($this->rootLayers[$targetRoot]) ? $this->declaredKind($type, $targetRoot) : null;
            };

            $model = $this->declaredKind('model', $root);
            $controller = $this->declaredKind('controller', $root);
            $factory = $this->declaredKind('factory', $root);

            if ($model !== null) {
                foreach (['factory', 'policy', 'seeder', 'controller'] as $type) {
                    $to = $target($type);

                    if ($to !== null) {
                        // Native naming: Invoice → InvoiceFactory / InvoicePolicy / InvoiceSeeder / InvoiceController.
                        $layout->relates($model, $to, name: ['suffix' => ucfirst($type)], as: $declared[] = $id('model', 'model-'.$type, $root));
                    }
                }

                $migration = $this->declaredKind('migration', $root);

                if ($migration !== null) {
                    $layout->relates($model, $migration, name: 'explicit', as: $declared[] = $id('model', 'model-migration', $root));
                }
            }

            if ($controller !== null) {
                $request = $target('request');

                if ($request !== null) {
                    // Relation ids are unique per layout: the controller's home root owns the bare ids mod's
                    // ControllerCommand looks up; other roots get root-prefixed ones.
                    $ids = $this->homeRoot('controller') === $root ? '' : $root.'-';
                    $layout->relates($controller, $request, name: ['prefix' => 'Store', 'suffix' => 'Request'], as: $declared[] = $ids.'controller-store-request');
                    $layout->relates($controller, $request, name: ['prefix' => 'Update', 'suffix' => 'Request'], as: $declared[] = $ids.'controller-update-request');
                }
            }

            if ($factory !== null && $model !== null) {
                $layout->relates($factory, $model, name: ['strip-suffix' => 'Factory'], mode: 'reference', as: $declared[] = $id('factory', 'factory-model', $root));
            }
        }

        // mod's own relations Laravel-DDD does not declare (listener-event, model-store-request, …) stay off.
        if ($this->extended) {
            foreach (array_diff(self::modRelationIds(), $declared) as $relation) {
                $edge = Layout::fresh(self::EXTENDS)->compiled()->relation($relation);
                $layout->relates($edge->fromFileType, $edge->toFileType, mode: 'none', as: $relation);
            }
        }
    }

    /**
     * The relation ids of mod's ddd layout as shipped.
     *
     * @return list<string>
     */
    private static function modRelationIds(): array
    {
        return self::$modRelationIds ??= array_keys(Layout::fresh(self::EXTENDS)->compiled()->relations());
    }

    private function declaredKind(string $type, string $root): ?string
    {
        return $this->typeKinds[$root][$type] ?? null;
    }

    /**
     * The root a type is generated in when no custom layer is asked for.
     */
    private function homeRoot(string $type): string
    {
        return $this->isApplication($type) && isset($this->rootLayers['application']) ? 'application' : 'domain';
    }

    private function isApplication(string $type): bool
    {
        $objects = config('ddd.application_objects', ['controller', 'request']);

        return is_array($objects) && in_array($type, $objects, true);
    }

    /**
     * @return list<string>
     */
    /**
     * Object types in the order v3 consulted them: the configured namespaces
     * first (config order decides which type a shared or overlapping folder
     * maps back to), then the built-in types, then requested extras.
     *
     * @return list<string>
     */
    private function types(): array
    {
        $configured = array_filter(array_keys((array) config('ddd.namespaces', [])), 'is_string');

        return array_values(array_unique([...$configured, ...self::TYPES, ...array_keys($this->extraTypes)]));
    }

    private function requireType(string $type): void
    {
        if (! in_array($type, $this->types(), true)) {
            $this->extraTypes[$type] = true;
            $this->compiled = null;
        }

        $this->compiled();
    }

    private function folder(string $type): string
    {
        return trim(str_replace('\\', '/', DomainResolver::getRelativeObjectNamespace($type)), '/');
    }

    private function rootFor(Layer $layer): string
    {
        $root = $layer->type === LayerType::Application ? 'application' : ($layer->type === LayerType::Custom ? null : 'domain');

        if ($root !== null && isset($this->emptyRoots[$root])) {
            throw InvalidLayoutUse::emptyNamespace($this->emptyRoots[$root]);
        }

        if ($layer->type === LayerType::Application) {
            return 'application';
        }

        if ($layer->type === LayerType::Custom) {
            foreach ($this->rootLayers as $name => $key) {
                if ($key !== '' && Path::normalizeNamespace($key) === $layer->namespace) {
                    return $name;
                }
            }

            throw InvalidLayoutUse::layerNotInLayout((string) $layer->namespace, $this->issues);
        }

        return 'domain';
    }

    /**
     * @return list<array{0: string, 1: string}> [namespace prefix, base-relative path]
     */
    private function discoveryRootsFor(array $paths): array
    {
        $roots = [];

        foreach (array_unique($paths) as $path) {
            $mapped = $this->psr4For($path);

            if ($mapped !== null) {
                $roots[$mapped[1]] = $mapped;
            }
        }

        return array_values($roots);
    }

    /**
     * The PSR-4 prefix and base-relative path of a directory, from the registered Composer loaders.
     *
     * @return array{0: string, 1: string}|null
     */
    private function psr4For(string $path): ?array
    {
        $basePath = rtrim(Container::getInstance()->make('path.base'), '/\\');
        $absolute = str_starts_with($path, '/') ? $path : $basePath.'/'.ltrim($path, '/\\');
        $real = realpath($absolute);
        $basePath = realpath($basePath) ?: $basePath;

        if ($real === false) {
            return null;
        }

        $best = null;

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                foreach ($directories as $directory) {
                    $directoryReal = realpath($directory);

                    if ($directoryReal === false || ($real !== $directoryReal && ! str_starts_with($real, $directoryReal.DIRECTORY_SEPARATOR))) {
                        continue;
                    }

                    if ($best === null || strlen($directoryReal) > strlen($best[2])) {
                        $remainder = trim(str_replace(DIRECTORY_SEPARATOR, '\\', substr($real, strlen($directoryReal))), '\\');
                        $best = [rtrim($prefix, '\\').'\\'.($remainder === '' ? '' : $remainder.'\\'), $real, $directoryReal];
                    }
                }
            }
        }

        if ($best === null) {
            return null;
        }

        $relative = str_starts_with($real, $basePath.DIRECTORY_SEPARATOR) ? substr($real, strlen($basePath) + 1) : $real;

        return [$best[0], CompiledRoot::normalisePath($relative)];
    }

    private function fingerprint(): string
    {
        $config = [
            'domain_path' => config('ddd.domain_path'), 'domain_namespace' => config('ddd.domain_namespace'),
            'application_path' => config('ddd.application_path'), 'application_namespace' => config('ddd.application_namespace'),
            'application_objects' => config('ddd.application_objects'), 'layers' => config('ddd.layers'),
            'namespaces' => config('ddd.namespaces'),
            'types' => array_keys($this->extraTypes),
        ];

        return hash('sha256', serialize($config));
    }

    private function namespace(mixed $value): string
    {
        return is_string($value) ? trim(Path::normalizeNamespace($value), '\\').'\\' : '\\';
    }

    private function path(mixed $value): string
    {
        return is_string($value) ? CompiledRoot::normalisePath($value) : '';
    }

    private static function slug(string $type): string
    {
        return strtolower(str_replace('_', '-', $type));
    }
}
