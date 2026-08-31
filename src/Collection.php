<?php

declare(strict_types=1);

namespace BlueprintAU\Collections;

/**
 * A pure, standalone array wrapper. No model imports, no framework coupling.
 *
 * Transforms are immutable — each returns a new instance. This is the one
 * package every other BlueprintAU package can depend on without circularity.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @phpstan-consistent-constructor
 *
 * @implements Enumerable<TKey, TValue>
 * @implements \ArrayAccess<TKey, TValue>
 */
class Collection implements Enumerable, \Countable, \ArrayAccess
{
    /** @var array<TKey, TValue> */
    protected array $items = [];

    /**
     * Create a new collection from the given items.
     *
     * The constructor is protected — use `make()` (or another factory) to
     * create instances from outside the class.
     *
     * @param iterable<TKey, TValue> $items
     */
    protected function __construct(iterable $items = [])
    {
        $this->items = is_array($items) ? $items : iterator_to_array($items);
    }

    /**
     * Create a new collection from the given items.
     *
     * Convenience static factory equivalent to `new static($items)`.
     *
     * @param iterable<TKey, TValue> $items
     * @return static
     */
    public static function make(iterable $items = []): static
    {
        return new static($items);
    }

    /**
     * Wrap the given value in a collection.
     *
     * If the value is already a collection it is returned unchanged; if it is
     * an array it is used directly; otherwise it is wrapped in a single-item
     * collection. Useful for normalizing "one or many" inputs.
     *
     * @param mixed $value
     * @return static
     */
    public static function wrap(mixed $value): static
    {
        if ($value instanceof static) {
            return $value;
        }

        return new static(is_array($value) ? $value : [$value]);
    }

    /**
     * Create a collection by invoking the callback N times.
     *
     * The callback receives the 1-based index. Useful for generating
     * sequences of items.
     *
     * @param int $number
     * @param (callable(int): TValue)|null $callback
     * @return static
     */
    public static function times(int $number, ?callable $callback = null): static
    {
        if ($number < 1) {
            return new static();
        }

        return (new static(range(1, $number)))->map($callback ?? fn ($i) => $i);
    }

    /**
     * Create a collection of numbers in the given range.
     *
     * Mirrors PHP's `range()`: when $to is less than $from, the sequence is
     * generated in descending order. A step of zero returns an empty
     * collection rather than throwing.
     *
     * @param int|float|string $from
     * @param int|float|string $to
     * @param int|float $step
     * @return static
     */
    public static function range(int|float|string $from, int|float|string $to, int|float $step = 1): static
    {
        if ($step == 0) {
            return new static();
        }
        return new static(range($from, $to, $step));
    }

    /**
     * Safe value access for arrays and objects — returns null on a missing
     * key/property (no error). This is the package's own data_get equivalent,
     * since the package has no dependencies.
     *
     * @param TValue $item
     * @param (TValue is array ? key-of<TValue> : string) $key
     * @return mixed
     */
    protected function value(mixed $item, int|string $key): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }
        if (is_object($item)) {
            return $item->{$key} ?? null;
        }
        return null;
    }

    // ---- Transforms (immutable — return new instances) ----

    /**
     * Map each item through a callback, producing a new collection.
     *
     * The callback receives the item and its key. Keys are preserved.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function map(callable $callback): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            $results[$key] = $callback($item, $key);
        }
        return new static($results);
    }

    /**
     * Filter the collection to items that pass the given callback.
     *
     * When no callback is given, truthy items are kept. Keys are preserved.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @return static
     */
    public function filter(?callable $callback = null): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            if (($callback ?? fn ($v) => (bool) $v)($item, $key)) {
                $results[$key] = $item;
            }
        }
        return new static($results);
    }

    /**
     * Pluck a single column's value from each item.
     *
     * When a key column is given, the result is keyed by that column's value;
     * otherwise the result is a plain list.
     *
     * @param (TValue is array ? key-of<TValue> : string) $value
     * @param ((TValue is array ? key-of<TValue> : string)|null) $key
     * @return static
     */
    public function pluck(int|string $value, int|string|null $key = null): static
    {
        $results = [];
        foreach ($this->items as $item) {
            $itemValue = $this->value($item, $value);
            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $results[$this->value($item, $key)] = $itemValue;
            }
        }
        return new static($results);
    }

    /**
     * Re-key the collection by a given column's value.
     *
     * Later items with a duplicate key overwrite earlier ones.
     *
     * @param (TValue is array ? key-of<TValue> : string) $key
     * @return static
     */
    public function keyBy(int|string $key): static
    {
        $results = [];
        foreach ($this->items as $item) {
            $results[$this->value($item, $key)] = $item;
        }
        return new static($results);
    }

    /**
     * Group the collection's items by a column or callback.
     *
     * The result is keyed by the group value, each holding a list of the
     * items in that group.
     *
     * @param ((TValue is array ? key-of<TValue> : string)|callable(TValue, TKey): mixed) $groupBy
     * @return static<(int|string), non-empty-list<TValue>>
     */
    public function groupBy(int|string|callable $groupBy): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            $groupKey = is_callable($groupBy) ? $groupBy($item, $key) : $this->value($item, $groupBy);
            $results[$groupKey][] = $item;
        }
        return new static($results);
    }

    /**
     * Map each item to an associative array and merge the results.
     *
     * The callback must return an array; later keys overwrite earlier ones.
     *
     * @param callable(TValue, TKey): array<mixed, mixed> $callback
     * @return static
     */
    public function mapWithKeys(callable $callback): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            $assoc = $callback($item, $key);
            foreach ($assoc as $mapKey => $mapValue) {
                $results[$mapKey] = $mapValue;
            }
        }
        return new static($results);
    }

    /**
     * Map each item through a callback, then collapse the result one level.
     *
     * Equivalent to `map($callback)->collapse()`.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->collapse();
    }

    /**
     * Collapse a collection of arrays into a single flat collection.
     *
     * Non-array items are skipped.
     *
     * @return static
     */
    public function collapse(): static
    {
        $results = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                $results = array_merge($results, $item);
            }
        }
        return new static($results);
    }

    // ---- Reductions ----

    /**
     * Reduce the collection to a single value using a callback.
     *
     * The callback receives the carry, the item, and the item's key.
     *
     * @template TCarry
     * @param callable(TCarry, TValue, TKey): TCarry $callback
     * @param TCarry $initial
     * @return TCarry
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $carry = $initial;
        foreach ($this->items as $key => $item) {
            $carry = $callback($carry, $item, $key);
        }
        return $carry;
    }

    /**
     * Sum the collection's values, or a single column of each item.
     *
     * @param string|callable(TValue): mixed|null $column
     * @return int|float
     */
    public function sum(string|callable|null $column = null): int|float
    {
        if ($column === null) {
            return array_sum($this->items);
        }
        if (is_callable($column)) {
            $sum = 0;
            foreach ($this->items as $item) {
                $sum += $column($item);
            }
            return $sum;
        }
        return $this->pluck($column)->sum();
    }

    /**
     * Average the collection's values, or a single column of each item.
     *
     * Returns 0 for an empty collection.
     *
     * @param string|callable(TValue): mixed|null $column
     * @return int|float
     */
    public function avg(string|callable|null $column = null): int|float
    {
        $count = $this->count();
        return $count ? $this->sum($column) / $count : 0;
    }

    /**
     * Get the minimum value, or the minimum of a single column.
     *
     * Returns null for an empty collection.
     *
     * @param string|callable(TValue): mixed|null $column
     * @return mixed
     */
    public function min(string|callable|null $column = null): mixed
    {
        if ($this->items === []) {
            return null;
        }
        if ($column === null) {
            return min($this->items);
        }
        if (is_callable($column)) {
            $min = null;
            foreach ($this->items as $item) {
                $value = $column($item);
                if ($min === null || $value < $min) {
                    $min = $value;
                }
            }
            return $min;
        }
        return $this->pluck($column)->min();
    }

    /**
     * Get the maximum value, or the maximum of a single column.
     *
     * Returns null for an empty collection.
     *
     * @param string|callable(TValue): mixed|null $column
     * @return mixed
     */
    public function max(string|callable|null $column = null): mixed
    {
        if ($this->items === []) {
            return null;
        }
        if ($column === null) {
            return max($this->items);
        }
        if (is_callable($column)) {
            $max = null;
            foreach ($this->items as $item) {
                $value = $column($item);
                if ($max === null || $value > $max) {
                    $max = $value;
                }
            }
            return $max;
        }
        return $this->pluck($column)->max();
    }

    /**
     * Count the number of items in the collection.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Determine whether the collection contains a given item.
     *
     * Supports three call signatures:
     *  - contains($value)          — strict value membership
     *  - contains(callable)        — any item passes the predicate
     *  - contains($key, $value) / contains($key, $value, $operator)
     *                              — comparison of a column against a value
     *
     * The column form compares `value($item, $key)` against `$value` using the
     * given operator (defaulting to loose equality). The operator is a
     * `ComparisonOperator` enum, so an invalid operator is a compile-time error
     * rather than a silently-wrong result.
     *
     * @param mixed $key
     * @param mixed $value
     * @param ComparisonOperator $operator
     * @return bool
     */
    public function contains(mixed $key, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::LooseEquals): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key)) {
                return $this->some($key);
            }
            return in_array($key, $this->items, true);
        }

        return $this->contains(fn ($item) => $this->compare($this->value($item, $key), $operator, $value));
    }

    /**
     * Compare a column value against a target using a comparison operator.
     *
     * The match is exhaustive over `ComparisonOperator`, so every case is
     * handled explicitly and there is no silent fallback. `Equals` is strict
     * (`===`); `LooseEquals` is loose (`==`).
     *
     * @param mixed $actual
     * @param ComparisonOperator $operator
     * @param mixed $value
     * @return bool
     */
    protected function compare(mixed $actual, ComparisonOperator $operator, mixed $value): bool
    {
        return match ($operator) {
            ComparisonOperator::Equals => $actual === $value,
            ComparisonOperator::LooseEquals => $actual == $value,
            ComparisonOperator::NotEquals => $actual != $value,
            ComparisonOperator::GreaterThan => $actual > $value,
            ComparisonOperator::GreaterThanOrEqual => $actual >= $value,
            ComparisonOperator::LessThan => $actual < $value,
            ComparisonOperator::LessThanOrEqual => $actual <= $value,
            ComparisonOperator::In => is_array($value) && in_array($actual, $value, true),
            ComparisonOperator::NotIn => is_array($value) && !in_array($actual, $value, true),
        };
    }

    /**
     * Filter the collection to items whose column matches a value.
     *
     * Returns a new collection of the items where `value($item, $key)`
     * compares against `$value` using the given operator (defaulting to loose
     * equality). Keys are preserved.
     *
     * @param mixed $key
     * @param mixed $value
     * @param ComparisonOperator $operator
     * @return static
     */
    public function where(mixed $key, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::LooseEquals): static
    {
        return $this->filter(fn ($item) => $this->compare($this->value($item, $key), $operator, $value));
    }

    /**
     * Determine whether every item passes the given callback.
     *
     * Returns true for an empty collection.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return bool
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determine whether any item passes the given callback.
     *
     * @param callable(TValue, TKey): bool $callback
     * @return bool
     */
    public function some(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return true;
            }
        }
        return false;
    }

    // ---- Iteration ----

    /**
     * Execute a callback over each item.
     *
     * Returning `false` from the callback stops iteration early. The
     * collection is returned unchanged for chaining.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return $this
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }
        return $this;
    }

    /**
     * Get the first item, optionally the first matching a callback.
     *
     * Returns the given default (or null) when nothing matches.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            $key = array_key_first($this->items);
            return $key === null ? $default : $this->items[$key];
        }
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }
        return $default;
    }

    /**
     * Get the last item, optionally the last matching a callback.
     *
     * Returns the given default (or null) when nothing matches.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            $key = array_key_last($this->items);
            return $key === null ? $default : $this->items[$key];
        }
        foreach (array_reverse(array_keys($this->items)) as $key) {
            if ($callback($this->items[$key], $key)) {
                return $this->items[$key];
            }
        }
        return $default;
    }

    /**
     * Reset the collection's keys to a sequential 0-based list.
     *
     * @return static
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get the collection's keys as a new collection.
     *
     * @return static
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    // ---- Selection ----

    /**
     * Take the first N items.
     *
     * @param int $limit
     * @return static
     */
    public function take(int $limit): static
    {
        return new static(array_slice($this->items, 0, $limit));
    }

    /**
     * Skip the first N items.
     *
     * @param int $count
     * @return static
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    /**
     * Take a slice of the collection starting at the given offset.
     *
     * @param int $offset
     * @param int|null $length
     * @return static
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    /**
     * Remove duplicate items from the collection.
     *
     * When a key is given, items are deduplicated by that column's value
     * (keeping the first occurrence). When strict is true, values are
     * compared with type checking.
     *
     * @param string|null $key
     * @param bool $strict
     * @return static
     */
    public function unique(?string $key = null, bool $strict = false): static
    {
        if ($key === null) {
            $seen = [];
            $result = [];
            foreach ($this->items as $k => $item) {
                if (!$this->isSeen($item, $seen, true)) {
                    $result[$k] = $item;
                }
            }
            return new static($result);
        }
        $seen = [];
        return $this->filter(function ($item) use ($key, $strict, &$seen) {
            return !$this->isSeen($this->value($item, $key), $seen, $strict);
        });
    }

    /**
     * Determine whether a value has already been seen, tracking it if not.
     *
     * Uses an O(1) hash lookup for scalar values; falls back to a linear scan
     * for non-scalar values (arrays/objects) that cannot be used as array keys.
     * In strict mode the hash key is type-prefixed so that `1` and `'1'` are
     * treated as distinct.
     *
     * @param mixed $value
     * @param array<int|string, mixed> $seen
     * @param bool $strict
     * @return bool
     */
    protected function isSeen(mixed $value, array &$seen, bool $strict): bool
    {
        if (is_scalar($value) || $value === null) {
            $key = $strict
                ? get_debug_type($value) . ':' . (string) $value
                : (string) $value;
            if (array_key_exists($key, $seen)) {
                return true;
            }
            $seen[$key] = true;
            return false;
        }
        foreach ($seen as $existing) {
            if ($strict ? $existing === $value : $existing == $value) {
                return true;
            }
        }
        $seen[] = $value;
        return false;
    }

    /**
     * Sort the collection, optionally with a custom comparator.
     *
     * Keys are preserved. Without a callback, items are sorted by value
     * using `asort`.
     *
     * @param (callable(TValue, TValue): int)|null $callback
     * @return static
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        $callback ? uasort($items, $callback) : asort($items);
        return new static($items);
    }

    /**
     * Sort the collection by a column or callback.
     *
     * Keys are preserved. Set descending to true for reverse order. The
     * $options flag is passed through to the underlying comparison:
     *  - SORT_NUMERIC — numeric comparison
     *  - SORT_STRING  — lexical (string) comparison
     *  - SORT_REGULAR (default) — numeric-aware when both values are numeric,
     *    otherwise lexical
     *
     * @param string|callable(TValue): mixed $column
     * @param int $options
     * @param bool $descending
     * @return static
     */
    public function sortBy(string|callable $column, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $results = $this->items;
        $callback = is_callable($column) ? $column : fn ($item) => $this->value($item, $column);
        uasort($results, function ($a, $b) use ($callback, $options, $descending) {
            $aVal = $callback($a);
            $bVal = $callback($b);
            $cmp = match ($options) {
                SORT_NUMERIC => $aVal <=> $bVal,
                SORT_STRING => strcmp((string) $aVal, (string) $bVal),
                default => is_numeric($aVal) && is_numeric($bVal)
                    ? $aVal <=> $bVal
                    : strcmp((string) $aVal, (string) $bVal),
            };
            return $descending ? -$cmp : $cmp;
        });
        return new static($results);
    }

    /**
     * Reverse the order of the collection's items.
     *
     * Keys are preserved.
     *
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    // ---- Conversion ----

    /**
     * Convert the collection to a plain array.
     *
     * Nested collections are recursively converted to arrays.
     *
     * @return array<TKey, mixed>
     */
    public function toArray(): array
    {
        return array_map(
            fn ($value) => $value instanceof Enumerable ? $value->toArray() : $value,
            $this->items
        );
    }

    /**
     * Convert the collection to a JSON string.
     *
     * @param int $options
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize the collection to a JSON-encodable array.
     *
     * @return array<TKey, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the underlying items array.
     *
     * Unlike toArray, nested collections are not recursively converted.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    // ---- ArrayAccess / Countable / IteratorAggregate ----

    /**
     * Determine whether an item exists at the given offset.
     *
     * Uses array_key_exists so that an item whose value is null still counts
     * as present (isset() would report it as absent).
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    /**
     * Get the item at the given offset, or null if it does not exist.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Writing to a collection is not supported.
     *
     * Collections are immutable — use a transform (map, filter, etc.) to
     * derive a new collection instead of mutating this one in place.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     * @throws \BadMethodCallException Always.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('Collection is immutable; use a transform instead.');
    }

    /**
     * Removing an item from a collection is not supported.
     *
     * Collections are immutable — use a transform (filter, etc.) to derive a
     * new collection instead of mutating this one in place.
     *
     * @param mixed $offset
     * @return void
     * @throws \BadMethodCallException Always.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('Collection is immutable; use a transform instead.');
    }

    /**
     * Get an iterator for the collection's items.
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}