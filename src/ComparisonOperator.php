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

    /**
     * Compare an actual column value against a target using this operator.
     *
     * `In` and `NotIn` require the target to be an array.
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
        };
    }
}