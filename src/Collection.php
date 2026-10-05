<?php

declare(strict_types=1);

namespace BlueprintAU\Collections;

/**
 * A pure, standalone array wrapper with no framework coupling.
 *
 * @template  TKey of array-key
 * @template  TValue
 *
 * @phpstan-consistent-constructor
 *
 * @implements  Enumerable<TKey, TValue>
 * @implements  \ArrayAccess<TKey, TValue>
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
     * @param  iterable<TKey, TValue> $items
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
     * @template  TMakeKey of array-key
     * @template  TMakeValue
     *
     * @param  iterable<TMakeKey, TMakeValue> $items
     * @return  static<TMakeKey, TMakeValue>
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
     * @template  TWrapValue
     *
     * @param  TWrapValue $value
     * @return  (TWrapValue is static ? TWrapValue : (TWrapValue is array ? static<int|string, value-of<TWrapValue>> : static<int, TWrapValue>))
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
     * The callback receives the 1-based index.
     *
     * @template  TTimesValue
     *
     * @param  int $number
     * @param  (callable(int): TTimesValue)|null $callback
     * @return  ($callback is null ? static<int, int> : static<int, TTimesValue>)
     */
    public static function times(int $number, ?callable $callback = null): static
    {
        if ($number < 1) {
            return new static();
        }

        if ($callback === null) {
            /** @var static<int, int> $sequence */
            $sequence = new static(range(1, $number));

            return $sequence;
        }

        /** @var static<int, TTimesValue> $result */
        $result = (new static(range(1, $number)))->map($callback);

        return $result;
    }

    /**
     * Create a collection of numbers in the given range.
     *
     * Mirrors PHP's `range()`: when $to is less than $from, the sequence is
     * generated in descending order. A step of zero returns an empty
     * collection rather than throwing.
     *
     * @param  int|float|string $from
     * @param  int|float|string $to
     * @param  int|float $step
     * @return  static<int, int|float|string>
     */
    public static function range(int|float|string $from, int|float|string $to, int|float $step = 1): static
    {
        if ($step == 0) {
            return new static();
        }
        return new static(range($from, $to, $step));
    }

    /**
     * Get a value from an array or object, or null when the key is missing.
     *
     * A missing object property dispatches magic `__get()`, which may run
     * arbitrary consumer code.
     *
     * @param  TValue $item
     * @param  (TValue is array ? key-of<TValue> : string) $key
     * @return  (TValue is array ? value-of<TValue> : mixed)
     */
    final protected function value(mixed $item, int|string $key): mixed
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
     * @template  TNewValue
     * @param  callable(TValue, TKey): TNewValue $callback
     * @return  static<TKey, TNewValue>
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
     * @param  (callable(TValue, TKey): bool)|null $callback
     * @return  static
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
     * @param  (TValue is array ? key-of<TValue> : string) $value
     * @param  ((TValue is array ? key-of<TValue> : string)|null) $key
     * @return  static<($key is null ? int : int|string), (TValue is array ? value-of<TValue> : mixed)>
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
     * @param  (TValue is array ? key-of<TValue> : string) $key
     * @return  static<int|string, TValue>
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
     * @param  ((TValue is array ? key-of<TValue> : string)|callable(TValue, TKey): mixed) $groupBy
     * @return  static<(int|string), non-empty-list<TValue>>
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
     * @template  TMapKey of array-key
     * @template  TMapValue
     * @param  callable(TValue, TKey): array<TMapKey, TMapValue> $callback
     * @return  static<TMapKey, TMapValue>
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
     * Equivalent to `map($callback)->collapse()`. String keys survive the
     * collapse and integer keys are renumbered, so the resulting key type is
     * int|string.
     *
     * @param  callable(TValue, TKey): mixed $callback
     * @return  static<int|string, mixed>
     */
    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->collapse();
    }

    /**
     * Collapse a collection of arrays into a single flat collection.
     *
     * Non-array items are skipped. Integer keys are renumbered by the merge
     * and string keys are preserved, so the resulting key type is int|string.
     *
     * @return  static<int|string, (TValue is array ? value-of<TValue> : mixed)>
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

    /**
     * Map each item through a callback, dropping null results.
     *
     * Keys are preserved — call `values()` to renumber to a 0-based list.
     *
     * @template  TNewValue
     * @param  callable(TValue, TKey): (TNewValue|null) $callback
     * @return  static<TKey, TNewValue>
     */
    public function filterMap(callable $callback): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            $mapped = $callback($item, $key);
            if ($mapped !== null) {
                $results[$key] = $mapped;
            }
        }
        return new static($results);
    }

    /**
     * Filter the collection to items that fail the given callback.
     *
     * When no callback is given, truthy items are dropped. Keys are preserved.
     *
     * @param  (callable(TValue, TKey): bool)|null $callback
     * @return  static
     */
    public function reject(?callable $callback = null): static
    {
        return $this->filter(fn ($item, $key) => !($callback ?? fn ($v) => (bool) $v)($item, $key));
    }

    // ---- Reductions ----

    /**
     * Reduce the collection to a single value using a callback.
     *
     * The callback receives the carry, the item, and the item's key.
     *
     * @template  TCarry
     * @param  callable(TCarry, TValue, TKey): TCarry $callback
     * @param  TCarry $initial
     * @return  TCarry
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
     * Non-numeric items throw a TypeError, matching the lazy implementation.
     *
     * @param  (TValue is array ? key-of<TValue> : string)|(callable(TValue): (int|float))|null $column
     * @return  int|float
     */
    public function sum(int|string|callable|null $column = null): int|float
    {
        if ($column === null) {
            $sum = 0;
            foreach ($this->items as $item) {
                $sum += $item;
            }
            return $sum;
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
     * @param  (TValue is array ? key-of<TValue> : string)|(callable(TValue): (int|float))|null $column
     * @return  int|float
     */
    public function avg(int|string|callable|null $column = null): int|float
    {
        $count = $this->count();
        return $count ? $this->sum($column) / $count : 0;
    }

    /**
     * Get the minimum value, or the minimum of a single column.
     *
     * Returns null for an empty collection. Comparison uses PHP's `<`
     * operator, which assumes homogeneous, comparable values.
     *
     * @template  TColumn
     * @param  (callable(TValue): TColumn)|(TValue is array ? key-of<TValue> : string)|null $column
     * @return  ($column is null ? (TValue is array ? value-of<TValue> : mixed)|null : ($column is callable ? TColumn : (TValue is array ? value-of<TValue> : mixed)))
     */
    public function min(int|string|callable|null $column = null): mixed
    {
        $min = null;
        $has = false;
        foreach ($this->items as $item) {
            $value = $column === null
                ? $item
                : (is_callable($column) ? $column($item) : $this->value($item, $column));
            if (!$has || $value < $min) {
                $min = $value;
                $has = true;
            }
        }
        return $has ? $min : null;
    }

    /**
     * Get the maximum value, or the maximum of a single column.
     *
     * Returns null for an empty collection. Comparison uses PHP's `>`
     * operator, which assumes homogeneous, comparable values.
     *
     * @template  TColumn
     * @param  (callable(TValue): TColumn)|(TValue is array ? key-of<TValue> : string)|null $column
     * @return  ($column is null ? (TValue is array ? value-of<TValue> : mixed)|null : ($column is callable ? TColumn : (TValue is array ? value-of<TValue> : mixed)))
     */
    public function max(int|string|callable|null $column = null): mixed
    {
        $max = null;
        $has = false;
        foreach ($this->items as $item) {
            $value = $column === null
                ? $item
                : (is_callable($column) ? $column($item) : $this->value($item, $column));
            if (!$has || $value > $max) {
                $max = $value;
                $has = true;
            }
        }
        return $has ? $max : null;
    }

    /**
     * Count the number of items in the collection.
     *
     * @return  int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Determine whether the collection contains a given item.
     *
     * With one argument, membership is strict value equality — a callable
     * value is checked as a value, never invoked as a predicate. With a
     * column and value, the column is compared using the given operator.
     *
     * @param  mixed  $key  A value to find, or a column name for the comparison form.
     * @param  mixed  $value  The value the column is compared against.
     * @param  ComparisonOperator  $operator
     * @return  bool
     */
    public function contains(mixed $key, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::Equals): bool
    {
        if (func_num_args() === 1) {
            return in_array($key, $this->items, true);
        }

        return $this->some(fn ($item) => $operator->compare($this->value($item, $key), $value));
    }

    /**
     * Filter the collection to items whose column matches a value.
     *
     * Keys are preserved. On a collection of scalar items, `value()` returns
     * null for every item, so the filter keeps nothing (or everything, for
     * NotEquals/NotIn) — use `filter()` with a predicate instead.
     *
     * @param  (TValue is array ? key-of<TValue> : string) $key
     * @param  mixed $value
     * @param  ComparisonOperator $operator
     * @return  static
     */
    public function where(mixed $key, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::Equals): static
    {
        return $this->filter(fn ($item) => $operator->compare($this->value($item, $key), $value));
    }

    /**
     * Determine whether every item passes the given callback.
     *
     * Returns true for an empty collection.
     *
     * @param  callable(TValue, TKey): bool $callback
     * @return  bool
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
     * @param  callable(TValue, TKey): bool $callback
     * @return  bool
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

    /**
     * Determine whether the collection is empty.
     *
     * @return  bool
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Determine whether the collection is not empty.
     *
     * @return  bool
     */
    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * Get the single item matching the given criteria.
     *
     * With no arguments the collection must hold exactly one item. With one
     * argument, matching is strict value equality — a callable value is
     * checked as a value, never invoked as a predicate. With a column and
     * value, the column is compared using the given operator.
     *
     * @param  mixed  $key  A value to find, or a column name for the comparison form.
     * @param  mixed  $value  The value the column is compared against.
     * @param  ComparisonOperator  $operator
     * @return  TValue
     *
     * @throws \LogicException When no item, two items, or three or more items match.
     */
    public function sole(mixed $key = null, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::Equals): mixed
    {
        $predicate = match (func_num_args()) {
            0 => fn ($item, $itemKey) => true,
            1 => fn ($item, $itemKey) => $item === $key,
            default => fn ($item, $itemKey) => $operator->compare($this->value($item, $key), $value),
        };

        $found = null;
        $matches = 0;
        foreach ($this->items as $itemKey => $item) {
            if ($predicate($item, $itemKey)) {
                $found = $item;
                if (++$matches === 3) {
                    throw new \LogicException('2 or more items match — the collection must contain exactly one matching item.');
                }
            }
        }

        if ($matches === 0) {
            throw new \LogicException('Item not found — the collection must contain exactly one matching item.');
        }
        if ($matches === 2) {
            throw new \LogicException('2 items match — the collection must contain exactly one matching item.');
        }

        /** @var TValue $found */
        return $found;
    }

    /**
     * Get the first item whose column matches a value.
     *
     * Returns null when nothing matches — chain `first()` for a custom default.
     *
     * @param  (TValue is array ? key-of<TValue> : string) $key
     * @param  mixed $value
     * @param  ComparisonOperator $operator
     * @return  TValue|null
     */
    public function firstWhere(int|string $key, mixed $value = null, ComparisonOperator $operator = ComparisonOperator::Equals): mixed
    {
        foreach ($this->items as $item) {
            if ($operator->compare($this->value($item, $key), $value)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Filter the collection to items that are null.
     *
     * With no column, the item itself is checked; otherwise the column's
     * value is checked. Keys are preserved.
     *
     * @param  ((TValue is array ? key-of<TValue> : string)|null) $key
     * @return  static
     */
    public function whereNull(int|string|null $key = null): static
    {
        return $this->filter(function ($item) use ($key) {
            return $key === null ? $item === null : $this->value($item, $key) === null;
        });
    }

    /**
     * Filter the collection to items that are not null.
     *
     * With no column, the item itself is checked; otherwise the column's
     * value is checked. Keys are preserved.
     *
     * @param  ((TValue is array ? key-of<TValue> : string)|null) $key
     * @return  static
     */
    public function whereNotNull(int|string|null $key = null): static
    {
        return $this->filter(function ($item) use ($key) {
            return $key === null ? $item !== null : $this->value($item, $key) !== null;
        });
    }

    /**
     * Join the collection's values, or a single column of each item.
     *
     * @param  string $glue
     * @param  ((TValue is array ? key-of<TValue> : string)|null) $column
     * @return  string
     */
    public function implode(string $glue, int|string|null $column = null): string
    {
        $parts = [];
        foreach ($this->items as $item) {
            $parts[] = (string) ($column === null ? $item : $this->value($item, $column));
        }
        return implode($glue, $parts);
    }

    // ---- Iteration ----

    /**
     * Execute a callback over each item.
     *
     * Returning `false` from the callback stops iteration early. The
     * collection is returned unchanged for chaining.
     *
     * @param  callable(TValue, TKey): mixed $callback
     * @return  $this
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
     * @template  TDefault
     * @param  (callable(TValue, TKey): bool)|null $callback
     * @param  TDefault $default
     * @return  TValue|TDefault
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
     * @template  TDefault
     * @param  (callable(TValue, TKey): bool)|null $callback
     * @param  TDefault $default
     * @return  TValue|TDefault
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
     * @return  static
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get the collection's keys as a new collection.
     *
     * @return  static
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    // ---- Selection ----

    /**
     * Take the first N items.
     *
     * Mirrors `array_slice($items, 0, $limit, true)`: a negative $limit omits
     * the last |$limit| items. Original keys are always preserved — call
     * `values()` to renumber to a 0-based list.
     *
     * @param  int $limit
     * @return  static
     */
    public function take(int $limit): static
    {
        return new static(array_slice($this->items, 0, $limit, true));
    }

    /**
     * Skip the first N items.
     *
     * Mirrors `array_slice($items, $count, null, true)`: a negative $count
     * keeps only the last |$count| items. Original keys are always preserved
     * — call `values()` to renumber to a 0-based list.
     *
     * @param  int $count
     * @return  static
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count, null, true));
    }

    /**
     * Take a slice of the collection starting at the given offset.
     *
     * Mirrors `array_slice($items, $offset, $length, true)`: a negative
     * $offset counts from the end of the collection and a negative $length
     * stops that many items before the end. Original keys are always
     * preserved — call `values()` to renumber to a 0-based list.
     *
     * @param  int $offset
     * @param  int|null $length
     * @return  static
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Take items while the callback passes, stopping at the first failure.
     *
     * Keys are preserved.
     *
     * @param  callable(TValue, TKey): bool $callback
     * @return  static
     */
    public function takeWhile(callable $callback): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                break;
            }
            $results[$key] = $item;
        }
        return new static($results);
    }

    /**
     * Skip items while the callback passes, keeping the rest.
     *
     * Only the initial run of passing items is skipped — after the first
     * failure, every remaining item is kept even if the callback passes
     * again. Keys are preserved.
     *
     * @param  callable(TValue, TKey): bool $callback
     * @return  static
     */
    public function skipWhile(callable $callback): static
    {
        $results = [];
        $failed = false;
        foreach ($this->items as $key => $item) {
            $failed = $failed || !$callback($item, $key);
            if ($failed) {
                $results[$key] = $item;
            }
        }
        return new static($results);
    }

    /**
     * Take items until the callback passes, stopping at the first success.
     *
     * Keys are preserved.
     *
     * @param  callable(TValue, TKey): bool $callback
     * @return  static
     */
    public function takeUntil(callable $callback): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                break;
            }
            $results[$key] = $item;
        }
        return new static($results);
    }

    /**
     * Skip items until the callback passes, keeping the rest.
     *
     * The item on which the callback first passes is the first kept item.
     * Keys are preserved.
     *
     * @param  callable(TValue, TKey): bool $callback
     * @return  static
     */
    public function skipUntil(callable $callback): static
    {
        $results = [];
        $passed = false;
        foreach ($this->items as $key => $item) {
            $passed = $passed || $callback($item, $key);
            if ($passed) {
                $results[$key] = $item;
            }
        }
        return new static($results);
    }

    /**
     * Remove duplicate items from the collection.
     *
     * When a key is given, items are deduplicated by that column's value
     * (keeping the first occurrence). When strict is true, values are
     * compared with type checking.
     *
     * Note: when no key is given, dedup is always strict (type-aware) and
     * the $strict flag is ignored — loose dedup of raw values would silently
     * merge `1` and `'1'`. The flag only applies to column-based dedup.
     *
     * @param  string|null $key
     * @param  bool $strict
     * @return  static
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
     * Scalars hash by their string form (type-prefixed in strict mode),
     * arrays by a hash of their serialization, and objects by identity.
     *
     * @param  mixed $value
     * @param  array<int|string, mixed> $seen
     * @param  bool $strict
     * @return  bool
     */
    protected function isSeen(mixed $value, array &$seen, bool $strict): bool
    {
        if (is_scalar($value) || $value === null) {
            $key = $strict
                ? get_debug_type($value) . ':' . (string) $value
                : (string) $value;
        } elseif (is_object($value)) {
            $key = 'o' . spl_object_id($value);
        } else {
            // Arrays (and any other non-scalar): hash the serialization.
            // serialize() preserves order and types, so equal arrays hash
            // equally — a close approximation of loose == for arrays.
            $key = 'a' . md5(serialize($value));
        }

        if (array_key_exists($key, $seen)) {
            return true;
        }
        $seen[$key] = true;
        return false;
    }

    /**
     * Sort the collection, optionally with a custom comparator.
     *
     * Keys are preserved. Without a callback, items are sorted by value
     * using `asort`.
     *
     * @param  (callable(TValue, TValue): int)|null $callback
     * @return  static
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
     * @param  string|(callable(TValue): mixed) $column
     * @param  int $options
     * @param  bool $descending
     * @return  static
     */
    public function sortBy(string|callable $column, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $callback = is_callable($column) ? $column : fn ($item) => $this->value($item, $column);

        // Decorate: [sortKey, originalKey, item].
        $decorated = [];
        foreach ($this->items as $key => $item) {
            $decorated[] = [$callback($item), $key, $item];
        }

        usort($decorated, function ($a, $b) use ($options, $descending) {
            $aVal = $a[0];
            $bVal = $b[0];
            $cmp = match ($options) {
                SORT_NUMERIC => $aVal <=> $bVal,
                SORT_STRING => strcmp((string) $aVal, (string) $bVal),
                default => is_numeric($aVal) && is_numeric($bVal)
                    ? $aVal <=> $bVal
                    : strcmp((string) $aVal, (string) $bVal),
            };
            if ($cmp !== 0) {
                return $descending ? -$cmp : $cmp;
            }
            // Stable tie-break on the original key so equal sort keys keep
            // their original relative order (uasort/usort are not stable).
            return $a[1] <=> $b[1];
        });

        // Undecorate, preserving original keys.
        $results = [];
        foreach ($decorated as [, $key, $item]) {
            $results[$key] = $item;
        }
        return new static($results);
    }

    /**
     * Reverse the order of the collection's items.
     *
     * Keys are preserved.
     *
     * @return  static
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
     * @return  array<TKey, mixed>
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
     * @param  int $options
     * @return  string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize the collection to a JSON-encodable array.
     *
     * @return  array<TKey, mixed>
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
     * @return  array<TKey, TValue>
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
     * @param  mixed $offset
     * @return  bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    /**
     * Get the item at the given offset, or null if it does not exist.
     *
     * @param  mixed $offset
     * @return  TValue|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Writing to a collection is not supported.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return  void
     * @throws  \BadMethodCallException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('Collection is immutable; use a transform instead.');
    }

    /**
     * Removing an item from a collection is not supported.
     *
     * @param  mixed  $offset
     * @return  void
     * @throws  \BadMethodCallException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('Collection is immutable; use a transform instead.');
    }

    /**
     * Get an iterator for the collection's items.
     *
     * @return  \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}