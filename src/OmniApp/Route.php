<?php

namespace OmniApp;

use OmniApp\Middleware;
use OmniApp\Exception\Pass;
use OmniApp\Exception\Stop;
use OmniApp\App;

/**
 * Route
 */
class Route extends Middleware
{
    const _CLASS_ = __CLASS__;

    protected $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    /**
     * Register a route for path
     *
     * @param string               $path
     * @param \Closure|string      $runner
     * @param \Closure|string|null $more
     */
    public function on($path, $runner, $more = null)
    {
        if ($more) {
            $_args = func_get_args();
            $path = array_shift($_args);
            $runner = $_args;
        }
        App::config()->route[$path] = $runner;
    }

    /**
     * Run route
     *
     * @return array
     * @throws \Exception
     * @return bool
     */
    public function dispatch()
    {
        $routes = (array)App::config('route');

        // No routes
        if (!$routes) return false;

        //$path = trim($path, '/');
        if ($this->path AND !preg_match('/^[\w\-~\/\.]{1,400}$/', $this->path)) {
            return false;
        }

        // Loop routes for parse and dispatch
        foreach ($routes as $p => $ctrl) {
            if (!$p) continue;

            // Try to parse the params
            if (($params = self::parseRoute($this->path, $p)) !== false) {
                try {
                    return self::run($ctrl);
                    // If multiple controller
                } catch (Pass $e) {
                    // When catch Next, continue next route
                }
            }
        }

        return false;
    }

    /**
     * Run the route
     *
     * @param $ctrl
     * @throws \Exception
     * @return bool
     */
    public function run($ctrl)
    {
        if (!$ctrl) return false;

        // Force as array
        $ctrl = (array)$ctrl;
        // Loop the link
        foreach ($ctrl as $k => &$c) {
            // Try to factory a controller
            $c = Controller::factory($c);
            // If on controller on the link is unavailable the link will broken
            if (!$c) {
                throw new \Exception('Cann\'t use the controller in route with index "' . $k . '"');
                return false;
            }
            // Set next controller and io
            if ($k > 0) $ctrl[$k - 1]->setNext($c, App::$request, App::$response);
        }
        // Call the first
        $ctrl[0]->call();
        return true;
    }

    /**
     * Call for middleware
     */
    public function call()
    {
        ob_start();
        try {
            if (!$this->dispatch()) {
                App::notFound();
            }
        } catch (Stop $e) {
        }
        App::$response->write(ob_get_clean(), -1);
    }

    /**
     * Get or set not found route
     *
     * @param mixed $runner
     * @return mixed
     */
    public function notFound($runner = null)
    {
        if ($runner) {
            App::config('route.404', $runner, true);
        }
        return ($_route = App::config('route.404')) ? $this->run($_route) : false;
    }


    /**
     * Get or set error runner
     *
     * @param mixed $runner
     * @return mixed
     */
    public function error($runner = null)
    {
        $_args = array();
        if (!$runner instanceof \Exception) {
            App::config('route.error', $runner, true);
        } else {
            $_args = array($runner);
        }
        return ($_route = App::config('route.error')) ? $this->run($_route, $_args) : false;
    }

    /**
     * Parse the route
     *
     * @static
     * @param $path
     * @param $route
     * @return array|bool
     */
    protected static function parseRoute($path, $route)
    {
        $params = false;

        // Regex or Param check
        if (!strpos($route, ':') && strpos($route, '^') === false) {
            if ($path === $route) {
                $params = array();
            }
        } else {
            // Try match
            if (preg_match(self::toRegex($route), $path, $matches)) {
                array_shift($matches);
                $params = $matches;
            }
        }

        // When complete the return
        return $params;
    }

    /**
     * To regex
     *
     * @static
     * @param string $regex
     * @return string
     */
    protected static function toRegex($regex)
    {
        if ($regex[1] !== '^') {
            $regex = str_replace(array('/'), array('\\/'), $regex);
            if ($regex{0} == '^') {
                $regex = '/' . $regex . '/';
            } elseif (strpos($regex, ':')) {
                $regex = '/^' . preg_replace('/:([a-zA-Z0-9]+)/', '(?<$1>[a-zA-Z0-9\.\-\+_]+?)', $regex) . '$/';
            } else {
                $regex = '/^' . $regex . '$/';
            }
        }
        return $regex;
    }
}
