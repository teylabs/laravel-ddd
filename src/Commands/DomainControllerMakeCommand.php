<?php

namespace Tey\LaravelDDD\Commands;

use Illuminate\Routing\Console\ControllerMakeCommand;
use Tey\LaravelDDD\Commands\Concerns\ForwardsToDomainCommands;
use Tey\LaravelDDD\Commands\Concerns\HasDomainStubs;
use Tey\LaravelDDD\Commands\Concerns\ResolvesDomainFromInput;
use Tey\LaravelDDD\Support\Path;

class DomainControllerMakeCommand extends ControllerMakeCommand
{
    use ForwardsToDomainCommands,
        HasDomainStubs,
        ResolvesDomainFromInput;

    protected $name = 'ddd:controller';

    protected function buildFormRequestReplacements(array $replace, $modelClass)
    {
        [$storeRequestClass, $updateRequestClass] = ['Request', 'Request'];
        [$storeRequest, $updateRequest] = ['Illuminate\\Http\\Request', 'Illuminate\\Http\\Request'];

        if ($this->option('requests')) {
            [$storeRequestClass, $updateRequestClass] = $this->generateFormRequests(
                $modelClass,
                $storeRequestClass,
                $updateRequestClass
            );

            // Resolved after generation, from the name each request was actually
            // forwarded under, so both sides derive the location from one rule
            // instead of computing it twice. Building these from the
            // controller's own name put the controller's class name into the
            // namespace, and for a nested controller left a raw '/' inside a use
            // statement.
            //
            // This resolves the conventional location for the forwarded name. A
            // schema callback that relocates the child request is not reflected
            // here; see RelatedObjectsCompatibilityTest for that limitation.
            //
            // The fully qualified name is what carries a nested folder: an
            // object's ->namespace is the layer's, so Requests\Billing\Store...
            // would lose its Billing segment if the name were appended by hand.
            $storeRequest = $this->requestReferenceFor($storeRequestClass);
            $updateRequest = $this->requestReferenceFor($updateRequestClass);
        }

        $namespacedRequests = $storeRequest.';';

        if ($storeRequestClass !== $updateRequestClass) {
            $namespacedRequests .= PHP_EOL.'use '.$updateRequest.';';
        }

        return array_merge($replace, [
            '{{ storeRequest }}' => $storeRequestClass,
            '{{storeRequest}}' => $storeRequestClass,
            '{{ updateRequest }}' => $updateRequestClass,
            '{{updateRequest}}' => $updateRequestClass,
            '{{ namespacedStoreRequest }}' => $storeRequest,
            '{{namespacedStoreRequest}}' => $storeRequest,
            '{{ namespacedUpdateRequest }}' => $updateRequest,
            '{{namespacedUpdateRequest}}' => $updateRequest,
            '{{ namespacedRequests }}' => $namespacedRequests,
            '{{namespacedRequests}}' => $namespacedRequests,
        ]);
    }

    /**
     * The fully qualified name of a form request this controller generated.
     *
     * The request is forwarded under the controller's subfolder, so it is asked
     * for under that same name — the one ForwardsToDomainCommands will use —
     * rather than under the bare class name.
     */
    protected function requestReferenceFor(string $requestClass): string
    {
        return $this->blueprint
            ->getRequestFor($this->forwardedNameFor($requestClass))
            ->fullyQualifiedName;
    }

    protected function buildClass($name)
    {
        $stub = parent::buildClass($name);

        if ($this->isUsingPublishedStub()) {
            return $stub;
        }

        // Handle Laravel 10 side effect
        // todo: deprecated since L10 is no longer supported.
        if (str($stub)->contains($invalidUse = "use {$this->getNamespace($name)}\Http\Controllers\Controller;\n")) {
            $laravel10Replacements = [
                ' extends Controller' => '',
                $invalidUse => '',
            ];

            $stub = str_replace(
                array_keys($laravel10Replacements),
                array_values($laravel10Replacements),
                $stub
            );
        }

        $replace = [];

        $appRootNamespace = $this->laravel->getNamespace();
        $pathToAppBaseController = Path::normalize(app()->path('Http/Controllers/Controller.php'));

        $baseControllerExists = $this->files->exists($pathToAppBaseController);

        if ($baseControllerExists) {
            $controllerClass = class_basename($name);
            $fullyQualifiedBaseController = "{$appRootNamespace}Http\Controllers\Controller";
            $namespaceLine = "namespace {$this->getNamespace($name)};";
            $replace["{$namespaceLine}\n"] = "{$namespaceLine}\n\nuse {$fullyQualifiedBaseController};";
            $replace["class {$controllerClass}\n"] = "class {$controllerClass} extends Controller\n";
        }

        $stub = str_replace(
            array_keys($replace),
            array_values($replace),
            $stub
        );

        return $this->sortImports($stub);
    }
}
