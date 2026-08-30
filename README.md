<!-- [![PHP Tests](https://github.com/blueprintau/collections/actions/workflows/tests.yml/badge.svg?branch=development)](https://github.com/blueprintau/collections/actions/workflows/tests.yml) -->
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

$users = new Collection([
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
$total    = (new Collection([1, 2, 3, 4]))->sum(); // 10
$avg      = (new Collection([1, 2, 3, 4]))->avg(); // 2.5
$hasAdmin = $users->contains(fn ($u) => $u['role'] === 'admin');  // true

// Selection
$firstTwo = $users->take(2);                       // [Alice, Bob]
$sorted   = (new Collection([
    ['name' => 'Carol'],
    ['name' => 'Alice'],
    ['name' => 'Bob'],
]))->sortBy('name');                               // [Alice, Bob, Carol]
$unique   = (new Collection([1, 1, 2, 3, 3]))->unique();  // [1, 2, 3]

// Iteration
$users->each(fn ($u) => print($u['name'] . "\n")); // Alice Bob Carol
$first = $users->first();                          // Alice's row

// Conversion
$array = $users->toArray();                        // plain array
$json  = $users->toJson();                         // JSON string
```

## The `Enumerable` contract

`Enumerable` is the contract both `Collection` and (later) `LazyCollection`
implement. It extends `\IteratorAggregate` and `\JsonSerializable`, so any
collection is `foreach`-able and `json_encode()`-able.

Only methods that behave identically whether the data is eagerly in memory or
lazily streamed belong on the contract. Anything that forces materialization
(`count`, `sort`, `unique`, `toArray`) stays on `Collection`.

## Requirements

PHP **8.3 or newer**.

## Testing

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan (level 6)
composer security:audit  # dependency security advisories
```

## License

MIT