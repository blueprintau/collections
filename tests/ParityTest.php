<?php

declare(strict_types=1);

namespace BlueprintAU\Collections\Tests;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Collections\LazyCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parameterized parity suite: every Enumerable operation must produce the
 * same result on Collection and LazyCollection for identical input.
 *
 * This is the suite the audit identified as the single highest-leverage
 * addition — every eager/lazy divergence found (negative take/skip/slice,
 * sum semantics, avg on one-shot sources) is caught here.
 */
final class ParityTest extends TestCase
{
    /**
     * @return iterable<string, array{array<int|string, mixed>}>
     */
    public static function sampleInputs(): iterable
    {
        yield 'empty' => [[]];
        yield 'single' => [[5]];
        yield 'list of ints' => [[1, 2, 3, 4, 5]];
        yield 'assoc' => [['a' => 1, 'b' => 2, 'c' => 3]];
        yield 'with duplicates' => [[1, 2, 1, 3, 2, 1]];
        yield 'mixed scalar types' => [[1, '1', 2, '2', true, false, null]];
        yield 'rows' => [
            [
                ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
                ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
                ['id' => 3, 'name' => 'Carol', 'role' => 'user'],
            ],
        ];
    }

    /**
     * Build an eager and a lazy collection over the same items. The lazy
     * source is a factory closure so it is re-iterable like the eager one.
     *
     * @param array<int|string, mixed> $items
     * @return array{Collection<int|string, mixed>, LazyCollection<int|string, mixed>}
     */
    private function pair(array $items): array
    {
        return [
            Collection::make($items),
            LazyCollection::make(static fn (): \Generator => yield from $items),
        ];
    }

    // ---- Selection: take / skip / slice (AUD-COR-001) ----

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('negativeSelectionCases')]
    public function test_take_parity(int $limit, array $items): void
    {
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->take($limit)->all(), $lazy->take($limit)->all());
    }

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('negativeSelectionCases')]
    public function test_skip_parity(int $count, array $items): void
    {
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->skip($count)->all(), $lazy->skip($count)->all());
    }

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('negativeSelectionCases')]
    public function test_slice_parity(int $offset, array $items): void
    {
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->slice($offset)->all(), $lazy->slice($offset)->all());
        $this->assertSame($eager->slice($offset, 2)->all(), $lazy->slice($offset, 2)->all());
        $this->assertSame($eager->slice($offset, -1)->all(), $lazy->slice($offset, -1)->all());
    }

    public function test_selection_preserves_original_keys(): void
    {
        // take/skip/slice always preserve keys — including non-sequential
        // integer keys (7, 10, 15 stay 7, 10, 15, not 0, 1, 2). Renumbering
        // is explicit via values().
        $items = [7 => 'a', 10 => 'b', 15 => 'c', 20 => 'd'];

        foreach ([Collection::make($items), LazyCollection::make(static fn (): \Generator => yield from $items)] as $c) {
            $this->assertSame([7 => 'a', 10 => 'b'], $c->take(2)->all());
            $this->assertSame([15 => 'c', 20 => 'd'], $c->skip(2)->all());
            $this->assertSame([10 => 'b', 15 => 'c'], $c->slice(1, 2)->all());
            $this->assertSame([0 => 'a', 1 => 'b'], $c->take(2)->values()->all());
        }
    }

    public function test_selection_negative_preserves_keys(): void
    {
        $items = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];

        foreach ([Collection::make($items), LazyCollection::make(static fn (): \Generator => yield from $items)] as $c) {
            $this->assertSame(['a' => 1, 'b' => 2], $c->take(-2)->all());
            $this->assertSame(['c' => 3, 'd' => 4], $c->skip(-2)->all());
            $this->assertSame(['b' => 2, 'c' => 3], $c->slice(-3, 2)->all());
        }
    }
    /**
     * @return iterable<string, array{int, array<int|string, mixed>}>
     */
    public static function negativeSelectionCases(): iterable
    {
        yield 'positive on list' => [2, [1, 2, 3, 4, 5]];
        yield 'negative take' => [-2, [1, 2, 3, 4, 5]];
        yield 'negative skip' => [-2, [1, 2, 3, 4, 5]];
        yield 'negative slice offset' => [-2, [1, 2, 3, 4, 5]];
        yield 'negative on assoc' => [-1, ['a' => 1, 'b' => 2, 'c' => 3]];
        yield 'zero' => [0, [1, 2, 3]];
        yield 'beyond length' => [10, [1, 2, 3]];
        yield 'negative beyond length' => [-10, [1, 2, 3]];
        yield 'on empty' => [-1, []];
    }

    // ---- Reductions: sum / avg / min / max (AUD-COR-002, AUD-COR-003) ----

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_min_max_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->min(), $lazy->min());
        $this->assertSame($eager->max(), $lazy->max());
    }
    public function test_avg_single_pass_on_one_shot_source(): void
    {
        // AUD-COR-002: avg() must not consume the stream twice.
        $invocations = 0;
        $lazy = LazyCollection::make(function () use (&$invocations): \Generator {
            $invocations++;
            yield from [1, 2, 3, 4];
        });

        $this->assertSame(2.5, $lazy->avg());
        $this->assertSame(1, $invocations, 'avg() must traverse the source exactly once');
    }

    public function test_avg_with_column_single_pass(): void
    {
        $invocations = 0;
        $lazy = LazyCollection::make(function () use (&$invocations): \Generator {
            $invocations++;
            yield from [['n' => 2], ['n' => 4], ['n' => 6]];
        });

        $this->assertSame(4, $lazy->avg('n'));
        $this->assertSame(1, $invocations);
    }

    public function test_sum_non_numeric_throws_on_both_classes(): void
    {
        // AUD-COR-003: both classes must agree — TypeError on non-numeric.
        $this->expectException(\TypeError::class);
        Collection::make([1, 'abc', 3])->sum();
    }

    public function test_sum_non_numeric_throws_on_lazy(): void
    {
        $this->expectException(\TypeError::class);
        LazyCollection::make(static fn (): \Generator => yield from [1, 'abc', 3])->sum();
    }

    public function test_sum_parity_numeric(): void
    {
        [$eager, $lazy] = $this->pair([1, 2, 3, 4.5]);
        $this->assertSame($eager->sum(), $lazy->sum());
        $this->assertSame(10.5, $lazy->sum());
    }

    // ---- Lazy factories: times / range (AUD-RES-002) ----

    public function test_lazy_times_is_truly_lazy(): void
    {
        // AUD-RES-002: times() must not materialize an N-element array.
        $lazy = LazyCollection::times(1_000_000);
        $this->assertSame([1, 2, 3], $lazy->take(3)->all());
    }

    public function test_lazy_times_matches_eager(): void
    {
        $this->assertSame(
            Collection::times(5, fn ($i) => $i * 2)->all(),
            LazyCollection::times(5, fn ($i) => $i * 2)->all()
        );
    }

    public function test_lazy_range_matches_eager(): void
    {
        foreach ([[1, 5, 1], [5, 1, 1], [0, 10, 3], [10, 0, 2]] as [$from, $to, $step]) {
            $this->assertSame(
                Collection::range($from, $to, $step)->all(),
                LazyCollection::range($from, $to, $step)->all(),
                "range({$from}, {$to}, {$step})"
            );
        }
    }

    public function test_lazy_range_is_truly_lazy(): void
    {
        $lazy = LazyCollection::range(1, 1_000_000);
        $this->assertSame([1, 2, 3], $lazy->take(3)->all());
    }

    // ---- unique / isSeen (AUD-PERF-001) ----

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_unique_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->unique()->all(), $lazy->unique()->all());
    }

    public function test_unique_dedups_equal_arrays(): void
    {
        $items = [['a' => 1], ['a' => 1], ['a' => 2]];
        [$eager, $lazy] = $this->pair($items);
        $this->assertCount(2, $eager->unique()->all());
        $this->assertSame($eager->unique()->all(), $lazy->unique()->all());
    }

    public function test_unique_treats_distinct_object_instances_as_distinct(): void
    {
        $items = [(object) ['v' => 1], (object) ['v' => 1]];
        [$eager, $lazy] = $this->pair($items);
        $this->assertCount(2, $eager->unique()->all());
        $this->assertCount(2, $lazy->unique()->all());
    }

    public function test_unique_by_column_parity(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 1, 'name' => 'Alice 2'],
        ];
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->unique('id')->all(), $lazy->unique('id')->all());
    }

    // ---- sortBy (AUD-PERF-002, AUD-COR-008) ----

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_sort_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->sort()->all(), $lazy->sort()->all());
    }

    public function test_sortby_callback_runs_once_per_item(): void
    {
        // AUD-PERF-002 / AUD-COR-008: extraction must be O(n), not O(n log n).
        foreach ([Collection::class, LazyCollection::class] as $class) {
            $invocations = 0;
            $items = [5, 3, 1, 4, 2, 6, 0, 7];
            $collection = $class === Collection::class
                ? Collection::make($items)
                : LazyCollection::make(static fn (): \Generator => yield from $items);

            $collection->sortBy(function ($item) use (&$invocations) {
                $invocations++;
                return $item;
            });

            $this->assertSame(count($items), $invocations, "{$class}::sortBy callback count");
        }
    }

    public function test_sortby_parity_with_column(): void
    {
        $items = [
            ['id' => 3, 'name' => 'Carol'],
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame($eager->sortBy('id')->all(), $lazy->sortBy('id')->all());
        $this->assertSame($eager->sortBy('id', SORT_REGULAR, true)->all(), $lazy->sortBy('id', SORT_REGULAR, true)->all());
    }

    public function test_sortby_is_stable_for_equal_keys(): void
    {
        $items = [
            ['k' => 1, 'order' => 'first'],
            ['k' => 1, 'order' => 'second'],
            ['k' => 0, 'order' => 'zero'],
        ];
        [$eager, $lazy] = $this->pair($items);
        $this->assertSame(
            ['zero', 'first', 'second'],
            array_column($eager->sortBy('k')->all(), 'order')
        );
        $this->assertSame(
            array_column($eager->sortBy('k')->all(), 'order'),
            array_column($lazy->sortBy('k')->all(), 'order')
        );
    }

    // ---- Transforms parity ----

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_transform_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);

        $double = fn ($v) => is_numeric($v) ? $v * 2 : $v;
        $this->assertSame($eager->map($double)->all(), $lazy->map($double)->all());
        $this->assertSame($eager->filter()->all(), $lazy->filter()->all());
        $this->assertSame($eager->values()->all(), $lazy->values()->all());
        $this->assertSame($eager->keys()->all(), $lazy->keys()->all());
        $this->assertSame($eager->reverse()->all(), $lazy->reverse()->all());
        $this->assertSame($eager->toArray(), $lazy->toArray());
    }

    // ---- Predicate & streaming transforms ----

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_predicate_transform_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);

        $isThree = fn ($v, $k) => $v === 3;
        $nullIfThree = fn ($v, $k) => $v === 3 ? null : $v;
        $this->assertSame($eager->filterMap($nullIfThree)->all(), $lazy->filterMap($nullIfThree)->all());
        $this->assertSame($eager->reject()->all(), $lazy->reject()->all());
        $this->assertSame($eager->reject($isThree)->all(), $lazy->reject($isThree)->all());
        $this->assertSame($eager->takeWhile(fn ($v) => is_numeric($v))->all(), $lazy->takeWhile(fn ($v) => is_numeric($v))->all());
        $this->assertSame($eager->skipWhile(fn ($v) => is_numeric($v))->all(), $lazy->skipWhile(fn ($v) => is_numeric($v))->all());
        $this->assertSame($eager->takeUntil(fn ($v) => $v === 3)->all(), $lazy->takeUntil(fn ($v) => $v === 3)->all());
        $this->assertSame($eager->skipUntil(fn ($v) => $v === 3)->all(), $lazy->skipUntil(fn ($v) => $v === 3)->all());
        $this->assertSame($eager->whereNull()->all(), $lazy->whereNull()->all());
        $this->assertSame($eager->whereNotNull()->all(), $lazy->whereNotNull()->all());
        $this->assertSame($eager->whereNull('missing')->all(), $lazy->whereNull('missing')->all());
        $this->assertSame($eager->whereNotNull('missing')->all(), $lazy->whereNotNull('missing')->all());
    }

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_predicate_reduction_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);

        $this->assertSame($eager->isEmpty(), $lazy->isEmpty());
        $this->assertSame($eager->isNotEmpty(), $lazy->isNotEmpty());
        $this->assertSame($eager->firstWhere(0, 3), $lazy->firstWhere(0, 3));
        // Only string-castable items implode safely — the rows fixture holds
        // arrays, so it would trigger an Array-to-string conversion.
        if (array_filter($items, 'is_array') === []) {
            $this->assertSame($eager->implode('|'), $lazy->implode('|'));
        }
    }

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_sole_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);

        try {
            $eagerResult = $eager->sole(3);
        } catch (\LogicException $e) {
            $eagerResult = get_class($e);
        }
        try {
            $lazyResult = $lazy->sole(3);
        } catch (\LogicException $e) {
            $lazyResult = get_class($e);
        }
        $this->assertSame($eagerResult, $lazyResult);
    }

    public function test_sole_column_form_parity(): void
    {
        $items = [
            ['id' => 1, 'role' => 'admin'],
            ['id' => 2, 'role' => 'user'],
        ];
        [$eager, $lazy] = $this->pair($items);

        $this->assertSame($eager->sole('role', 'admin'), $lazy->sole('role', 'admin'));
    }

    /**
     * @param array<int|string, mixed> $items
     */
    #[DataProvider('sampleInputs')]
    public function test_reduction_parity(array $items): void
    {
        [$eager, $lazy] = $this->pair($items);

        $this->assertSame($eager->reduce(fn ($c, $v) => is_numeric($v) ? $c + $v : $c, 0), $lazy->reduce(fn ($c, $v) => is_numeric($v) ? $c + $v : $c, 0));
        $this->assertSame($eager->first(), $lazy->first());
        $this->assertSame($eager->last(), $lazy->last());
        $this->assertSame($eager->contains(3), $lazy->contains(3));
        $this->assertSame($eager->every(fn ($v) => $v !== 'nope'), $lazy->every(fn ($v) => $v !== 'nope'));
        $this->assertSame($eager->some(fn ($v) => $v === 3), $lazy->some(fn ($v) => $v === 3));
    }

    public function test_row_operation_parity(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice', 'role' => 'admin', 'nickname' => null],
            ['id' => 2, 'name' => 'Bob', 'role' => 'user', 'nickname' => 'Bobby'],
            ['id' => 3, 'name' => 'Carol', 'role' => 'user', 'nickname' => null],
        ];
        [$eager, $lazy] = $this->pair($items);

        $this->assertSame($eager->pluck('name')->all(), $lazy->pluck('name')->all());
        $this->assertSame($eager->pluck('name', 'id')->all(), $lazy->pluck('name', 'id')->all());
        $this->assertSame($eager->keyBy('id')->all(), $lazy->keyBy('id')->all());
        $this->assertSame($eager->where('role', 'user')->all(), $lazy->where('role', 'user')->all());
        $this->assertSame(
            $eager->groupBy('role')->map(fn ($g) => count($g))->all(),
            $lazy->groupBy('role')->map(fn ($g) => count($g))->all()
        );
        $this->assertSame($eager->sum('id'), $lazy->sum('id'));
        $this->assertSame($eager->avg('id'), $lazy->avg('id'));
        $this->assertSame($eager->min('id'), $lazy->min('id'));
        $this->assertSame($eager->max('id'), $lazy->max('id'));
        $this->assertSame($eager->firstWhere('role', 'user'), $lazy->firstWhere('role', 'user'));
        $this->assertSame($eager->whereNull('nickname')->all(), $lazy->whereNull('nickname')->all());
        $this->assertSame($eager->whereNotNull('nickname')->all(), $lazy->whereNotNull('nickname')->all());
        $this->assertSame($eager->implode(', ', 'name'), $lazy->implode(', ', 'name'));
        $this->assertSame($eager->sole('id', 1), $lazy->sole('id', 1));
    }
}
