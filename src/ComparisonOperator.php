<?php

declare(strict_types=1);

namespace BlueprintAU\Collections;

/**
 * Comparison operators for the column form of `contains()`.
 *
 * Using a backed enum (rather than a raw string) makes the operator
 * type-safe: an invalid operator is a compile-time error at the call site,
 * never a silently-wrong boolean at runtime.
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
}