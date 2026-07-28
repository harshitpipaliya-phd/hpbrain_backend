<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/**
 * Policy Engine. Ported from api/src/policy/policy.service.ts, including the
 * composite AND/OR support the Node build gained in Sprint 8.
 *
 * Type strictness in the comparison operators is intentional: 'eq' uses ===,
 * so "5" never equals 5. A policy that authorizes execution must not be
 * loosened by type juggling.
 */
final class PolicyService
{
    /**
     * Dot-path lookup. Deliberately hand-rolled rather than using Laravel's
     * data_get(): domain rules must be testable without booting the framework,
     * and a policy engine that authorizes execution should not depend on a
     * helper whose semantics can change under it.
     */
    private function resolvePath(array $context, string $path): mixed
    {
        $value = $context;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function evaluateCondition(array $condition, array $context): bool
    {
        $actual = $this->resolvePath($context, (string) ($condition['field'] ?? ''));
        $value  = $condition['value'] ?? null;

        return match ($condition['operator'] ?? '') {
            'eq'  => $actual === $value,
            'neq' => $actual !== $value,
            'gte' => is_numeric($actual) && is_numeric($value) && $actual >= $value,
            'lte' => is_numeric($actual) && is_numeric($value) && $actual <= $value,
            'gt'  => is_numeric($actual) && is_numeric($value) && $actual >  $value,
            'lt'  => is_numeric($actual) && is_numeric($value) && $actual <  $value,
            'in'  => is_array($value) && in_array($actual, $value, true),
            default => false,
        };
    }

    /** Composite form wins when present; otherwise the flat field/operator/value form. */
    public function evaluateRule(array $rule, array $context): bool
    {
        $conditions = $rule['conditions'] ?? [];

        if ($conditions !== []) {
            $results = array_map(fn ($c) => $this->evaluateCondition($c, $context), $conditions);

            return ($rule['match'] ?? 'all') === 'any'
                ? in_array(true, $results, true)
                : ! in_array(false, $results, true);
        }

        return $this->evaluateCondition($rule, $context);
    }
}
