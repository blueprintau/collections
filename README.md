[![PHP Tests](https://github.com/blueprintau/collections/actions/workflows/tests.yml/badge.svg)](https://github.com/blueprintau/collections/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/blueprintau/collections.svg)](https://packagist.org/packages/blueprintau/collections)

# BlueprintAU Collections

A pure, standalone array wrapper for the BlueprintAU ecosystem. This is the
generic collection package — no model imports, no framework coupling. It is
the one package every other `BlueprintAU` package can depend on without
circularity.

## Dependencies

**None.** This is a pure, standalone array wrapper.

## Installation

```bash
composer require blueprintau/collections
```

## Usage

```php
use BlueprintAU\Collections\Collection;

// Constructors are protected — create instances via the static factories.
$users = Collection::make([
    ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
    ['id' => 2, 'name' => 'Bob',   'role' => 'user'],
    ['id' => 3, 'name' => 'Carol', 'role' => 'user'],
]);

// Transforms (immutable — each returns a new Collection)
$names  = $users->pluck('name');                   // ['Alice', 'Bob', 'Carol']
$byId   = $users->keyBy('id');                     // [1 => [...], 2 => [...], 3 => [...]]
$byRole = $users->groupBy('role');                 // ['admin' => [...], 'user' => [...]]
$admins = $users->filter(fn ($u) => $u['role'] === 'admin');  // [Alice]
$upper  = $users->map(fn ($u) => strtoupper($u['name']));     // ['ALICE', 'BOB', 'CAROL']

// Reductions
$count    = $users->count();                       // 3
$total    = Collection::make([1, 2, 3,  ̀4])->sum(); // 10
$avg      = Collection::make([1, 2, 3, 4])->avg(); // 2.5
$hasAdmin = $users->contains(fn ($u) => $u['role'] === 'admin');  // true

// Selection
$firstTwo = $users->take(2);                       // [Alice, Bob]
$sorted   = Collection::make([
    ['name' => 'Carol'],
    ['name' => 'Alice'],
    ['name' => 'Bob'],
])->sortBy('name');                               // [Alice, Bob, Carol]
$unique   = Collection::make([1, 1, 2,  ̀3,  ̀3])->unique();  // [1, 2, 3]

// Iteration
$users->each(fn ($u) => print($u['name'] . "\n")); // Alice Bob Carol
$first = $users->first();                          // Alice's row

// Conversion
$array = $users->toArray();                        // plain array
$json  = $users->toJson();                         // JSON string
```

## The `Enumerable` contract

`Enumerable` is the contract both `Collection` and `LazyCollection`
implement. It extends `\IteratorAggregate` and `\JsonSerializable`, so any
collection is `foreach`-able and `json_encode()`-able.

Only methods that behave identically whether the data is eagerly in memory or
lazily streamed belong on the contract. Anything that forces materialization
(`count`, `sort`, `unique`, `toArray`) stays on `Collection`.

## The `LazyCollection`

`LazyCollection` is the lazy counterpart to `Collection`. Instead of holding
items in memory, it streams them through a generator source on demand.

- **Transforms are lazy** — `map`, `filter`, `pluck`, `take`, `skip`, `slice`,
  and friends return a new `LazyCollection` that pulls from the previous one
  only when iterated. This makes it possible to work with infinite or very
  large sources without materializing them.
- **Terminal operations materialize** — `toArray`, `count`, `toJson`, `all`,
  `sort`, `sortBy`, `unique`, and `reverse` consume the stream.
- **Re-iterable** — the source is stored as a factory closure, so each
  iteration produces a fresh generator. Pass a callable source (e.g. a
  generator function) to replay the stream. Non-callable sources are wrapped
  via `yield from`, which rewinds re-iterable iterators (e.g. `ArrayIterator`)
  automatically. A raw `Generator` is single-use: iterating it twice throws
  a "Cannot rewind a generator that was already run" exception rather than
  silently returning wrong data.

```php
use BlueprintAU\Collections\LazyCollection;

// An infinite sequence, safely truncated.
$lazy = LazyCollection::make(function (): \Generator {
    $i = 1;
    while (true) {
        yield $i++;
    }
});

$firstFive = $lazy->take(5)->all(); // [1, 2, 3, 4, 5]
```

## Requirements

PHP **8.3 or newer**.

## Testing

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan (level 8)
composer security:audit  # dependency security advisories
```

CI runs the test suite across PHP 8.3 / 8.4 / 8.5 (lowest and highest
dependencies) on every push and pull request. Releases are cut from the
**Release** workflow (Actions → Release), which takes a version tag, verifies
it does not already exist, runs the full test suite, then creates the tag and
GitHub Release.

## License

MIT