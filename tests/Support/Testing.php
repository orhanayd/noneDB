<?php
// Test access to the engine: hooks, counters, settings and internal methods. Lives with the tests: the
// engine (noneDB.php) never contains it; hooks and counters exist only in the test build (tools/build.php)
namespace noneDB\Tests\Support;

use function array_values, count, get_class, method_exists, property_exists;

use noneDB\Internal\Cache\Runtime;
use noneDB\Internal\Core\Kernel;

/**
 * The one interface tests use to reach the engine's internals instead of Reflection: process-wide hooks
 * and counters (Runtime), one instance's settings and caches (Kernel) and its internal methods.
 * Not part of the engine: tests only.
 */
final class Testing {
    /** @var array<string, string> method name => kernel service property (built on first use) */
    private static $owners = null;

    /**
     * Set the fault hook: callable(string $point, array $context), called at the engine's fault points; it may
     * throw or exit to simulate a failure there. Null removes it.
     */
    public static function setFaultHook(?callable $hook): void {
        Runtime::get()->faultHook = $hook;
    }

    /**
     * A process-wide value: hooks (faultHook, syncFn, flushFn, renameFn, mbstringFn, sweepBudget), counters
     * (directoryListings, segmentReads, indexStats, metrics, ...), limits and caches
     */
    public static function get(string $name) {
        $runtime = Runtime::get();
        self::requireProperty($runtime, $name);
        return $runtime->$name;
    }

    public static function set(string $name, $value): void {
        $runtime = Runtime::get();
        self::requireProperty($runtime, $name);
        $runtime->$name = $value;
    }

    /** A value of one instance: its settings (shardSize, lockTimeout, ...) and caches */
    public static function getOf(\noneDB $db, string $name) {
        $kernel = Kernel::of($db);
        self::requireProperty($kernel, $name);
        return $kernel->$name;
    }

    public static function setOf(\noneDB $db, string $name, $value): void {
        $kernel = Kernel::of($db);
        self::requireProperty($kernel, $name);
        $kernel->$name = $value;
    }

    /** Number of live noneDB instances (their kernels) */
    public static function liveInstances(): int {
        return count(Kernel::$instances);
    }

    /** The kernel of an instance */
    public static function kernel(\noneDB $db): Kernel {
        return Kernel::of($db);
    }

    /** The service of an instance that owns $method (bind a closure to it to call several of its methods) */
    public static function service(\noneDB $db, string $method) {
        return self::serviceOf(Kernel::of($db), $method);
    }

    /**
     * An object standing for the whole engine of an instance: $engine->method(...) calls the internal method
     * of whichever service owns it, $engine->name reads or writes the kernel's value (by reference).
     * Bind a test closure to it with ->call($engine). By-reference arguments don't pass through: use
     * service() for those.
     */
    public static function engine(\noneDB $db) {
        $kernel = Kernel::of($db);
        return new class($kernel) {
            /** @var Kernel */
            private $kernel;

            public function __construct(Kernel $kernel) {
                $this->kernel = $kernel;
            }

            public function __call(string $method, array $args) {
                $service = Testing::serviceOfKernel($this->kernel, $method);
                return (function (string $method, array $args) {
                    return $this->$method(...$args);
                })->call($service, $method, $args);
            }

            public function &__get(string $name) {
                if (!property_exists($this->kernel, $name)) {
                    throw new \InvalidArgumentException('noneDB: no internal value ' . $name);
                }
                return $this->kernel->$name;
            }

            public function __set(string $name, $value) {
                if (!property_exists($this->kernel, $name)) {
                    throw new \InvalidArgumentException('noneDB: no internal value ' . $name);
                }
                $this->kernel->$name = $value;
            }

            public function __isset(string $name) {
                return isset($this->kernel->$name);
            }
        };
    }

    /** @internal for engine() */
    public static function serviceOfKernel(Kernel $kernel, string $method) {
        return self::serviceOf($kernel, $method);
    }

    /**
     * Call an internal method of an instance, whatever its visibility
     * @return mixed
     */
    public static function call(\noneDB $db, string $method, ...$args) {
        $service = self::serviceOf(Kernel::of($db), $method);
        return (function (string $method, array $args) {
            return $this->$method(...$args);
        })->call($service, $method, $args);
    }

    /**
     * Call an internal static method, whatever its visibility: of a service by its name, of any other engine class
     * as 'Class::method' (the class by its short name, e.g. 'ShardFilters::filter')
     * @return mixed
     */
    public static function callStatic(string $method, ...$args) {
        $classes = self::serviceClasses();
        if (strpos($method, '::') !== false) {
            [$short, $method] = explode('::', $method, 2);
            $classes = array_filter(get_declared_classes(), function (string $class) use ($short) {
                return strpos($class, 'noneDB\\Internal\\') === 0 && substr($class, strrpos($class, '\\') + 1) === $short;
            });
        }
        foreach ($classes as $class) {
            if (method_exists($class, $method)) {
                $call = \Closure::bind(function (string $method, array $args) {
                    return static::$method(...$args);
                }, null, $class);
                return $call($method, $args);
            }
        }
        throw new \InvalidArgumentException("noneDB: no internal method $method");
    }

    private static function serviceOf(Kernel $kernel, string $method) {
        if (self::$owners === null) {
            self::$owners = [];
            foreach (Kernel::SERVICES as $property => $class) {
                foreach ((new \ReflectionClass($class))->getMethods() as $reflection) {
                    self::$owners[$reflection->getName()] = self::$owners[$reflection->getName()] ?? $property;
                }
            }
        }
        if (!isset(self::$owners[$method])) {
            throw new \InvalidArgumentException("noneDB: no internal method $method");
        }
        return $kernel->{self::$owners[$method]};
    }

    /** @return string[] the classes of the kernel's services */
    private static function serviceClasses(): array {
        return array_values(Kernel::SERVICES);
    }

    private static function requireProperty($object, string $name): void {
        if (!property_exists($object, $name)) {
            throw new \InvalidArgumentException('noneDB: no internal value ' . $name . ' in ' . get_class($object));
        }
    }
}
