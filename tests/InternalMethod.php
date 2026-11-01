<?php

namespace noneDB\Tests;

/**
 * An internal method of the engine, called through noneDB\Tests\Support\Testing (instead of Reflection):
 * ->invoke($db, ...$args) calls it for that instance, ->invoke(null, ...) calls a static one
 */
final class InternalMethod
{
    /** @var string */
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /** @return mixed */
    public function invoke(?\noneDB $db, ...$args)
    {
        return $db === null ? \noneDB\Tests\Support\Testing::callStatic($this->name, ...$args)
            : \noneDB\Tests\Support\Testing::call($db, $this->name, ...$args);
    }

    /** @return mixed */
    public function invokeArgs(?\noneDB $db, array $args)
    {
        return $this->invoke($db, ...$args);
    }
}
