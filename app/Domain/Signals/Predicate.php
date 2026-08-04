<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Domain\Universal\ResolvedSource;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Compiles a JSON predicate from a rule row into Query Builder clauses.
 *
 * THE THREAT MODEL, stated plainly, because everything below follows from it.
 * A rule row is data an administrator writes through the API. If any part of it
 * reached the database as SQL text, that administrator would own the database
 * and every tenant in it. So:
 *
 *   - OPERATORS come from a closed set. An unknown operator throws; it is never
 *     passed through on the assumption that the database will understand it.
 *   - FIELDS are universal field names, resolved to columns through
 *     EntityResolver. A predicate cannot name a column directly, so it cannot
 *     name a column the tenant has not mapped, and it cannot smuggle an
 *     expression in where a column belongs.
 *   - VALUES are always bound, never interpolated. Every builder call below
 *     passes the value as a binding.
 *
 * There is no `raw` operator and no escape hatch. That is deliberate: an escape
 * hatch is added for one urgent case and then becomes the way things are done.
 * When the grammar is genuinely insufficient, the answer is to add a named
 * operator here — reviewed, bound, and closed — not to open a door.
 *
 * GRAMMAR
 *
 *   {"all": [ ...clauses ]}          every clause must hold
 *   {"any": [ ...clauses ]}          at least one clause must hold
 *   {"field": "unit", "op": "is_null"}
 *   {"field": "status", "op": "eq", "value": 1}
 *   {"field": "joinedDate", "op": "before_days", "value": 90}
 *
 * `all` and `any` nest. A bare clause is treated as a single-clause `all`.
 */
final class Predicate
{
    /**
     * The complete operator set. Anything outside it throws.
     *
     * before_days / after_days are relative to now and take a day count, so a
     * rule can say "assessed more than 90 days ago" without a rule author ever
     * writing a date expression.
     */
    public const OPERATORS = [
        'is_null', 'is_not_null',
        'eq', 'neq',
        'in', 'not_in',
        'lt', 'lte', 'gt', 'gte',
        'before_days', 'after_days',
    ];

    /** Operators that take no value. */
    private const NULLARY = ['is_null', 'is_not_null'];

    /** Operators whose value must be a list. */
    private const LIST_OPS = ['in', 'not_in'];

    /** op => the Query Builder comparison it maps to. */
    private const COMPARISON = [
        'eq' => '=', 'neq' => '!=',
        'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>=',
    ];

    /**
     * Apply a predicate to a query.
     *
     * @param  array<string, mixed>  $predicate
     * @param  string  $alias  table alias to qualify columns with, or '' for none
     */
    public static function apply(
        Builder $query,
        array $predicate,
        ResolvedSource $source,
        string $alias = '',
    ): Builder {
        $query->where(fn ($q) => self::compile($q, $predicate, $source, $alias));

        return $query;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function compile(Builder $q, array $node, ResolvedSource $source, string $alias): void
    {
        if (isset($node['all'])) {
            self::assertList($node['all'], 'all');

            foreach ($node['all'] as $child) {
                $q->where(fn ($sub) => self::compile($sub, $child, $source, $alias));
            }

            return;
        }

        if (isset($node['any'])) {
            self::assertList($node['any'], 'any');

            // An empty `any` would match nothing, which is a rule that never
            // fires — almost certainly a mistake in the row rather than an
            // intention, so it is refused rather than quietly never firing.
            if ($node['any'] === []) {
                throw new InvalidArgumentException('Predicate "any" must list at least one clause.');
            }

            foreach ($node['any'] as $child) {
                $q->orWhere(fn ($sub) => self::compile($sub, $child, $source, $alias));
            }

            return;
        }

        self::leaf($q, $node, $source, $alias);
    }

    /**
     * @param  array<string, mixed>  $clause
     */
    private static function leaf(Builder $q, array $clause, ResolvedSource $source, string $alias): void
    {
        $field = $clause['field'] ?? null;
        $op = $clause['op'] ?? null;

        if (! is_string($field) || $field === '') {
            throw new InvalidArgumentException('Predicate clause needs a "field".');
        }

        if (! is_string($op) || ! in_array($op, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported predicate operator %s. Supported: %s. '
                .'The grammar is closed on purpose — extend it here rather than adding raw SQL.',
                var_export($op, true),
                implode(', ', self::OPERATORS),
            ));
        }

        // Resolution is what keeps a predicate from naming an arbitrary column:
        // an unmapped field throws before any SQL is built.
        $column = $source->field($field);

        if ($alias !== '') {
            $column = $alias.'.'.$column;
        }

        if (in_array($op, self::NULLARY, true)) {
            $op === 'is_null' ? $q->whereNull($column) : $q->whereNotNull($column);

            return;
        }

        if (! array_key_exists('value', $clause)) {
            throw new InvalidArgumentException("Predicate operator '{$op}' needs a \"value\".");
        }

        $value = $clause['value'];

        if (in_array($op, self::LIST_OPS, true)) {
            if (! is_array($value) || $value === []) {
                throw new InvalidArgumentException("Predicate operator '{$op}' needs a non-empty list value.");
            }

            $op === 'in' ? $q->whereIn($column, $value) : $q->whereNotIn($column, $value);

            return;
        }

        if ($op === 'before_days' || $op === 'after_days') {
            if (! is_numeric($value) || $value < 0) {
                throw new InvalidArgumentException(
                    "Predicate operator '{$op}' needs a non-negative numeric day count. "
                    ."The direction is carried by the operator, so a negative count would "
                    ."silently mean the opposite of what the rule says."
                );
            }

            // BOTH operators measure N days BACK from now; they differ only in
            // which side of that line they take. before_days 90 is "more than 90
            // days ago" and after_days 90 is "within the last 90 days".
            //
            // The alternative — after_days meaning now PLUS N — would be
            // future-looking, and every column a rule reasons about (joined,
            // assessed, resolved) records something that already happened, so it
            // would match nothing and read as though it should.
            //
            // The cutoff is computed here and bound as a literal. A rule author
            // supplies a number of days and never a date expression.
            $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('-'.(int) $value.' days')
                ->format('Y-m-d H:i:s');

            $q->where($column, $op === 'before_days' ? '<' : '>', $cutoff);

            return;
        }

        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException("Predicate operator '{$op}' needs a scalar value.");
        }

        $q->where($column, self::COMPARISON[$op], $value);
    }

    /** @param mixed $value */
    private static function assertList($value, string $key): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Predicate \"{$key}\" must be a list of clauses.");
        }
    }

    /**
     * Validate a predicate without running it — for the API that accepts rule
     * rows, so a bad predicate is refused at write time rather than discovered
     * when the rule silently fails at evaluation.
     *
     * @param  array<string, mixed>  $predicate
     * @return array<int, string> field names the predicate reads
     */
    public static function fieldsUsed(array $predicate): array
    {
        $fields = [];

        $walk = function (array $node) use (&$walk, &$fields): void {
            foreach (['all', 'any'] as $key) {
                if (isset($node[$key])) {
                    self::assertList($node[$key], $key);

                    foreach ($node[$key] as $child) {
                        $walk($child);
                    }

                    return;
                }
            }

            $field = $node['field'] ?? null;
            $op = $node['op'] ?? null;

            if (! is_string($field) || $field === '') {
                throw new InvalidArgumentException('Predicate clause needs a "field".');
            }

            if (! is_string($op) || ! in_array($op, self::OPERATORS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported predicate operator %s.',
                    var_export($op, true),
                ));
            }

            $fields[$field] = true;
        };

        $walk($predicate);

        return array_keys($fields);
    }
}
