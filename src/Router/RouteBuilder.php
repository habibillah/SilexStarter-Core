<?php

namespace SilexStarter\Router;

use Illuminate\Support\Str;
use SilexStarter\SilexStarter;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Response;

class RouteBuilder
{
    /** controllers context stack */
    protected $contextStack = [];

    /** before handler stack */
    protected $beforeHandlerStack = [];

    /** after handler stack */
    protected $afterHandlerStack = [];

    /** namespace stack */
    protected $namespaceStack = [];

    /** Silex\Application instance */
    protected $app;

    /** Illuminate\Support\Str instance */
    protected $stringHelper;

    /**
     * Construct the RouteBuilder object.
     *
     * @param SilexStarter $app
     * @param Str         $str
     */
    public function __construct(SilexStarter $app, Str $str)
    {
        $this->app            = $app;
        $this->stringHelper   = $str;
    }

    /**
     * Push the ControllerCollection into context stack,
     * the latest instance in context will be used by get, match, etc for route grouping.
     *
     * @param ControllerCollection $context
     */
    protected function pushContext(ControllerCollection $context)
    {
        $this->contextStack[] = $context;
    }

    /**
     * Retrieve the latest ControllerCollection and remove the instance from the context.
     */
    protected function popContext()
    {
        return array_pop($this->contextStack);
    }

    /**
     * Get the current context, the latest ControllerCollection in context stack
     * or root ControllerCollection instance if context stack is empty.
     *
     * @return ControllerCollection
     */
    protected function getContext()
    {
        if (!empty($this->contextStack)) {
            return end($this->contextStack);
        } else {
            return $this->app['controllers'];
        }
    }

    /**
     * Add new before handler to the end of middleware stack.
     *
     * @param array|string|\Closure $beforeHandler The before middleware handler
     */
    protected function pushBeforeHandler($beforeHandler)
    {
        $beforeHandler = is_string($beforeHandler) ? $this->app->middleware($beforeHandler) : $beforeHandler;
        $this->beforeHandlerStack[] = $beforeHandler;
    }

    /**
     * Retrieve latest middleware from the middleware stack.
     *
     * @return callable Closure or array of closure
     */
    protected function popBeforeHandler()
    {
        return array_pop($this->beforeHandlerStack);
    }

    /**
     * Get the full middleware stack.
     *
     * @return array
     */
    protected function getBeforeHandler()
    {
        return $this->beforeHandlerStack;
    }

    /**
     * Add new after handler to the top of middleware stack.
     *
     * @param array|string|callable $afterHandler The after middleware handler
     */
    protected function pushAfterHandler($afterHandler)
    {
        $afterHandler = is_string($afterHandler) ? $this->app->middleware($afterHandler) : $afterHandler;
        array_unshift($this->afterHandlerStack, $afterHandler);
    }

    /**
     * Retrieve first middleware from the middleware stack.
     *
     * @return array|callable Closure or array of closure
     */
    protected function popAfterHandler()
    {
        return array_shift($this->afterHandlerStack);
    }

    /**
     * Get the full middleware stack.
     *
     * @return array
     */
    protected function getAfterHandler()
    {
        return $this->afterHandlerStack;
    }

    /**
     * Apply the middleware and binding to the controller.
     *
     * @param mixed $route   The controller or controller collection
     * @param array                           $options the route options
     *
     * @return mixed
     */
    protected function applyControllerOption($route, array $options)
    {
        $this->applyBeforeHandlerStack($route, isset($options['before']) ? $options['before'] : null);
        $this->applyAfterHandlerStack($route, isset($options['after']) ? $options['after'] : null);

        if (isset($options['as'])) {
            $route->bind($options['as']);
        }

        if (isset($options['assert'])) {
            foreach ($options['assert'] as $placeholder => $rule) {
                $route->assert($placeholder, $rule);
            }
        }

        if (isset($options['default'])) {
            foreach ($options['default'] as $placeholder => $value) {
                $route->value($placeholder, $value);
            }
        }

        return $route;
    }

    /**
     * @param $route
     * @param callable $beforeHandler
     */
    protected function applyBeforeHandlerStack($route, $beforeHandler = null)
    {
        foreach ($this->getBeforeHandler() as $before) {
            $route->before($before);
        }

        if ($beforeHandler) {
            $route->before(
                is_string($beforeHandler)
                ? $this->app->middleware($beforeHandler)
                : $beforeHandler
            );
        }
    }

    public function pushNamespace($namespace)
    {
        $namespace = trim($namespace, '\\');
        $this->namespaceStack[] = $namespace;
    }

    public function popNamespace()
    {
        return array_pop($this->namespaceStack);
    }

    public function getNamespace($lastNs = null)
    {
        $namespace = implode('\\', $this->namespaceStack);

        if ($lastNs) {
            return implode('\\', [$namespace, trim($lastNs, '\\')]);
        }

        return $namespace;
    }

    /**
     * @param $route
     * @param mixed $afterHandler
     */
    protected function applyAfterHandlerStack($route, $afterHandler = null)
    {
        if ($afterHandler) {
            $route->after(
                is_string($afterHandler)
                ? $this->app->middleware($afterHandler)
                : $afterHandler
            );
        }

        foreach ($this->getAfterHandler() as $after) {
            $route->after($after);
        }
    }

    protected function forwardRouteMethod($method, $pattern, $to = null, array $options = [])
    {
        $ns    = $this->getNamespace(isset($options['namespace']) ? $options['namespace'] : null);
        $route = $this->getContext()->{$method}($pattern, $ns ? $ns . '\\' .$to : $to);

        if (isset($options['permission'])) {
            $permission = $options['permission'];

            $route->before(
                function (Application $app) use ($permission) {
                    if (!$app['route_permission_checker']->check($permission)) {
                        return new Response('No sufficient permission to access this page', 401);
                    }
                }
            );
        }

        $route = $this->applyControllerOption($route, $options);

        return $route;
    }

    public function match($pattern, $to = null, array $options = [])
    {
        return $this->forwardRouteMethod('match', $pattern, $to, $options);
    }

    public function get($pattern, $to = null, array $options = [])
    {
        return $this->forwardRouteMethod('get', $pattern, $to, $options);
    }

    public function post($pattern, $to = null, array $options = [])
    {
        return $this->forwardRouteMethod('post', $pattern, $to, $options);
    }

    public function put($pattern, $to = null, array $options = [])
    {
        return $this->forwardRouteMethod('put', $pattern, $to, $options);
    }

    public function delete($pattern, $to = null, array $options = [])
    {
        return $this->forwardRouteMethod('delete', $pattern, $to, $options);
    }

    public function patch($pattern, $to = null, array $options = [])
    {
        return $this->forwardRouteMethod('patch', $pattern, $to, $options);
    }

    /**
     * Grouping route into controller collection and mount to specific prefix.
     *
     * @param string   $prefix   the route prefix
     * @param \Closure $callable the route collection handler
     * @param array    $options  the route options
     *
     * @return \Silex\ControllerCollection controller collection that already mounted to $prefix
     */
    public function group($prefix, \Closure $callable, array $options = [])
    {
        if (isset($options['before'])) {
            $this->pushBeforeHandler($options['before']);
        }

        if (isset($options['after'])) {
            $this->pushAfterHandler($options['after']);
        }

        if (isset($options['namespace'])) {
            $this->pushNamespace($options['namespace']);
        }

        /* push the context to be accessed to callable route */
        $this->pushContext($this->app['controllers_factory']);

        $callable();

        $routeCollection = $this->popContext();

        if (isset($options['before'])) {
            $this->popBeforeHandler();
        }

        if (isset($options['after'])) {
            $this->popAfterHandler();
        }

        if (isset($options['namespace'])) {
            $this->popNamespace();
        }

        $this->getContext()->mount($prefix, $routeCollection);

        return $routeCollection;
    }

    /**
     * Build route into resourceful controller.
     *
     * @param string $prefix     the route prefix
     * @param string $controller the controller class
     * @param array  $options    the route options
     *
     * @return \Silex\ControllerCollection
     */
    public function resource($prefix, $controller, array $options = [])
    {
        $prefix     = '/'.ltrim($prefix, '/');
        $ns         = $this->getNamespace(isset($options['namespace']) ? $options['namespace'] : null);
        $controller = $ns ? $ns . '\\' .$controller : $controller;

        $routeMaps  = [
            'get' => new RouteMap(
                'get',
                '/',
                "$controller:index",
                isset($options['as']) ? ['as' => $options['as'] . '.index'] : []
            ),
            'get_paginate' => new RouteMap(
                'get',
                '/page/{page}',
                "$controller:index",
                isset($options['as']) ? ['as' => $options['as'] . '.paged'] : []
            ),
            'get_create' => new RouteMap(
                'get',
                '/create',
                "$controller:create",
                isset($options['as']) ? ['as' => $options['as'] . '.create'] : []
            ),
            'get_edit' => new RouteMap(
                'get',
                '/{id}/edit',
                "$controller:edit",
                isset($options['as']) ? ['as' => $options['as'] . '.edit'] : []
            ),
            'get_show' => new RouteMap(
                'get',
                '/{id}',
                "$controller:show",
                isset($options['as']) ? ['as' => $options['as'] . '.show'] : []
            ),
            'post' => new RouteMap(
                'post',
                '/',
                "$controller:store",
                isset($options['as']) ? ['as' => $options['as'] . '.store'] : []
            ),
            'put' => new RouteMap(
                'put',
                '/{id}',
                "$controller:update",
                isset($options['as']) ? ['as' => $options['as'] . '.update'] : []
            ),
            'delete' => new RouteMap(
                'delete',
                '/{id}',
                "$controller:destroy",
                isset($options['as']) ? ['as' => $options['as'] . '.delete'] : []
            ),
        ];

        unset($options['as']);

        $routeCollection = $this->buildControllerRoute($this->app['controllers_factory'], $routeMaps);

        $this->applyControllerOption($routeCollection, $options);

        $this->getContext()->mount($prefix, $routeCollection);

        return $routeCollection;
    }

    /**
     * Build route to all available public method in controller class.
     *
     * @param string $prefix     the route prefix
     * @param string $controller the controller class name or object
     * @param array  $options    the route options
     *
     * @return \Silex\ControllerCollection
     */
    public function controller($prefix, $controller, array $options = [])
    {
        $prefix             = '/'.ltrim($prefix, '/');
        $ns                 = $this->getNamespace(isset($options['namespace']) ? $options['namespace'] : null);
        $controller         = $ns ? $ns . '\\' .$controller : $controller;
        $routeMaps          = $this->createControllerRouteMap($controller, $options);

        $routeCollection    = $this->buildControllerRoute($this->app['controllers_factory'], $routeMaps);

        unset($options['as']);

        $this->applyControllerOption($routeCollection, $options);
        $this->getContext()->mount($prefix, $routeCollection);

        return $routeCollection;
    }

    /**
     * Create list of route map based on controller's public method.
     *
     * @param object|string $controller Fully qualified controller class name or class instance
     *
     * @return array array of SilexStarter\Router\RouteMap
     */
    protected function createControllerRouteMap($controller, $options)
    {
        $class              = new \ReflectionClass($controller);
        $controllerActions  = $class->getMethods(\ReflectionMethod::IS_PUBLIC);

        $uppercase          = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $acceptedMethod     = ['get', 'post', 'put', 'delete', 'head', 'options', 'patch'];

        $routeMaps          = [];

        foreach ($controllerActions as $action) {

            /* skip if method is considered magic method */
            if (strpos($action->name, '__') === 0) {
                continue;
            }

            $routeOptions   = [];
            $routeAction    = $class->getName().':'.$action->name;

            /* the http method get, put, post, etc */
            $httpMethod     = substr($action->name, 0, strcspn($action->name, $uppercase));

            /* the url path, index => getIndex */
            $routeName      = (in_array($httpMethod, $acceptedMethod))
                            ? $this->stringHelper->snake(strpbrk($action->name, $uppercase))
                            : $this->stringHelper->snake($action->name);

            $defaultParams  = [];
            $routePattern   = ($routeName === 'index') ? '/' : $routeName;

            foreach ($action->getParameters() as $param) {
                $routePattern .= '/{'.$param->getName().'}';

                if ($param->isDefaultValueAvailable()) {
                    $defaultParams[$param->getName()] = $param->getDefaultValue();
                }
            }

            $routeOptions['default'] = $defaultParams;

            if (isset($options['as'])) {
                $routeOptions['as'] = $options['as'] . '.' . $routeName;
            }


            $routeMaps[$routeName]  = new RouteMap($httpMethod, $routePattern, $routeAction, $routeOptions);
        }

        return $routeMaps;
    }

    /**
     * Apply route maps into route collection.
     *
     * @param ControllerCollection $router    The ControllerCollection instance
     * @param array                $routeMaps List of RouteMap object
     *
     * @return ControllerCollection
     */
    protected function buildControllerRoute(ControllerCollection $router, array $routeMaps)
    {
        foreach ($routeMaps as $map) {
            $options = $map->getOptions();
            $pattern = $map->getPattern();
            $method  = $map->getHttpMethod() ? $map->getHttpMethod() : 'match';
            $route   = $router->$method($pattern, $map->getAction());

            if (isset($options['default'])) {
                foreach ($options['default'] as $field => $value) {
                    $route->value($field, $value);
                }
            }

            if (isset($options['as'])) {
                $route->bind($options['as']);
            }
        }

        return $router;
    }
}
