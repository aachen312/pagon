<?php
/**
 * OmniApp Framework
 *
 * @package   OmniApp
 * @author    Corrie Zhao <hfcorriez@gmail.com>
 * @copyright (c) 2011 - 2012 OmniApp Framework
 */

namespace OmniApp;

const VERSION = '0.3';

/*********************
 * core app
 ********************/

/**
 * App Class
 */
class App
{
    /**
     * @var Http\Input|Cli\Input
     */
    public $input;

    /**
     * @var Http\Output|Cli\Output
     */
    public $output;

    /**
     * @var Config
     */
    public $config;

    /**
     * @var array Local variables
     */
    public $locals = array();

    /**
     * @var Emitter
     */
    public $emitter;

    /**
     * @var string View engine
     */
    protected $engines;

    /**
     * @var Route
     */
    protected $route;

    /**
     * @var string Mode
     */
    protected $mode;

    /**
     * @var Middleware[]
     */
    protected $middleware = array();

    /**
     * @var float App start time
     */
    private $start_time = null;

    /**
     * @var bool Is cli?
     */
    private $_cli = false;

    /**
     * @var bool Is win?
     */
    private $_win = false;

    /**
     * @var bool Is run?
     */
    private $_run = false;

    /**
     * App init
     *
     * @param array $config
     * @throws \Exception
     */
    public function __construct($config = array())
    {
        $app = & $this;

        // Record start time
        $this->start_time = microtime(true);

        // Is cli
        $this->_cli = PHP_SAPI == 'cli';

        // Is win
        $this->_win = substr(PHP_OS, 0, 3) == 'WIN';

        // Register shutdown
        register_shutdown_function(array($this, '__shutdown'));

        // Register autoload
        spl_autoload_register(array($this, '__autoload'));

        // Set io depends on SAPI
        if (!$this->_cli) {
            $this->input = new Http\Input($app);
            $this->output = new Http\Output($app);
        } else {
            $this->input = new Cli\Input($app);
            $this->output = new Cli\Output($app);
        }

        // Init emitter
        $this->emitter = new Emitter();

        // Init Route
        $this->route = new Route($app, $this->input->path());

        // Force use UTF-8 encoding
        iconv_set_encoding("internal_encoding", "UTF-8");
        mb_internal_encoding('UTF-8');

        // Default things to do before run
        $this->emitter->on('run', function () use ($app) {
            // configure timezone
            if ($_timezone = $app->config->get('timezone')) date_default_timezone_set($_timezone);

            // configure debug
            if ($app->config->debug) $app->add(new Middleware\PrettyException());
        });

        // Config
        $this->config = $config instanceof Config ? $config : new Config($config);

        // Set default locals
        $this->locals['config'] = &$this->config;

        // Fire init
        $this->emitter->emit('init');
    }

    /**
     * Check if cli
     *
     * @return bool
     */
    public function isCli()
    {
        return $this->_cli;
    }

    /**
     * Check if windows, if not, it must be *unix
     *
     * @return bool
     */
    public function isWin()
    {
        return $this->_win;
    }

    /**
     * Get app start time
     *
     * @return int
     */
    public function startTime()
    {
        return $this->start_time;
    }

    /**
     * Get run time
     *
     * @return string
     */
    public function runTime()
    {
        return number_format(microtime(true) - $this->startTime(), 6);
    }

    /**
     * Set with no event emit
     *
     * @param $key
     * @param $value
     */
    public function set($key, $value)
    {
        $this->config->set($key, $value);
    }

    /**
     * Set config as true
     *
     * @param $key
     */
    public function enable($key)
    {
        $this->config->set($key, true);
    }

    /**
     * Set config as false
     *
     * @param $key
     */
    public function disable($key)
    {
        $this->config->set($key, false);
    }

    /**
     * Check config if true
     *
     * @param $key
     * @return bool
     */
    public function enabled($key)
    {
        return $this->config->set($key) === true;
    }

    /**
     * Check config if false
     *
     * @param $key
     * @return bool
     */
    public function disabled($key)
    {
        return $this->config->set($key) === false;
    }

    /**
     * Config mode
     *
     * # Manuel call must before App::init
     *
     * @param string|\Closure $mode
     * @return callable|null|string
     */
    public function mode($mode = null)
    {
        if ($mode) {
            $this->mode = $mode;
        }
        return $this->mode && is_string($this->mode) ? $this->mode : null;
    }

    /**
     * Configure app
     *
     * @param string|\Closure $mode
     * @param \Closure        $closure
     */
    public function configure($mode, \Closure $closure = null)
    {
        if ($closure === null) {
            // Allow set mode get method when mode is closure
            if ($mode instanceof \Closure) {
                $this->emitter->on('mode', $mode);
            }
        } else {
            // Set trigger for the mode
            $this->emitter->on('mode:' . $mode, $closure);
            // Don not change the current mode
        }
    }

    /**
     * Add middleware
     *
     * @param Middleware|\Closure|string $middleware
     * @throws \Exception
     */
    public function add($middleware)
    {
        // Check and construct Middleware
        if (is_string($middleware) && is_subclass_of($middleware, Middleware::_CLASS_)) {
            // Class string support
            $middleware = new $middleware();
        } elseif ($middleware instanceof \Closure) {
            // Closure support
            $middleware = new Middleware($middleware);
        } elseif (!$middleware instanceof Middleware) {
            // Not base middleware
            throw new \Exception("Bad middleware can not be added");
        }

        // Add to the end
        $this->middleware[] = $middleware;
    }

    /**
     * Route get method
     *
     * @param string          $path
     * @param \Closure|string $runner
     * @param \Closure|string $more
     * @return mixed
     */
    public function get($path, $runner = null, $more = null)
    {
        // Get config for use
        if ($runner === null) {
            return $this->config->get($path);
        }

        if ($this->_cli || !$this->input->isGet()) return;

        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
    }

    /**
     * Route post method
     *
     * @param string          $path
     * @param \Closure|string $runner
     * @param \Closure|string $more
     */
    public function post($path, $runner, $more = null)
    {
        if ($this->_cli || !$this->input->isPost()) return;

        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
    }

    /**
     * Route put method
     *
     * @param string          $path
     * @param \Closure|string $runner
     * @param \Closure|string $more
     */
    public function put($path, $runner, $more = null)
    {
        if ($this->_cli || !$this->input->isPut()) return;

        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
    }

    /**
     * Route delete method
     *
     * @param string          $path
     * @param \Closure|string $runner
     * @param \Closure|string $more
     */
    public function delete($path, $runner, $more = null)
    {
        if ($this->_cli || !$this->input->isDelete()) return;

        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
    }

    /**
     * Route options method
     *
     * @param string          $path
     * @param \Closure|string $runner
     * @param \Closure|string $more
     */
    public function options($path, $runner, $more = null)
    {
        if ($this->_cli || !$this->input->isOptions()) return;

        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
    }

    /**
     * Route head method
     *
     * @param string          $path
     * @param \Closure|string $runner
     * @param \Closure|string $more
     */
    public function head($path, $runner, $more = null)
    {
        if ($this->_cli || !$this->input->isHead()) return;

        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
    }

    /**
     * Restful route
     *
     * @param string $path
     * @param mixed  $runner
     * @param mixed  $more
     */
    public function rest($path, $runner, $more = null)
    {
        if ($this->_cli) return;

        if ($more !== null) {
            $_args = func_get_args();
            foreach ($_args as $i => &$_arg) {
                if ($i === 0) continue;
                if (is_string($_arg) && !strpos($_arg, '::')) {
                    $_arg .= '::' . strtolower($this->input->method());
                }
            }
            call_user_func_array(array($this->route, 'on'), $_args);
        } else {
            if (is_string($runner) && !strpos($runner, '::')) {
                $runner .= '::' . strtolower($this->input->method());
            }
            $this->route->on($path, $runner);
        }
    }

    /**
     * Add controllers to match route
     */
    public function all()
    {
        throw new \BadMethodCallException('Method App::all is not implements');
    }

    /**
     * Map route
     *
     * @param string          $path
     * @param \Closure|string $runner
     * @param \Closure|string $more
     */
    public function map($path, $runner, $more = null)
    {
        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
    }

    /**
     * Get or set route
     *
     * @param null $path
     * @param null $runner
     * @param null $more
     * @return Route
     */
    public function route($path = null, $runner = null, $more = null)
    {
        if ($path === null) return $this->route;
        if ($runner === null) return $this->route->get($path);
        if ($more !== null) {
            call_user_func_array(array($this->route, 'on'), func_get_args());
        } else {
            $this->route->on($path, $runner);
        }
        return;
    }

    /**
     * Set or get view
     *
     * @param string $name
     * @param string $engine
     * @return string
     */
    public function engine($name, $engine = null)
    {
        if ($engine) {
            $this->engines[$name] = $engine;
        }
        return isset($this->engines[$name]) ? $this->engines[$name] : null;
    }

    /**
     * Render template
     *
     * @param string $path
     * @param array  $data
     */
    public function render($path, $data = array())
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $engine = false;
        if ($ext && isset($this->engines[$ext])) {
            if (is_string($this->engines[$ext]) && class_exists($this->engines[$ext])) {
                $this->engines[$ext] = $engine = new $this->engines[$ext]();
            } else {
                $engine = $this->engines[$ext];
            }
        }
        $view = new View($path, $data + $this->locals, array(
            'engine' => $engine,
            'dir'    => $this->config->views
        ));
        echo $view;
    }

    /**
     * Get root of application
     *
     * @return string
     */
    public function root()
    {
        return rtrim($this->input->env('DOCUMENT_ROOT'), '/') . rtrim($this->input->rootUri(), '/') . '/';
    }

    /**
     * App will run
     *
     */
    public function run()
    {
        if (!$this->mode) {
            // Set mode
            $this->mode = ($_mode = getenv('OMNI_ENV')) ? $_mode : 'development';
        } elseif ($this->mode instanceof \Closure) {
            // Make mode
            $_mode = $this->mode;
            $this->mode = $_mode();
        }

        // Trigger default mode
        $this->emitter->emit('mode', $this->mode);

        // If trigger exists, trigger closure
        $this->emitter->emit('mode:' . $this->mode);

        // run
        $this->emitter->emit('run');

        $this->_run = true;

        $_error = false;
        if ($this->config->error) {
            // If config error, register error handle and set flag
            $_error = true;
            $this->registerErrorHandler();
        }

        // Check middleware list
        if ($this->middleware) {
            if (!in_array($this->route, $this->middleware)) {
                $this->middleware[] = $this->route;
            }
            // Loop middleware
            foreach ($this->middleware as $_i => $_m) {
                // Set next middleware
                if (isset($this->middleware[$_i + 1])) {
                    $_m->setNext($this->middleware[$_i + 1]);
                }
                // Set app
                $_m->setApp($this);
            }
        } else {
            // Set middleware
            $this->middleware[] = $this->route;
        }

        try {
            // Start buffer
            if (!$_buffer_disabled = $this->config->disable_buffer) ob_start();

            // Request app call
            $this->middleware[0]->call();

            // Write direct output to the head of buffer
            if (!$_buffer_disabled) $this->output->write(ob_get_clean());
        } catch (\Exception $e) {
            if ($this->config->debug) {
                throw $e;
            } else {
                $this->error($e);
            }
            $this->emitter->emit('error');
        }

        $this->_run = false;

        // Send start
        $this->emitter->emit('start');

        // Send headers
        if (!$this->_cli) {
            $this->output->sendHeader();
        }

        // Send
        echo $this->output->body();

        // Send end
        $this->emitter->emit('end');

        if ($_error) $this->restoreErrorHandler();
    }

    /**
     * Register or run error
     *
     * @param callable $runner
     */
    public function error($runner = null)
    {
        if (is_callable($runner) && !$runner instanceof \Exception) {
            $this->route->set('error', $runner);
        } else {
            ob_get_level() && ob_clean();
            ob_start();
            if (!$this->route->run('error', array($runner))) {
                echo 'Error occurred';
            }
            $this->output(500, ob_get_clean());
        }
    }

    /**
     * Register or run not found
     *
     * @param callable $runner
     */
    public function notFound($runner = null)
    {
        if (is_callable($runner) && !$runner instanceof \Exception) {
            $this->route->set('404', $runner);
        } else {
            ob_get_level() && ob_clean();
            ob_start();
            if (!$this->route->run('404', array($runner))) {
                echo 'Path not found';
            }
            $this->output(404, ob_get_clean());
        }
    }

    /**
     * Register or run not found
     *
     * @param callable $runner
     */
    public function crash($runner = null)
    {
        if (is_callable($runner) && !$runner instanceof \Exception) {
            $this->route->set('crash', $runner);
        } else {
            ob_get_level() && ob_clean();
            ob_start();
            if (!$this->route->run('crash', array($runner))) {
                echo 'App is down';
            }
            $this->output(500, ob_get_clean());
        }
    }

    /**
     * Output the response
     *
     * @param int    $status
     * @param string $body
     */
    public function output($status, $body)
    {
        $this->output->status($status)->body($body);
        if (!$this->_cli) {
            $this->output->sendHeader();
        }
        echo $this->output->body();
    }

    /**
     * Stop
     *
     * @throws Exception\Stop
     */
    public function stop()
    {
        throw new Exception\Stop();
    }

    /**
     * Pass
     *
     * @throws Exception\Pass
     */
    public function pass()
    {
        ob_get_level() && ob_clean();
        throw new Exception\Pass();
    }

    /**
     * Get or set param
     *
     * @param string|null $param
     * @return array|bool|null
     */
    public function param($param = null)
    {
        if ($param === null) {
            return $this->input->params;
        } else {
            if (is_array($param)) {
                $this->input->params = $param;
                return true;
            } else {
                return isset($this->input->params[$param]) ? $this->input->params[$param] : null;
            }
        }
    }

    /**
     * Register error and exception handlers
     *

     */
    public function registerErrorHandler()
    {
        set_error_handler(array($this, '__error'));
    }

    /**
     * Restore error and exception handlers
     *

     */
    public function restoreErrorHandler()
    {
        restore_error_handler();
    }

    /**
     * Auto load class
     *
     * @param $class
     * @return bool
     */
    protected function __autoload($class)
    {
        if ($class{0} == '\\') $class = ltrim($class, '\\');

        // If with OmniApp path, force require
        if (substr($class, 0, strlen(__NAMESPACE__) + 1) == __NAMESPACE__ . '\\') {
            require __DIR__ . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
            return true;
        } else {
            // Set the 99 high order for default autoload
            $available_path = array(99 => $this->config->autoload);
            // Check other namespaces
            if (isset($this->config['autoload_namespaces'])) {
                // Loop namespaces as autoload
                foreach ($this->config['autoload_namespaces'] as $_prefix => $_path) {
                    // Check if match prefix
                    if (($_pos = strpos($class, $_prefix)) === 0) {
                        // Set ordered path
                        $available_path[strlen($_prefix)] = $_path;
                    }
                }
                // Sort by order
                ksort($available_path);
            }

            // No available path, no continue
            if (!$available_path) return false;

            // Set default file name
            $file_name = '';
            // PSR-0 check
            if ($last_pos = strrpos($class, '\\')) {
                $namespace = substr($class, 0, $last_pos);
                $class = substr($class, $last_pos + 1);
                $file_name = str_replace('\\', '/', $namespace) . '/';
            }
            // Get last file name
            $file_name .= str_replace('_', '/', $class) . '.php';
            // Loop available path for check
            foreach ($available_path as $_path) {
                // Check file if exists
                if ($file = stream_resolve_include_path($_path . '/' . $file_name)) {
                    require $file;
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Error handler for app
     *
     * @param $type
     * @param $message
     * @param $file
     * @param $line
     * @throws \ErrorException
     */
    protected function __error($type, $message, $file, $line)
    {
        if (error_reporting() & $type) throw new \ErrorException($message, $type, 0, $file, $line);
    }

    /**
     * Shutdown handler for app
     */
    public function __shutdown()
    {
        $this->emitter->emit('shutdown');
        if (!$this->_run) return;

        if (!$this->config->debug && ($error = error_get_last())
            && in_array($error['type'], array(E_PARSE, E_ERROR, E_USER_ERROR, E_COMPILE_ERROR))
        ) {
            $this->crash();
        }
    }
}