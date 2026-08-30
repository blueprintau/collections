<?php

declare(strict_types=1);

namespace BlueprintAU\Collections\Tests;

use BlueprintAU\Collections\Collection;
use PHPUnit\Framework\TestCase;

final class CollectionTest extends TestCase
{
    /**
     * @return Collection<int, array{id: int, name: string, role: string}>
     */
    private function users(): Collection
    {
        return new Collection([
            ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Bob',   'role' => 'user'],
            ['id' => 3, 'name' => 'Carol', 'role' => 'user'],
        ]);
    }

    public function test_construct_from_array(): void
    {
        $this->assertSame([1, 2, 3], (new Collection([1, 2, 3]))->all());
    }

    public function test_construct_from_generator(): void
    {
        $gen = (function () {
            yield 1;
            yield 2;
        })();
        $this->assertSame([1, 2], (new Collection($gen))->all());
    }

    public function test_make(): void
    {
        $this->assertSame([1, 2, 3], Collection::make([1, 2, 3])->all());
    }

    public function test_wrap_collection_returns_same_instance(): void
    {
        $c = new Collection([1, 2]);
        $this->assertSame($c, Collection::wrap($c));
    }

    public function test_wrap_array(): void
    {
        $this->assertSame([1, 2], Collection::wrap([1, 2])->all());
    }

    public function test_wrap_scalar(): void
    {
        $this->assertSame([5], Collection::wrap(5)->all());
    }

    public function test_times(): void
    {
        $this->assertSame([1, 2, 3], Collection::times(3)->all());
    }

    public function test_times_with_callback(): void
    {
        $this->assertSame(['a', 'aa', 'aaa'], Collection::times(3, fn ($i) => str_repeat('a', $i))->all());
    }

    public function test_range(): void
    {
        $this->assertSame([2, 3, 4], Collection::range(2, 4)->all());
    }

    public function test_range_with_step(): void
    {
        $this->assertSame([1, 3, 5], Collection::range(1, 5, 2)->all());
    }

    public function test_range_descending(): void
    {
        $this->assertSame([3, 2, 1], Collection::range(3, 1)->all());
    }

    public function test_pluck(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Carol'], $this->users()->pluck('name')->all());
    }

    public function test_pluck_with_key(): void
    {
        $this->assertSame([1 => 'Alice', 2 => 'Bob', 3 => 'Carol'], $this->users()->pluck('name', 'id')->all());
    }

    public function test_key_by(): void
    {
        $byId = $this->users()->keyBy('id');
        $this->assertSame([1, 2, 3], $byId->keys()->all());
        $this->assertSame('Alice', $byId[1]['name']);
    }

    public function test_group_by(): void
    {
        $byRole = $this->users()->groupBy('role');
        $this->assertSame(['admin', 'user'], $byRole->keys()->all());
        $this->assertCount(1, $byRole['admin']);
        $this->assertCount(2, $byRole['user']);
    }

    public function test_filter(): void
    {
        $admins = $this->users()->filter(fn ($u) => $u['role'] === 'admin');
        $this->assertSame(['Alice'], $admins->pluck('name')->all());
    }

    public function test_map(): void
    {
        $upper = $this->users()->map(fn ($u) => strtoupper($u['name']));
        $this->assertSame(['ALICE', 'BOB', 'CAROL'], $upper->all());
    }

    public function test_map_with_keys(): void
    {
        $result = $this->users()->mapWithKeys(fn ($u) => [$u['id'] => $u['name']]);
        $this->assertSame([1 => 'Alice', 2 => 'Bob', 3 => 'Carol'], $result->all());
    }

    public function test_flat_map(): void
    {
        $result = (new Collection([[1, 2], [3, 4]]))->flatMap(fn ($arr) => $arr);
        $this->assertSame([1, 2, 3, 4], $result->all());
    }

    public function test_collapse(): void
    {
        $this->assertSame([1, 2, 3, 4], (new Collection([[1, 2], [3, 4]]))->collapse()->all());
    }

    public function test_reduce(): void
    {
        $this->assertSame(10, (new Collection([1, 2, 3, 4]))->reduce(fn ($carry, $n) => $carry + $n, 0));
    }

    public function test_sum(): void
    {
        $this->assertSame(10, (new Collection([1, 2, 3, 4]))->sum());
    }

    public function test_sum_column(): void
    {
        $this->assertSame(6, $this->users()->sum('id'));
    }

    public function test_sum_callable(): void
    {
        $this->assertSame(6, $this->users()->sum(fn ($u) => $u['id']));
    }

    public function test_avg(): void
    {
        $this->assertSame(2.5, (new Collection([1, 2, 3, 4]))->avg());
    }

    public function test_avg_empty(): void
    {
        $this->assertSame(0, (new Collection([]))->avg());
    }

    public function test_min_max(): void
    {
        $this->assertSame(1, (new Collection([3, 1, 2]))->min());
        $this->assertSame(3, (new Collection([3, 1, 2]))->max());
    }

    public function test_min_max_callable(): void
    {
        $this->assertSame(1, $this->users()->min(fn ($u) => $u['id']));
        $this->assertSame(3, $this->users()->max(fn ($u) => $u['id']));
    }

    public function test_count(): void
    {
        $this->assertSame(3, $this->users()->count());
    }

    public function test_contains_value(): void
    {
        $this->assertTrue((new Collection([1, 2, 3]))->contains(2));
        $this->assertFalse((new Collection([1, 2, 3]))->contains(9));
    }

    public function test_contains_callback(): void
    {
        $this->assertTrue($this->users()->contains(fn ($u) => $u['role'] === 'admin'));
        $this->assertFalse($this->users()->contains(fn ($u) => $u['role'] === 'superuser'));
    }

    public function test_every(): void
    {
        $this->assertTrue((new Collection([2, 4, 6]))->every(fn ($n) => $n % 2 === 0));
        $this->assertFalse((new Collection([2, 3, 6]))->every(fn ($n) => $n % 2 === 0));
    }

    public function test_some(): void
    {
        $this->assertTrue((new Collection([1, 2, 3]))->some(fn ($n) => $n === 2));
        $this->assertFalse((new Collection([1, 3, 5]))->some(fn ($n) => $n === 2));
    }

    public function test_each(): void
    {
        $seen = [];
        $this->users()->each(function ($u) use (&$seen) {
            $seen[] = $u['name'];
        });
        $this->assertSame(['Alice', 'Bob', 'Carol'], $seen);
    }

    public function test_each_breaks_on_false(): void
    {
        $seen = [];
        $this->users()->each(function ($u) use (&$seen) {
            $seen[] = $u['name'];
            return false;
        });
        $this->assertSame(['Alice'], $seen);
    }

    public function test_first(): void
    {
        $this->assertSame('Alice', $this->users()->first()['name']);
    }

    public function test_first_with_callback(): void
    {
        $this->assertSame('Bob', $this->users()->first(fn ($u) => $u['name'] === 'Bob')['name']);
    }

    public function test_first_default(): void
    {
        $this->assertNull((new Collection([]))->first());
        $this->assertSame('fallback', (new Collection([]))->first(null, 'fallback'));
    }

    public function test_last(): void
    {
        $this->assertSame('Carol', $this->users()->last()['name']);
    }

    public function test_values_keys(): void
    {
        $this->assertSame([0, 1, 2], $this->users()->keys()->all());
        $this->assertSame(['Alice', 'Bob', 'Carol'], $this->users()->values()->pluck('name')->all());
    }

    public function test_take_skip_slice(): void
    {
        $this->assertSame(['Alice', 'Bob'], $this->users()->take(2)->pluck('name')->all());
        $this->assertSame(['Bob', 'Carol'], $this->users()->skip(1)->pluck('name')->all());
        $this->assertSame(['Bob'], $this->users()->slice(1, 1)->pluck('name')->all());
    }

    public function test_unique(): void
    {
        $this->assertSame([1, 2, 3], (new Collection([1, 1, 2, 3, 3]))->unique()->values()->all());
    }

    public function test_unique_by_key(): void
    {
        $result = $this->users()->unique('role');
        $this->assertSame(['Alice', 'Bob'], $result->pluck('name')->all());
    }

    public function test_sort(): void
    {
        $this->assertSame([1, 2, 3], (new Collection([3, 1, 2]))->sort()->values()->all());
    }

    public function test_sort_by(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Carol'], $this->users()->sortBy('name')->pluck('name')->all());
    }

    public function test_sort_by_descending(): void
    {
        $this->assertSame(['Carol', 'Bob', 'Alice'], $this->users()->sortBy('name', descending: true)->pluck('name')->all());
    }

    public function test_reverse(): void
    {
        $this->assertSame(['Carol', 'Bob', 'Alice'], $this->users()->reverse()->pluck('name')->all());
    }

    public function test_to_array_recurses(): void
    {
        $nested = new Collection([new Collection([1, 2])]);
        $this->assertSame([[1, 2]], $nested->toArray());
    }

    public function test_to_json(): void
    {
        $this->assertSame('[1,2,3]', (new Collection([1, 2, 3]))->toJson());
    }

    public function test_json_serializable(): void
    {
        $this->assertSame('[1,2,3]', json_encode(new Collection([1, 2, 3])));
    }

    public function test_array_access_read(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2]);
        $this->assertTrue(isset($c['a']));
        $this->assertSame(1, $c['a']);
        $this->assertFalse(isset($c['missing']));
        $this->assertNull($c['missing']);
    }

    public function test_array_access_null_value_is_present(): void
    {
        $c = new Collection(['a' => null]);
        $this->assertTrue(isset($c['a']));
        $this->assertNull($c['a']);
    }

    public function test_array_access_write_throws(): void
    {
        $c = new Collection(['a' => 1]);
        $this->expectException(\BadMethodCallException::class);
        $c['b'] = 2;
    }

    public function test_array_access_append_throws(): void
    {
        $c = new Collection([1, 2]);
        $this->expectException(\BadMethodCallException::class);
        $c[] = 3;
    }

    public function test_array_access_unset_throws(): void
    {
        $c = new Collection(['a' => 1]);
        $this->expectException(\BadMethodCallException::class);
        unset($c['a']);
    }

    public function test_iteration(): void
    {
        $result = [];
        foreach ($this->users() as $u) {
            $result[] = $u['name'];
        }
        $this->assertSame(['Alice', 'Bob', 'Carol'], $result);
    }

    public function test_transforms_are_immutable(): void
    {
        $original = $this->users();
        $filtered = $original->filter(fn ($u) => $u['role'] === 'admin');

        // The transform returns a new collection; the original is untouched.
        $this->assertCount(1, $filtered);
        $this->assertCount(3, $original);
        $this->assertSame('Alice', $original->first()['name']);
    }
}