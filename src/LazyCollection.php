<?php

declare(strict_types=1);

namespace BlueprintAU\Collections;

/**
 * A lazy counterpart to Collection that streams items through a generator
 * source instead of eagerly materializing them in memory.
 *
 * Transforms (map, filter, pluck, ...) are lazy — they return a new
 * LazyCollection whose generator pulls from this one on demand. Terminal
 * operations (toArray, count, toJson, all, sort, ...) materialize the stream.
 *
 * The source is stored as a factory closure returning a fresh generator, so a
 * LazyCollection is re-iterable: each call to getIterator() re-invokes the
 * factory. Prefer passing a callable source (e.g. a generator function) so the
 * stream can be replayed.
 *
 * Non-callable sources are wrapped via `yield from`, which rewinds re-iterable
 * iterators (e.g. `ArrayIterator`) automatically on each iteration. A raw
 * `Generator` is single-use: iterating it twice throws a "Cannot rewind a
 * generator that was already run" exception rather than silently returning wrong
 * data. Pass a callable source to replay a stream; pass a raw `Generator` only
 * when the source is genuinely one-shot.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @phpstan-consistent-constructor
 *
 * @implements Enumerable<TKey, TValue>
 */
class LazyCollection implements Enumerable
{
    /**
     * A factory that produces a fresh generator for each iteration.
     *
     * @var (callable(): \Generator<TKey, TValue, mixed, void>)
     */
    protected $source;

    /**
     * Create a new lazy collection from the given source.
     *
     * The constructor is protected — use `make()` (or another factory) to
     * create instances from outside the class.
     *
     * @param iterable<TKey, TValue>|(callable(): \Generator<TKey, TValue, mixed, void>) $source
     */
    protected function __construct(iterable|callable $source = [])
    {
        if (is_callable($source)) {
            $this->source = $source;
        } else {
            $this->source = static function () use ($source): \Generator {
                yield from $source;
            };
        }
    }

    /**
     * Create a new lazy collection from the given source.
     *
     * @param iterable<TKey, TValue>|(callable(): \Generator<TKey, TValue, mixed, void>) $source
     * @return static
     */
    public static function make(iterable|callable $source = []): static
    {
        return new static($source);
    }

    /**
     * Wrap the given value in a lazy collection.
     *
     * If the value is already a lazy collection it is returned unchanged; if
     * it is an array it is used directly; otherwise it is wrapped in a
     * single-item collection.
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
     * Create a lazy collection by invoking the callback N times.
     *
     * The callback receives the 1-based index.
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
     * Create a lazy collection of numbers in the given range.
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
     * key/property (no error).
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

    /**
     * Compare a column value against a target using a comparison operator.
     *
     * The match is exhaustive over `ComparisonOperator`.
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

    // ---- Transforms (lazy — return new instances) ----

    /**
     * Map each item through a callback, producing a new lazy collection.
     *
     * The callback receives the item and its key. Keys are preserved.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function map(callable $callback): static
    {
        return new static(function () use ($callback): \Generator {
            foreach ($this as $key => $item) {
                yield $key => $callback($item, $key);
            }
        });
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
        return new static(function () use ($callback): \Generator {
            foreach ($this as $key => $item) {
                if (($callback ?? fn ($v) => (bool) $v)($item, $key)) {
                    yield $key => $item;
                }
            }
        });
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
        return new static(function () use ($value, $key): \Generator {
            foreach ($this as $item) {
                $itemValue = $this->value($item, $value);
                if ($key === null) {
                    yield $itemValue;
                } else {
                    yield $this->value($item, $key) => $itemValue;
                }
            }
        });
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
        return new static(function () use ($key): \Generator {
            foreach ($this as $item) {
                yield $this->value($item, $key) => $item;
            }
        });
    }

    /**
     * Group the collection's items by a column or callback.
     *
     * The result is keyed by the group value, each holding a list of the
     * items in that group. Grouping requires the full stream, so this
     * materializes internally before returning a lazy collection.
     *
     * @param ((TValue is array ? key-of<TValue> : string)|callable(TValue, TKey): mixed) $groupBy
     * @return static<(int|string), non-empty-list<TValue>>
     */
    public function groupBy(int|string|callable $groupBy): static
    {
        $results = [];
        foreach ($this as $key => $item) {
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
        return new static(function () use ($callback): \Generator {
            foreach ($this as $key => $item) {
                $assoc = $callback($item, $key);
                foreach ($assoc as $mapKey => $mapValue) {
                    yield $mapKey => $mapValue;
                }
            }
        });
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
        return new static(function (): \Generator {
            foreach ($this as $item) {
                if (is_array($item)) {
                    foreach ($item as $value) {
                        yield $value;
                    }
                }
            }
        });
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
        foreach ($this as $key => $item) {
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
            $sum = 0;
            foreach ($this as $item) {
                $sum += $item;
            }
            return $sum;
        }
        if (is_callable($column)) {
            $sum = 0;
            foreach ($this as $item) {
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
        $min = null;
        $has = false;
        foreach ($this as $item) {
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
     * Returns null for an empty collection.
     *
     * @param string|callable(TValue): mixed|null $column
     * @return mixed
     */
    public function max(string|callable|null $column = null): mixed
    {
        $max = null;
        $has = false;
        foreach ($this as $item) {
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
     * This is a terminal operation — the stream is consumed.
     *
     * @return int
     */
    public function count(): int
    {
        return iterator_count($this->getIterator());
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
            foreach ($this as $item) {
                if ($item === $key) {
                    return true;
                }
            }
            return false;
        }

        return $this->contains(fn ($item) => $this->compare($this->value($item, $key), $operator, $value));
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
        foreach ($this as $key => $item) {
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
        foreach ($this as $key => $item) {
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
        foreach ($this as $key => $item) {
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
        foreach ($this as $key => $item) {
            if ($callback === null || $callback($item, $key)) {
                return $item;
            }
        }
        return $default;
    }

    /**
     * Get the last item, optionally the last matching a callback.
     *
     * Returns the given default (or null) when nothing matches. This consumes
     * the entire stream.
     *
     * @param (callable(TValue, TKey): bool)|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        $result = $default;
        foreach ($this as $key => $item) {
            if ($callback === null || $callback($item, $key)) {
                $result = $item;
            }
        }
        return $result;
    }

    /**
     * Reset the collection's keys to a sequential 0-based list.
     *
     * @return static
     */
    public function values(): static
    {
        return new static(function (): \Generator {
            foreach ($this as $item) {
                yield $item;
            }
        });
    }

    /**
     * Get the collection's keys as a new collection.
     *
     * @return static
     */
    public function keys(): static
    {
        return new static(function (): \Generator {
            foreach ($this as $key => $item) {
                yield $key;
            }
        });
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
        return new static(function () use ($limit): \Generator {
            $count = 0;
            foreach ($this as $key => $item) {
                if ($count >= $limit) {
                    break;
                }
                yield $key => $item;
                $count++;
            }
        });
    }

    /**
     * Skip the first N items.
     *
     * @param int $count
     * @return static
     */
    public function skip(int $count): static
    {
        return new static(function () use ($count): \Generator {
            $skipped = 0;
            foreach ($this as $key => $item) {
                if ($skipped < $count) {
                    $skipped++;
                    continue;
                }
                yield $key => $item;
            }
        });
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
        return new static(function () use ($offset, $length): \Generator {
            $index = 0;
            $taken = 0;
            foreach ($this as $key => $item) {
                if ($index < $offset) {
                    $index++;
                    continue;
                }
                if ($length !== null && $taken >= $length) {
                    break;
                }
                yield $key => $item;
                $index++;
                $taken++;
            }
        });
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
        return new static(function () use ($key, $strict): \Generator {
            $seen = [];
            foreach ($this as $k => $item) {
                $value = $key === null ? $item : $this->value($item, $key);
                $useStrict = $key === null ? true : $strict;
                if (!$this->isSeen($value, $seen, $useStrict)) {
                    yield $k => $item;
                }
            }
        });
    }

    /**
     * Sort the collection, optionally with a custom comparator.
     *
     * Keys are preserved. Without a callback, items are sorted by value
     * using `asort`. This is a terminal operation — the stream is consumed.
     *
     * @param (callable(TValue, TValue): int)|null $callback
     * @return static
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->all();
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
     * This is a terminal operation — the stream is consumed.
     *
     * @param string|callable(TValue): mixed $column
     * @param int $options
     * @param bool $descending
     * @return static
     */
    public function sortBy(string|callable $column, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->all();
        $callback = is_callable($column) ? $column : fn ($item) => $this->value($item, $column);
        uasort($items, function ($a, $b) use ($callback, $options, $descending) {
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
        return new static($items);
    }

    /**
     * Reverse the order of the collection's items.
     *
     * Keys are preserved. This is a terminal operation — the stream is
     * consumed.
     *
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->all(), true));
    }

    // ---- Conversion ----

    /**
     * Convert the collection to a plain array.
     *
     * Nested collections are recursively converted to arrays. This is a
     * terminal operation — the stream is consumed.
     *
     * @return array<TKey, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this as $key => $item) {
            $result[$key] = $item instanceof Enumerable ? $item->toArray() : $item;
        }
        return $result;
    }

    /**
     * Convert the collection to a JSON string.
     *
     * This is a terminal operation — the stream is consumed.
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
     * This is a terminal operation — the stream is consumed.
     *
     * @return array<TKey, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the underlying items as an array.
     *
     * Unlike toArray, nested collections are not recursively converted. This
     * is a terminal operation — the stream is consumed.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return iterator_to_array($this->getIterator());
    }

    // ---- IteratorAggregate ----

    /**
     * Get an iterator for the collection's items.
     *
     * Each call re-invokes the source factory, producing a fresh generator.
     *
     * @return \Generator<TKey, TValue, mixed, void>
     */
    public function getIterator(): \Traversable
    {
        return ($this->source)();
    }
}