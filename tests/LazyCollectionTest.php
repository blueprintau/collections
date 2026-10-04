<?php

declare(strict_types=1);

namespace BlueprintAU\Collections\Tests;

use BlueprintAU\Collections\ComparisonOperator;
use BlueprintAU\Collections\LazyCollection;
use PHPUnit\Framework\TestCase;

final class LazyCollectionTest extends TestCase
{
    /**
     * @return LazyCollection<int, array{id: int, name: string, role: string}>
     */
    private function users(): LazyCollection
    {
        return LazyCollection::make([
            ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Bob',   'role' => 'user'],
            ['id' => 3, 'name' => 'Carol', 'role' => 'user'],
        ]);
    }

    /**
     * An infinite generator of integers starting at 1.
     *
     * @return \Generator<int, int, mixed, void>
     */
    private function infinite(): \Generator
    {
        $i = 1;
        while (true) {
            yield $i++;
        }
    }

    public function test_construct_from_array(): void
    {
        $this->assertSame([1, 2, 3], (LazyCollection::make([1, 2, 3]))->all());
    }

    public function test_construct_from_generator(): void
    {
        $gen = (function () {
            yield 1;
            yield 2;
        })();
        $this->assertSame([1, 2], (LazyCollection::make($gen))->all());
    }

    public function test_construct_from_callable_source(): void
    {
        $lazy = LazyCollection::make(fn (): \Generator => yield from [1, 2, 3]);
        $this->assertSame([1, 2, 3], $lazy->all());
    }

    public function test_is_reiterable(): void
    {
        $lazy = LazyCollection::make(fn (): \Generator => yield from [1, 2, 3]);
        $first = $lazy->all();
        $this->assertSame([1, 2, 3], $first);
        // A re-iterable source replays the stream on the second pass.
        $this->assertSame([1, 2, 3], $lazy->all());
    }

    public function test_is_lazy_does_not_consume_source_until_iterated(): void
    {
        $state = new \stdClass();
        $state->consumed = false;
        $lazy = LazyCollection::make(function () use ($state): \Generator {
            $state->consumed = true;
            yield 1;
        });

        $this->assertFalse($state->consumed);
        $lazy->all();
        $this->assertTrue($state->consumed);
    }

    public function test_take_on_infinite_generator_terminates(): void
    {
        $lazy = LazyCollection::make($this->infinite());
        $this->assertSame([1, 2, 3, 4, 5], $lazy->take(5)->all());
    }

    public function test_make(): void
    {
        $this->assertSame([1, 2, 3], LazyCollection::make([1, 2, 3])->all());
    }

    public function test_wrap_lazy_collection_returns_same_instance(): void
    {
        $c = LazyCollection::make([1, 2]);
        $this->assertSame($c, LazyCollection::wrap($c));
    }

    public function test_wrap_array(): void
    {
        $this->assertSame([1, 2], LazyCollection::wrap([1, 2])->all());
    }

    public function test_wrap_scalar(): void
    {
        $this->assertSame([5], LazyCollection::wrap(5)->all());
    }

    public function test_times(): void
    {
        $this->assertSame([1, 2, 3], LazyCollection::times(3)->all());
    }

    public function test_times_with_callback(): void
    {
        $this->assertSame([2, 4, 6], LazyCollection::times(3, fn ($i) => $i * 2)->all());
    }

    public function test_times_zero_returns_empty(): void
    {
        $this->assertSame([], LazyCollection::times(0)->all());
    }

    public function test_range(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], LazyCollection::range(1, 5)->all());
    }

    public function test_range_descending(): void
    {
        $this->assertSame([5, 4, 3, 2, 1], LazyCollection::range(5, 1)->all());
    }

    public function test_range_with_step(): void
    {
        $this->assertSame([1, 3, 5], LazyCollection::range(1, 5, 2)->all());
    }

    public function test_range_non_integer_bounds_delegates_to_range(): void
    {
        $this->assertSame(range(1.0, 2.0, 0.5), LazyCollection::range(1.0, 2.0, 0.5)->all());
        $this->assertSame(range('a', 'e'), LazyCollection::range('a', 'e')->all());
    }

    public function test_map(): void
    {
        $this->assertSame([2, 4, 6], (LazyCollection::make([1, 2, 3]))->map(fn ($n) => $n * 2)->all());
    }

    public function test_map_preserves_keys(): void
    {
        $this->assertSame(['a' => 2, 'b' => 4], (LazyCollection::make(['a' => 1, 'b' => 2]))->map(fn ($n) => $n * 2)->all());
    }

    public function test_filter(): void
    {
        $this->assertSame([1, 3], (LazyCollection::make([1, 2, 3, 4]))->filter(fn ($n) => $n % 2 === 1)->values()->all());
    }

    public function test_filter_without_callback_keeps_truthy(): void
    {
        $this->assertSame([1, 2], (LazyCollection::make([0, 1, '', 2]))->filter()->values()->all());
    }

    public function test_pluck(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Carol'], $this->users()->pluck('name')->all());
    }

    public function test_pluck_with_key(): void
    {
        $this->assertSame(
            ['Alice' => 'admin', 'Bob' => 'user', 'Carol' => 'user'],
            $this->users()->pluck('role', 'name')->all()
        );
    }

    public function test_key_by(): void
    {
        $this->assertSame(
            ['Alice' => ['id' => 1, 'name' => 'Alice', 'role' => 'admin']],
            $this->users()->keyBy('name')->take(1)->all()
        );
    }

    public function test_group_by(): void
    {
        $this->assertSame(
            ['admin' => [['id' => 1, 'name' => 'Alice', 'role' => 'admin']]],
            $this->users()->groupBy('role')->take(1)->all()
        );
    }

    public function test_map_with_keys(): void
    {
        $this->assertSame(
            ['a' => 1, 'b' => 2],
            (LazyCollection::make([1, 2]))->mapWithKeys(fn ($n) => [$n === 1 ? 'a' : 'b' => $n])->all()
        );
    }

    public function test_flat_map(): void
    {
        $this->assertSame(
            [1, 2, 3, 4],
            (LazyCollection::make([[1, 2], [3, 4]]))->flatMap(fn ($arr) => $arr)->values()->all()
        );
    }

    public function test_collapse(): void
    {
        $this->assertSame(
            [1, 2, 3, 4],
            (LazyCollection::make([[1, 2], [3, 4]]))->collapse()->values()->all()
        );
    }

    public function test_reduce(): void
    {
        $this->assertSame(6, (LazyCollection::make([1, 2, 3]))->reduce(fn ($carry, $n) => $carry + $n, 0));
    }

    public function test_sum(): void
    {
        $this->assertSame(6, (LazyCollection::make([1, 2, 3]))->sum());
    }

    public function test_sum_with_column(): void
    {
        $this->assertSame(6, $this->users()->sum('id'));
    }

    public function test_avg(): void
    {
        $this->assertSame(2, (LazyCollection::make([1, 2, 3]))->avg());
    }

    public function test_avg_empty_returns_zero(): void
    {
        $this->assertSame(0, (LazyCollection::make([]))->avg());
    }

    public function test_min(): void
    {
        $this->assertSame(1, (LazyCollection::make([3, 1, 2]))->min());
    }

    public function test_min_empty_returns_null(): void
    {
        /** @var LazyCollection<int, int> $empty */
        $empty = LazyCollection::make([]);
        $this->assertNull($empty->min());
    }

    public function test_max(): void
    {
        $this->assertSame(3, (LazyCollection::make([3, 1, 2]))->max());
    }

    public function test_max_empty_returns_null(): void
    {
        /** @var LazyCollection<int, int> $empty */
        $empty = LazyCollection::make([]);
        $this->assertNull($empty->max());
    }

    public function test_count(): void
    {
        $this->assertSame(3, (LazyCollection::make([1, 2, 3]))->count());
    }

    public function test_contains_value(): void
    {
        $this->assertTrue((LazyCollection::make([1, 2, 3]))->contains(2));
        $this->assertFalse((LazyCollection::make([1, 2, 3]))->contains(5));
    }

    public function test_contains_callable(): void
    {
        // contains() is value-only: a Closure is checked as a value, never
        // invoked as a predicate. Predicates go through some().
        $closure = fn ($n) => $n === 2;
        $this->assertFalse((LazyCollection::make([1, 2, 3]))->contains($closure));
        $this->assertTrue((LazyCollection::make([1, 2, 3]))->some($closure));
    }

    public function test_contains_column(): void
    {
        $this->assertTrue($this->users()->contains('name', 'Alice'));
    }

    public function test_where(): void
    {
        $this->assertSame(
            [['id' => 2, 'name' => 'Bob', 'role' => 'user']],
            $this->users()->where('name', 'Bob')->values()->all()
        );
    }

    public function test_where_with_operator(): void
    {
        $this->assertSame(
            [['id' => 2, 'name' => 'Bob', 'role' => 'user'], ['id' => 3, 'name' => 'Carol', 'role' => 'user']],
            $this->users()->where('id', 1, ComparisonOperator::GreaterThan)->values()->all()
        );
    }

    public function test_every(): void
    {
        $this->assertTrue((LazyCollection::make([2, 4, 6]))->every(fn ($n) => $n % 2 === 0));
        $this->assertFalse((LazyCollection::make([2, 3, 6]))->every(fn ($n) => $n % 2 === 0));
    }

    public function test_every_empty_returns_true(): void
    {
        $this->assertTrue((LazyCollection::make([]))->every(fn ($n) => false));
    }

    public function test_some(): void
    {
        $this->assertTrue((LazyCollection::make([1, 2, 3]))->some(fn ($n) => $n === 2));
        $this->assertFalse((LazyCollection::make([1, 2, 3]))->some(fn ($n) => $n === 5));
    }

    public function test_each(): void
    {
        $seen = [];
        $result = (LazyCollection::make([1, 2, 3]))->each(function ($n) use (&$seen) {
            $seen[] = $n;
        });
        $this->assertSame([1, 2, 3], $seen);
        $this->assertInstanceOf(LazyCollection::class, $result);
    }

    public function test_each_breaks_on_false(): void
    {
        $seen = [];
        (LazyCollection::make([1, 2, 3]))->each(function ($n) use (&$seen) {
            $seen[] = $n;
            return $n === 2 ? false : null;
        });
        $this->assertSame([1, 2], $seen);
    }

    public function test_first(): void
    {
        $this->assertSame(1, (LazyCollection::make([1, 2, 3]))->first());
    }

    public function test_first_with_callback(): void
    {
        $this->assertSame(2, (LazyCollection::make([1, 2, 3]))->first(fn ($n) => $n % 2 === 0));
    }

    public function test_first_with_default(): void
    {
        $this->assertSame(9, (LazyCollection::make([]))->first(null, 9));
    }

    public function test_last(): void
    {
        $this->assertSame(3, (LazyCollection::make([1, 2, 3]))->last());
    }

    public function test_last_with_callback(): void
    {
        $this->assertSame(3, (LazyCollection::make([1, 2, 3]))->last(fn ($n) => $n % 2 === 1));
    }

    public function test_values(): void
    {
        $this->assertSame([1, 2], (LazyCollection::make(['a' => 1, 'b' => 2]))->values()->all());
    }

    public function test_keys(): void
    {
        $this->assertSame(['a', 'b'], (LazyCollection::make(['a' => 1, 'b' => 2]))->keys()->all());
    }

    public function test_take(): void
    {
        $this->assertSame([1, 2], (LazyCollection::make([1, 2, 3]))->take(2)->all());
    }

    public function test_skip(): void
    {
        $this->assertSame([3], (LazyCollection::make([1, 2, 3]))->skip(2)->values()->all());
    }

    public function test_slice(): void
    {
        $this->assertSame([2, 3], (LazyCollection::make([1, 2, 3, 4]))->slice(1, 2)->values()->all());
    }

    public function test_slice_without_length(): void
    {
        $this->assertSame([3, 4], (LazyCollection::make([1, 2, 3, 4]))->slice(2)->values()->all());
    }

    public function test_unique(): void
    {
        $this->assertSame([1, 2, 3], (LazyCollection::make([1, 2, 2, 3, 1]))->unique()->values()->all());
    }

    public function test_unique_with_key(): void
    {
        $this->assertSame(
            [['id' => 1, 'name' => 'Alice', 'role' => 'admin'], ['id' => 2, 'name' => 'Bob', 'role' => 'user']],
            $this->users()->unique('role')->all()
        );
    }

    public function test_sort(): void
    {
        $this->assertSame([1, 2, 3], (LazyCollection::make([3, 1, 2]))->sort()->values()->all());
    }

    public function test_sort_by(): void
    {
        $this->assertSame(
            ['Alice', 'Bob', 'Carol'],
            $this->users()->sortBy('name')->pluck('name')->all()
        );
    }

    public function test_sort_by_descending(): void
    {
        $this->assertSame(
            ['Carol', 'Bob', 'Alice'],
            $this->users()->sortBy('name', descending: true)->pluck('name')->all()
        );
    }

    public function test_reverse(): void
    {
        $this->assertSame([3, 2, 1], (LazyCollection::make([1, 2, 3]))->reverse()->values()->all());
    }

    public function test_to_array_recurses_nested_collections(): void
    {
        $lazy = LazyCollection::make([
            LazyCollection::make([1, 2]),
            LazyCollection::make([3, 4]),
        ]);
        $this->assertSame([[1, 2], [3, 4]], $lazy->toArray());
    }

    public function test_to_json(): void
    {
        $this->assertSame('[1,2,3]', (LazyCollection::make([1, 2, 3]))->toJson());
    }

    public function test_json_serialize(): void
    {
        $this->assertSame([1, 2, 3], (LazyCollection::make([1, 2, 3]))->jsonSerialize());
    }

    public function test_all_does_not_recurse_nested_collections(): void
    {
        $inner = LazyCollection::make([1, 2]);
        $lazy = LazyCollection::make([$inner]);
        $this->assertSame([$inner], $lazy->all());
    }

    public function test_is_foreachable(): void
    {
        $result = [];
        foreach (LazyCollection::make([1, 2, 3]) as $item) {
            $result[] = $item;
        }
        $this->assertSame([1, 2, 3], $result);
    }

    public function test_range_zero_step_returns_empty(): void
    {
        $this->assertSame([], LazyCollection::range(1, 10, 0)->all());
    }

    public function test_pluck_on_objects(): void
    {
        $items = LazyCollection::make([
            (object) ['id' => 1, 'name' => 'Alice'],
            (object) ['id' => 2, 'name' => 'Bob'],
        ]);
        $this->assertSame(['Alice', 'Bob'], $items->pluck('name')->all());
    }

    public function test_pluck_on_scalars_returns_nulls(): void
    {
        $this->assertSame([null, null], LazyCollection::make([1, 2])->pluck('x')->all());
    }

    public function test_where_with_comparison_operators(): void
    {
        $this->assertSame(
            ['Bob', 'Carol'],
            $this->users()->where('id', 1, ComparisonOperator::GreaterThan)->pluck('name')->values()->all()
        );
        $this->assertSame(
            ['Bob', 'Carol'],
            $this->users()->where('id', 2, ComparisonOperator::GreaterThanOrEqual)->pluck('name')->values()->all()
        );
        $this->assertSame(
            ['Alice'],
            $this->users()->where('id', 2, ComparisonOperator::LessThan)->pluck('name')->values()->all()
        );
        $this->assertSame(
            ['Alice', 'Bob'],
            $this->users()->where('id', 2, ComparisonOperator::LessThanOrEqual)->pluck('name')->values()->all()
        );
        $this->assertSame(
            ['Carol'],
            $this->users()->where('id', [1, 2], ComparisonOperator::NotIn)->pluck('name')->values()->all()
        );
    }

    public function test_sum_with_callable(): void
    {
        $items = LazyCollection::make([['n' => 1], ['n' => 2], ['n' => 3]]);
        $this->assertSame(6, $items->sum(fn ($i) => $i['n']));
    }

    public function test_min_max_with_callable(): void
    {
        $items = LazyCollection::make([['n' => 5], ['n' => 2], ['n' => 8]]);
        $this->assertSame(2, $items->min(fn ($i) => $i['n']));
        $this->assertSame(8, $items->max(fn ($i) => $i['n']));
    }

    public function test_sort_by_with_sort_options(): void
    {
        $items = LazyCollection::make([['id' => 10], ['id' => 2], ['id' => 1]]);
        $this->assertSame([1, 2, 10], $items->sortBy('id', SORT_NUMERIC)->pluck('id')->values()->all());
        // SORT_REGULAR compares numerically when both values are numeric.
        $this->assertSame([1, 2, 10], $items->sortBy('id')->pluck('id')->values()->all());
        $this->assertSame(
            ['Alice', 'Bob', 'Carol'],
            $this->users()->sortBy('name', SORT_STRING)->pluck('name')->values()->all()
        );
        // SORT_REGULAR falls back to lexical comparison for non-numeric values.
        $this->assertSame(
            ['Alice', 'Bob', 'Carol'],
            $this->users()->sortBy('name')->pluck('name')->values()->all()
        );
    }

    public function test_unique_with_array_items_strict(): void
    {
        $items = LazyCollection::make([[1, 2], [1, 2], [3]]);
        $this->assertCount(2, $items->unique()->values()->all());
    }

    public function test_unique_by_key_loose_with_array_values(): void
    {
        $items = LazyCollection::make([
            ['tags' => [1, 2]],
            ['tags' => [1, 2]],
            ['tags' => [3]],
        ]);
        $this->assertCount(2, $items->unique('tags')->values()->all());
    }
}