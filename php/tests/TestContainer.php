<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Tests;

use Psr\Container\ContainerInterface;

/**
 * The slice of PHP-DI a module touches: `set()` with a factory, `get()` that
 * resolves it once, `has()`. The composed app's container is PHP-DI; this
 * keeps the extension's suite free of it.
 */
final class TestContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    public function set(string $id, mixed $value): void
    {
        $this->entries[$id] = $value;
        unset($this->resolved[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }
        if (!array_key_exists($id, $this->entries)) {
            throw new \RuntimeException("unbound: {$id}");
        }
        $entry = $this->entries[$id];
        return $this->resolved[$id] = $entry instanceof \Closure ? $entry($this) : $entry;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
