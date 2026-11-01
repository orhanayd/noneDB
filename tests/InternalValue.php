<?php

namespace noneDB\Tests;

/**
 * An internal value of the engine, read and written through noneDB\Tests\Support\Testing (instead of
 * Reflection): with a noneDB instance a value of that instance (settings, caches), without one a
 * process-wide value (hooks, counters, caches)
 */
final class InternalValue
{
    /** @var string */
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /** @return mixed */
    public function getValue(?\noneDB $db = null)
    {
        return $db === null ? \noneDB\Tests\Support\Testing::get($this->name) : \noneDB\Tests\Support\Testing::getOf($db, $this->name);
    }

    public function setValue(?\noneDB $db, $value): void
    {
        $db === null ? \noneDB\Tests\Support\Testing::set($this->name, $value) : \noneDB\Tests\Support\Testing::setOf($db, $this->name, $value);
    }
}
