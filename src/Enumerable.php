<?php

declare(strict_types=1);

namespace BlueprintAU\Collections;

/**
 * The contract shared by eager (Collection) and lazy (LazyCollection)
 * implementations. Any collection is foreach-able and JSON-serializable.
 *
 * Only methods that behave identically whether the data is eagerly in memory
 * or lazily streamed belong here. Anything that forces materialization
 * (count, sort, unique, toArray) is intentionally excluded.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends \IteratorAggregate<TKey, TValue>
 */
interface Enumerable extends \IteratorAggregate, \JsonSerializable
{
    // ---- Transforms (lazy-compatible) ----

    /**
     * Map each item through a callback, producing a new collection.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function map(callable $callback): static;

    /**
     * Filter the collection to items that pass the given callback.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @return static
     */
    public function filter(?callable $callback = null): static;

    /**
     * Pluck a single column's value from each item.
     *
     * @param (TValue is array ? key-of<TValue> : string) $value
     * @param ((TValue is array ? key-of<TValue> : string)|null) $key
     * @return static
     */
    public function pluck(int|string $value, int|string|null $key = null): static;

    /**
     * Re-key the collection by a given column's value.
     *
     * @param (TValue is array ? key-of<TValue> : string) $key
     * @return static
     */
    public function keyBy(int|string $key): static;

    /**
     * Group the collection's items by a column or callback.
     *
     * @param ((TValue is array ? key-of<TValue> : string)|callable(TValue, TKey): mixed) $groupBy
     * @return static
     */
    public function groupBy(int|string|callable $groupBy): static;

    /**
     * Map each item to an associative array and merge the results.
     *
     * @param callable(TValue, TKey): array<mixed, mixed> $callback
     * @return static
     */
    public function mapWithKeys(callable $callback): static;

    /**
     * Map each item through a callback, then collapse the result one level.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function flatMap(callable $callback): static;

    // ---- Reductions (lazy-compatible) ----

    /**
     * Reduce the collection to a single value using a callback.
     *
     * @template TCarry
     * @param callable(TCarry, TValue, TKey): TCarry $callback
     * @param TCarry $initial
     * @return TCarry
     */
    public function reduce(callable $callback, mixed $initial = null): mixed;

    /**
     * Sum the collection's values, or a single column of each item.
     *
     * @param string|callable(TValue): mixed|null $column
     * @return int|float
     */
    public function sum(string|callable|null $column = null): int|float;

    /**
     * Average the collection's values, or a single column of each item.
     *
     * @param string|callable(TValue): mixed|null $column
     * @return int|float
     */
    public function avg(string|callable|null $column = null): int|float;

    /**
     * Get the minimum value, or the minimum of a single column.
     *
     * @template TColumn
     * @param string|callable(TValue): TColumn|null $column
     * @return TColumn|TValue
     */
    public function min(string|callable|null $column = null): mixed;

    /**
     * Get the maximum value, or the maximum of a single column.
     *
     * @template TColumn
     * @param string|callable(TValue): TColumn|null $column
     * @return TColumn|TValue
     */
    public function max(string|callable|null $column = null): mixed;

    /**
     * Determine whether the collection contains a given item.
     *
     * @param mixed $key
     * @param mixed $value
     * @param ComparisonOperator $operator
     * @return bool
     */
    public function contains(mixed $key, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::LooseEquals): bool;

    /**
     * Filter the collection to items whose column matches a value.
     *
     * @param mixed $key
     * @param mixed $value
     * @param ComparisonOperator $operator
     * @return static
     */
    public function where(mixed $key, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::LooseEquals): static;

    /**
     * Determine whether every item passes the given callback.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return bool
     */
    public function every(callable $callback): bool;

    /**
     * Determine whether any item passes the given callback.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return bool
     */
    public function some(callable $callback): bool;

    // ---- Iteration (lazy-compatible) ----

    /**
     * Execute a callback over each item.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function each(callable $callback): static;

    /**
     * Get the first item, optionally the first matching a callback.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed;

    /**
     * Get the last item, optionally the last matching a callback.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function last(?callable $callback = null, mixed $default = null): mixed;

    /**
     * Reset the collection's keys to a sequential 0-based list.
     *
     * @return static
     */
    public function values(): static;

    /**
     * Get the collection's keys as a new collection.
     *
     * @return static
     */
    public function keys(): static;

    // ---- Selection (lazy-compatible) ----

    /**
     * Take the first N items.
     *
     * @param int $limit
     * @return static
     */
    public function take(int $limit): static;

    /**
     * Skip the first N items.
     *
     * @param int $count
     * @return static
     */
    public function skip(int $count): static;

    /**
     * Take a slice of the collection starting at the given offset.
     *
     * @param int $offset
     * @param int|null $length
     * @return static
     */
    public function slice(int $offset, ?int $length = null): static;

    /**
     * Convert the collection to a plain array.
     *
     * Nested collections are recursively converted to arrays. This is a
     * terminal operation — a lazy collection materializes on call.
     *
     * @return array<TKey, mixed>
     */
    public function toArray(): array;
}