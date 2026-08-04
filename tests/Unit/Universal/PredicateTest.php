<?php

declare(strict_types=1);

namespace Tests\Unit\Universal;

use App\Domain\Signals\Predicate;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\UnsupportedEntityException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The predicate grammar, tested mostly for what it REFUSES.
 *
 * A rule row is data an administrator writes through the API. If any part of it
 * reached the database as SQL text, that administrator would own the database
 * and every tenant in it. The operator set is closed, fields are resolved rather
 * than named, and values are bound — the tests below are what keeps all three
 * true as the grammar grows.
 */
final class PredicateTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 't1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        \Illuminate\Support\Facades\Schema::create('people', function ($t) {
            $t->integer('id')->primary();
            $t->string('org', 36);
            $t->string('email')->nullable();
            $t->integer('status')->default(1);
            $t->integer('dept')->nullable();
            $t->date('joined')->nullable();
        });

        foreach ([
            [1, 'a@x.test', 1, 10, '2020-01-01'],
            [2, null,       1, 0,  '2026-08-01'],
            [3, '',         0, 20, null],
            [4, 'd@x.test', 1, null, '2026-07-01'],
        ] as [$id, $email, $status, $dept, $joined]) {
            DB::table('people')->insert([
                'id' => $id, 'org' => self::TENANT, 'email' => $email,
                'status' => $status, 'dept' => $dept, 'joined' => $joined,
            ]);
        }

        foreach ([
            'id' => 'id', 'tenantKey' => 'org', 'email' => 'email',
            'status' => 'status', 'unit' => 'dept', 'joinedDate' => 'joined',
        ] as $universal => $column) {
            DB::table('hpbrain_entity_mappings')->insert([
                'id' => 'm-'.$universal, 'tenant_id' => self::TENANT,
                'source_system' => 'erp', 'source_entity' => 'people',
                'source_field' => $column, 'universal_entity' => 'Person',
                'universal_field' => $universal, 'mapping_type' => 'direct',
                'is_active' => true, 'created_by' => 'test',
                'created_date' => '2026-08-04 00:00:00', 'updated_date' => '2026-08-04 00:00:00',
            ]);
        }
    }

    /** @param array<string, mixed> $predicate @return array<int, int> */
    private function ids(array $predicate): array
    {
        $source = (new EntityResolver())->resolve(self::TENANT, 'Person');
        $query = DB::table($source->table)->where($source->tenantKey, self::TENANT);

        Predicate::apply($query, $predicate, $source);

        $ids = $query->pluck('id')->map(fn ($v) => (int) $v)->all();
        sort($ids);

        return $ids;
    }

    // ---- the grammar works ------------------------------------------------

    /** @test */
    public function is_null_and_is_not_null(): void
    {
        $this->assertSame([2], $this->ids(['field' => 'email', 'op' => 'is_null']));
        $this->assertSame([1, 3, 4], $this->ids(['field' => 'email', 'op' => 'is_not_null']));
    }

    /** @test */
    public function eq_and_neq(): void
    {
        $this->assertSame([1, 2, 4], $this->ids(['field' => 'status', 'op' => 'eq', 'value' => 1]));
        $this->assertSame([3], $this->ids(['field' => 'status', 'op' => 'neq', 'value' => 1]));
    }

    /** @test */
    public function in_and_not_in(): void
    {
        $this->assertSame([1, 3], $this->ids(['field' => 'unit', 'op' => 'in', 'value' => [10, 20]]));
        // SQL three-valued logic: person 4's NULL unit is neither in nor not-in.
        $this->assertSame([2], $this->ids(['field' => 'unit', 'op' => 'not_in', 'value' => [10, 20]]));
    }

    /** @test */
    public function comparisons(): void
    {
        // units are 10, 0, 20 and NULL. NULL satisfies neither comparison.
        $this->assertSame([1, 2], $this->ids(['field' => 'unit', 'op' => 'lt', 'value' => 20]));
        $this->assertSame([1, 3], $this->ids(['field' => 'unit', 'op' => 'gte', 'value' => 10]));
    }

    /** @test */
    public function before_days_and_after_days_take_a_day_count_not_a_date(): void
    {
        // A rule author supplies a number; the cutoff is computed and bound here,
        // so no date expression ever originates in a rule row.
        //
        // Person 1 joined in 2020, so only they are more than a year back. The
        // fixture's other dated rows are recent, and person 3 has no date at all
        // — NULL is neither before nor after, which is correct: an unrecorded
        // joining date is not an old one.
        // Both measure N days BACK and differ only in which side they take:
        // before_days 365 is "joined more than a year ago", after_days 365 is
        // "joined within the last year".
        $this->assertSame([1], $this->ids(['field' => 'joinedDate', 'op' => 'before_days', 'value' => 365]));
        $this->assertSame([2, 4], $this->ids(['field' => 'joinedDate', 'op' => 'after_days', 'value' => 365]));
    }

    /** @test */
    public function a_negative_day_count_is_refused(): void
    {
        // The direction is carried by the operator. A negative count would make
        // before_days mean after, which is a rule that does the opposite of what
        // it reads as.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-negative/');
        $this->ids(['field' => 'joinedDate', 'op' => 'after_days', 'value' => -7]);
    }

    /** @test */
    public function all_and_any_compose_and_nest(): void
    {
        $this->assertSame([2], $this->ids(['all' => [
            ['field' => 'status', 'op' => 'eq', 'value' => 1],
            ['any' => [
                ['field' => 'unit', 'op' => 'is_null'],
                ['field' => 'unit', 'op' => 'eq', 'value' => 0],
            ]],
            ['field' => 'email', 'op' => 'is_null'],
        ]]));
    }

    /** @test */
    public function any_does_not_leak_past_its_own_group(): void
    {
        // The bug this shape guards against: an unparenthesised OR binding across
        // the tenant filter, which is how a scoped count becomes a global one.
        $ids = $this->ids(['all' => [
            ['field' => 'status', 'op' => 'eq', 'value' => 0],
            ['any' => [
                ['field' => 'unit', 'op' => 'eq', 'value' => 20],
                ['field' => 'unit', 'op' => 'eq', 'value' => 10],
            ]],
        ]]);

        $this->assertSame([3], $ids, 'The OR must not escape the AND that contains it.');
    }

    // ---- the grammar refuses ---------------------------------------------

    /** @test */
    public function an_unknown_operator_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported predicate operator/');
        $this->ids(['field' => 'email', 'op' => 'regexp', 'value' => '.*']);
    }

    /** @test */
    public function there_is_no_raw_sql_escape_hatch(): void
    {
        // Named explicitly so that adding one has to delete a test that says why
        // it must not exist.
        $this->assertNotContains('raw', Predicate::OPERATORS);
        $this->assertNotContains('sql', Predicate::OPERATORS);
        $this->assertNotContains('expr', Predicate::OPERATORS);

        $this->expectException(InvalidArgumentException::class);
        $this->ids(['field' => 'email', 'op' => 'raw', 'value' => '1=1']);
    }

    /** @test */
    public function a_field_the_tenant_has_not_mapped_is_refused(): void
    {
        // A predicate names UNIVERSAL fields, so it cannot reach a column the
        // tenant has not mapped — and cannot smuggle an expression in where a
        // column belongs.
        $this->expectException(UnsupportedEntityException::class);
        $this->ids(['field' => 'salary', 'op' => 'gt', 'value' => 0]);
    }

    /** @test */
    public function a_value_carrying_sql_is_bound_not_interpreted(): void
    {
        // The injection attempt that matters: if this were interpolated, every
        // row would come back. Bound, it simply matches nothing.
        $this->assertSame([], $this->ids([
            'field' => 'email', 'op' => 'eq', 'value' => "x' OR '1'='1",
        ]));
    }

    /** @test */
    public function a_field_name_carrying_sql_is_refused_rather_than_resolved(): void
    {
        $this->expectException(UnsupportedEntityException::class);
        $this->ids(['field' => 'email OR 1=1 --', 'op' => 'is_not_null']);
    }

    /** @test */
    public function a_clause_without_a_field_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a "field"/');
        $this->ids(['op' => 'is_null']);
    }

    /** @test */
    public function an_operator_needing_a_value_is_refused_without_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a "value"/');
        $this->ids(['field' => 'status', 'op' => 'eq']);
    }

    /** @test */
    public function a_list_operator_is_refused_a_scalar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty list/');
        $this->ids(['field' => 'unit', 'op' => 'in', 'value' => 10]);
    }

    /** @test */
    public function a_scalar_operator_is_refused_an_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a scalar value/');
        $this->ids(['field' => 'status', 'op' => 'eq', 'value' => [1, 2]]);
    }

    /** @test */
    public function an_empty_any_is_refused_rather_than_never_firing(): void
    {
        // An empty OR matches nothing, so the rule would silently never fire.
        // Almost certainly a mistake in the row, so it is refused loudly.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one clause/');
        $this->ids(['any' => []]);
    }

    /** @test */
    public function before_days_is_refused_a_non_numeric_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/numeric day count/');
        $this->ids(['field' => 'joinedDate', 'op' => 'before_days', 'value' => 'NOW()']);
    }

    // ---- validation without execution ------------------------------------

    /** @test */
    public function fields_used_reports_what_a_predicate_reads(): void
    {
        // For the write path: a rule can be validated when it is saved rather
        // than discovered to be broken when it silently fails to fire.
        $fields = Predicate::fieldsUsed(['all' => [
            ['field' => 'status', 'op' => 'eq', 'value' => 1],
            ['any' => [
                ['field' => 'unit', 'op' => 'is_null'],
                ['field' => 'email', 'op' => 'is_null'],
            ]],
        ]]);

        sort($fields);
        $this->assertSame(['email', 'status', 'unit'], $fields);
    }

    /** @test */
    public function fields_used_refuses_a_bad_operator_without_touching_the_database(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Predicate::fieldsUsed(['field' => 'email', 'op' => 'drop_table']);
    }
}
