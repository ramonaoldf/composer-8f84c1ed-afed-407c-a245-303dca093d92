<?php

namespace Laravel\Wayfinder;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route as BaseRoute;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory;
use ReflectionProperty;

use function Illuminate\Filesystem\join_paths;
use function Laravel\Prompts\info;

class GenerateCommand extends Command
{
    protected $signature = 'wayfinder:generate {--path=} {--skip-actions} {--skip-routes} {--with-form}';

    private ?string $forcedScheme;

    private ?string $forcedRoot;

    private $urlDefaults = [];

    private $pathDirectory = 'actions';

    private $content = [];

    public function __construct(
        private Filesystem $files,
        private Router $router,
        private Factory $view,
        private UrlGenerator $url,
        private BladeCompiler $bladeCompiler,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->view->addNamespace('wayfinder', __DIR__.'/../resources');
        $this->view->addExtension('blade.ts', 'blade');
        $this->bladeCompiler->directive('trimDeadspace', function () {
            return '<?php ob_start(); ?>';
        });
        $this->bladeCompiler->directive('endtrimDeadspace', function () {
            return '<?php echo \Laravel\Wayfinder\TypeScript::trimDeadspace(ob_get_clean()); ?>';
        });

        $this->forcedScheme = (new ReflectionProperty($this->url, 'forceScheme'))->getValue($this->url);
        $this->forcedRoot = (new ReflectionProperty($this->url, 'forcedRoot'))->getValue($this->url);

        $routes = collect($this->router->getRoutes())->map(function (BaseRoute $route) {
            $defaults = collect($this->router->gatherRouteMiddleware($route))->map(function ($middleware) {
                $this->urlDefaults[$middleware] ??= $this->getDefaultsForMiddleware($middleware);

                return $this->urlDefaults[$middleware];
            })->flatMap(fn ($r) => $r);

            return new Route($route, $defaults, $this->forcedScheme, $this->forcedRoot);
        });

        $this->files->deleteDirectory($this->base());

        if (! $this->option('skip-actions')) {
            $controllers = $routes->filter(fn (Route $route) => $route->hasController())->groupBy(fn (Route $route) => $route->dotNamespace());

            $controllers->undot()->each($this->writeBarrelFiles(...));
            $controllers->each($this->writeControllerFile(...));

            $this->writeContent();

            info('[Wayfinder] Generated actions in '.$this->base());
        }

        $this->pathDirectory = 'routes';

        $this->files->deleteDirectory($this->base());

        if (! $this->option('skip-routes')) {
            $named = $routes->filter(fn (Route $route) => $route->name() && ! Str::endsWith($route->name(), '.'))->groupBy(fn (Route $route) => Str::beforeLast($route->name(), '.'));

            $named->undot()->each($this->writeBarrelFiles(...));
            $named->each($this->writeNamedFile(...));

            $this->writeContent();

            info('[Wayfinder] Generated routes in '.$this->base());
        }

        $this->pathDirectory = 'wayfinder';

        $this->files->ensureDirectoryExists($this->base());
        $this->files->copy(__DIR__.'/../resources/js/wayfinder.ts', join_paths($this->base(), 'index.ts'));
    }

    private function appendContent($path, $content): void
    {
        $this->content[$path] ??= [];

        $this->content[$path][] = $content;
    }

    private function writeContent(): void
    {
        foreach ($this->content as $path => $content) {
            $this->files->ensureDirectoryExists(dirname($path));

            $this->files->put($path, TypeScript::cleanUp(implode(PHP_EOL, $content)));
        }

        $this->content = [];
    }

    private function writeControllerFile(Collection $routes, string $namespace): void
    {
        $path = join_paths($this->base(), ...explode('.', $namespace)).'.ts';

        $this->appendCommonImports($routes, $path, $namespace);

        $routes->groupBy(fn (Route $route) => $route->method())->each(function ($methodRoutes) use ($path) {
            if ($methodRoutes->count() === 1) {
                return $this->writeControllerMethodExport($methodRoutes->first(), $path);
            }

            return $this->writeMultiRouteControllerMethodExport($methodRoutes, $path);
        });

        [$invokable, $methods] = $routes->partition(fn (Route $route) => $route->hasInvokableController());

        $defaultExport = $invokable->isNotEmpty() ? $invokable->first()->jsMethod() : last(explode('.', $namespace));

        if ($invokable->isEmpty()) {
            $methodProps = "const {$defaultExport} = { ";
            $methodProps .= $methods->map(fn (Route $route) => $route->jsMethod())->unique()->implode(', ');
            $methodProps .= ' }';
        } else {
            $methodProps = $methods->map(fn (Route $route) => $defaultExport.'.'.$route->jsMethod().' = '.$route->jsMethod())->unique()->implode(PHP_EOL);
        }

        $this->appendContent($path, <<<JAVASCRIPT
        {$methodProps}

        export default {$defaultExport}
        JAVASCRIPT);
    }

    private function writeMultiRouteControllerMethodExport(Collection $routes, string $path): void
    {
        $this->appendContent($path, $this->view->make('wayfinder::multi-method', [
            'method' => $routes->first()->jsMethod(),
            'original_method' => $routes->first()->originalJsMethod(),
            'path' => $routes->first()->controllerPath(),
            'line' => $routes->first()->controllerMethodLineNumber(),
            'controller' => $routes->first()->controller(),
            'isInvokable' => $routes->first()->hasInvokableController(),
            'withForm' => $this->option('with-form') ?? false,
            'routes' => $routes->map(fn ($r) => [
                'tempMethod' => $r->jsMethod().md5($r->uri()),
                'parameters' => $r->parameters(),
                'verbs' => $r->verbs(),
                'uri' => $r->uri(),
            ]),
        ]));
    }

    private function writeControllerMethodExport(Route $route, string $path): void
    {
        $this->appendContent($path, $this->view->make('wayfinder::method', [
            'controller' => $route->controller(),
            'method' => $route->jsMethod(),
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => $route->hasInvokableController(),
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'withForm' => $this->option('with-form') ?? false,
        ]));
    }

    private function writeNamedFile(Collection $routes, string $namespace): void
    {
        $path = join_paths($this->base(), ...explode('.', $namespace)).'.ts';

        $this->appendCommonImports($routes, $path, $namespace);

        $routes->each(fn (Route $route) => $this->writeNamedMethodExport($route, $path));

        $imports = $routes->map(fn (Route $route) => $route->namedMethod())->implode(', ');

        $basename = basename($path, '.ts');
        $base = Str::of($basename)->when(
            str_contains($basename, '-'),
            fn ($s) => $s->camel()
        )->toString();

        if ($base !== $imports) {
            $this->appendContent($path, <<<JAVASCRIPT
                const {$base} = { {$imports} }

                export default {$base}
                JAVASCRIPT);
        }
    }

    private function appendCommonImports(Collection $routes, string $path, string $namespace): void
    {
        $imports = ['queryParams', 'type QueryParams'];

        if ($routes->contains(fn (Route $route) => $route->parameters()->contains(fn (Parameter $parameter) => $parameter->optional))) {
            $imports[] = 'validateParameters';
        }

        $importBase = str_repeat('/..', substr_count($namespace, '.') + 1);

        $this->appendContent($path, 'import { '.implode(', ', $imports)." } from '.{$importBase}/wayfinder'\n");
    }

    private function writeNamedMethodExport(Route $route, string $path): void
    {
        $this->appendContent($path, $this->view->make('wayfinder::method', [
            'controller' => $route->controller(),
            'method' => $route->namedMethod(),
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => false,
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'withForm' => $this->option('with-form') ?? false,
        ]));
    }

    private function writeBarrelFiles(array|Collection $children, string $parent): void
    {
        $children = collect($children);

        if (array_is_list($children->all())) {
            return;
        }

        $children->each(function ($grandkids, $child) use ($parent) {
            $grandkids = collect($grandkids);

            if (array_is_list($grandkids->all())) {
                return;
            }

            $directory = join_paths($this->base(), $parent, $child);

            $imports = $grandkids->keys()->map(fn ($grandkid) => "import * as {$grandkid} from './{$grandkid}'")->implode(PHP_EOL);

            $this->appendContent(join_paths($directory, 'index.ts'), $imports);

            $keys = $grandkids->keys()->map(fn ($k) => str_repeat(' ', 4).$k)->implode(', '.PHP_EOL);

            $varExport = $child;

            if (str_contains($varExport, '-')) {
                $varExport = Str::camel($varExport);
            }

            $this->appendContent(join_paths($directory, 'index.ts'), <<<JAVASCRIPT


                const {$varExport} = {
                {$keys},
                }

                export default {$varExport}
                JAVASCRIPT);

            $this->writeBarrelFiles($grandkids, join_paths($parent, $child));
        });
    }

    private function base(): string
    {
        $base = $this->option('path') ?? join_paths(resource_path(), 'js');

        return join_paths($base, $this->pathDirectory);
    }

    private function getDefaultsForMiddleware(string $middleware)
    {
        if (! class_exists($middleware)) {
            return [];
        }

        $reflection = new \ReflectionClass($middleware);

        if (! $reflection->hasMethod('handle')) {
            return [];
        }

        $method = $reflection->getMethod('handle');

        // Get the file name and line numbers
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        // Read the file and extract the method contents
        $lines = file($fileName);
        $methodContents = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        if (! str_contains($methodContents, 'URL::defaults')) {
            return [];
        }

        $methodContents = str($methodContents)->after('{')->beforeLast('}')->trim();
        $tokens = token_get_all('<?php '.$methodContents);
        $foundUrlFacade = false;
        $defaults = [];
        $inArray = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && token_name($token[0]) === 'T_STRING') {
                if (
                    $token[1] === 'URL'
                    && is_array($tokens[$index + 1])
                    && $tokens[$index + 1][1] === '::'
                    && is_array($tokens[$index + 2])
                    && $tokens[$index + 2][1] === 'defaults'
                ) {
                    $foundUrlFacade = true;
                }
            }

            if (! $foundUrlFacade) {
                continue;
            }

            if ((is_array($token) && $token[0] === T_ARRAY) || $token === '[') {
                $inArray = true;
            }

            // If we are in an array context and the token is a string (key)
            if (! $inArray) {
                continue;
            }

            if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                $count = 1;
                $previousToken = $tokens[$index - $count];

                // Work backwards to get the key
                while (is_array($previousToken) && $previousToken[0] === T_WHITESPACE) {
                    $count++;
                    $previousToken = $tokens[$index - $count];
                }

                $valueToken = $tokens[$index + 1];
                $count = 1;

                // Work backwards to get the key
                while (is_array($valueToken) && $valueToken[0] === T_WHITESPACE) {
                    $count++;
                    $valueToken = $tokens[$index + $count];
                }

                $value = trim($valueToken[1], "'\"");

                $value = match ($value) {
                    'true' => 1,
                    'false' => 0,
                    default => $value,
                };

                $defaults[trim($previousToken[1], "'\"")] = $value;
            }

            // Check for the closing bracket of the array
            if ($token === ']') {
                $inArray = false;
                break;
            }
        }

        return $defaults;
    }
}
