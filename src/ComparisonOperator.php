<?php

declare(strict_types=1);

namespace BlueprintAU\Collections;

/**
 * Comparison operators for the column form of `contains()` and `where()`.
 *
 * Using a backed enum (rather than a raw string) makes the operator
 * type-safe: an invalid operator is a compile-time error at the call site,
 * never a silently-wrong boolean at runtime.
 *
 * The comparison semantics live on the enum itself (`compare()`), so both
 * collection implementations — and any consumer code — share one definition
 * instead of duplicating it.
 */
enum ComparisonOperator: string
{
    case Equals = '=';
    case LooseEquals = '==';
    case NotEquals = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case In = 'in';
    case NotIn = 'not in';

    /**
     * Compare an actual column value against a target using this operator.
     *
     * The match is exhaustive over the enum cases, so every operator is
     * handled explicitly and there is no silent fallback. `Equals` is strict
     * (`===`); `LooseEquals` is loose (`==`). `In`/`NotIn` require `$value`
     * to be an array and compare strictly against its members.
     */
    public function compare(mixed $actual, mixed $value): bool
    {
        return match ($this) {
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
}