<?php

declare(strict_types=1);

namespace BlueprintAU\Collections;

/**
 * Comparison operators for the column form of `contains()` and `where()`.
 */
enum ComparisonOperator
{
    case Equals;
    case LooseEquals;
    case NotEquals;
    case StrictNotEquals;
    case GreaterThan;
    case GreaterThanOrEqual;
    case LessThan;
    case LessThanOrEqual;
    case In;
    case NotIn;
    case Between;
    case NotBetween;
    case StartsWith;
    case EndsWith;
    case Contains;

    /**
     * Compare an actual column value against `$value` using this operator.
     *
     * `In` and `NotIn` require `$value` to be an array.
     * `Between` and `NotBetween` require `$value` to be a two-element
     * `[min, max]` array.
     * The string operators require `$value` to be a string.
     */
    public function compare(mixed $actual, mixed $value): bool
    {
        return match ($this) {
            ComparisonOperator::Equals => $actual === $value,
            ComparisonOperator::LooseEquals => $actual == $value,
            ComparisonOperator::NotEquals => $actual != $value,
            ComparisonOperator::StrictNotEquals => $actual !== $value,
            ComparisonOperator::GreaterThan => $actual > $value,
            ComparisonOperator::GreaterThanOrEqual => $actual >= $value,
            ComparisonOperator::LessThan => $actual < $value,
            ComparisonOperator::LessThanOrEqual => $actual <= $value,
            ComparisonOperator::In => is_array($value) && in_array($actual, $value, true),
            ComparisonOperator::NotIn => is_array($value) && !in_array($actual, $value, true),
            ComparisonOperator::Between => is_array($value)
                && count($value) === 2
                && $actual >= $value[0]
                && $actual <= $value[1],
            ComparisonOperator::NotBetween => is_array($value)
                && count($value) === 2
                && ($actual < $value[0] || $actual > $value[1]),
            ComparisonOperator::StartsWith => is_string($actual) && is_string($value)
                && str_starts_with($actual, $value),
            ComparisonOperator::EndsWith => is_string($actual) && is_string($value)
                && str_ends_with($actual, $value),
            ComparisonOperator::Contains => is_string($actual) && is_string($value)
                && str_contains($actual, $value),
        };
    }
}