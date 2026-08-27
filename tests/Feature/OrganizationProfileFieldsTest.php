<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * THE ORGANIZATION RECORD IS AS WIDE AS THE SOURCE SYSTEM, AND NO WIDER.
 *
 * The screens above this endpoint used to render a fixed list of organization
 * fields — legal name, code, industry, country, timezone, currency — and print
 * "Not recorded" beside each one the Brain had never asked the ERP for. Six rows
 * of nothing, on a tenant whose `org_details` row held a registered address, a
 * registration number and a size band the whole time.
 *
 * Fixing that meant the response has to answer two different questions, and this
 * file exists to keep them apart:
 *
 *   · WHAT IS THE VALUE?           — the field, nullable, always present.
 *   · CAN THIS TENANT HOLD ONE?    — `profile_fields`, per tenant.
 *
 * A client that conflates them is back where it started: it cannot tell an
 * empty column from an absent one, so it must render both the same way. With
 * `profile_fields` it renders the fields that exist and omits the rest, which is
 * why the capability list is asserted here as carefully as the values are.
 *
 * The ERP fixture deliberately keeps the NARROW `org_details` — legal_name and
 * logo only — because that is the shape a deployment running an older copy of
 * the table has, and the mapping describes the wide one. Every test below is
 * therefore also a test that SourceSchema narrows a mapping to the columns that
 * are physically there instead of selecting one that is not.
 */
final class OrganizationProfileFieldsTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    /**
     * Give this tenant's profile table the columns the fuller ERP has.
     *
     * Nothing is re-mapped: the mapping already names these columns, and the
     * point of the exercise is that the SAME mapping produces a narrow record on
     * a narrow table and a wide one here.
     */
    private function widenProfileTable(): void
    {
        Schema::table('org_details', function ($t) {
            $t->string('email')->nullable();
            $t->string('mobile_no')->nullable();
            $t->string('website')->nullable();
            $t->text('registered_address')->nullable();
            $t->string('cin')->nullable();
            $t->string('gstin')->nullable();
            $t->string('employee_count')->nullable();
            $t->string('work_week')->nullable();
            $t->string('country_code')->nullable();
        });
    }

    /** @test */
    public function a_narrow_profile_table_claims_only_the_fields_it_has(): void
    {
        $row = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT)
            ->assertStatus(200)
            ->json('0');

        // The capability list is the whole point: two fields, because this
        // deployment's org_details carries two.
        $this->assertSame(['legalName', 'logo'], $row['profile_fields']);
        $this->assertSame(['name', 'code', 'industry'], $row['identity_fields']);

        // Every field is still PRESENT and null, so the payload has one shape
        // whatever the tenant's ERP looks like.
        foreach (['registration_number', 'tax_id', 'address', 'email', 'phone', 'website', 'employee_count', 'work_week'] as $absent) {
            $this->assertArrayHasKey($absent, $row);
            $this->assertNull($row[$absent], $absent.' has no column here and must not be invented.');
        }
    }

    /** @test */
    public function a_wider_profile_table_publishes_the_rest_of_the_record(): void
    {
        $this->widenProfileTable();

        DB::table('org_details')->where('sub_institute_id', 4)->update([
            'email'              => 'ops@sids.test',
            'mobile_no'          => '+91 99999 00000',
            'website'            => 'https://sids.test',
            'registered_address' => '12 Ring Road, Surat',
            'cin'                => 'U85320GJ2017P',
            'gstin'              => '24AAACS0000A1Z5',
            'employee_count'     => '51-200',
            'work_week'          => 'mon-sat',
        ]);

        $row = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT)
            ->assertStatus(200)
            ->json('0');

        $this->assertSame('ops@sids.test', $row['email']);
        $this->assertSame('+91 99999 00000', $row['phone']);
        $this->assertSame('https://sids.test', $row['website']);
        $this->assertSame('12 Ring Road, Surat', $row['address']);
        $this->assertSame('U85320GJ2017P', $row['registration_number']);
        $this->assertSame('24AAACS0000A1Z5', $row['tax_id']);
        $this->assertSame('51-200', $row['employee_count']);
        $this->assertSame('mon-sat', $row['work_week']);

        $this->assertContains('email', $row['profile_fields']);
        $this->assertContains('registrationNumber', $row['profile_fields']);
        $this->assertContains('employeeCount', $row['profile_fields']);
    }

    /**
     * `country_code` holds a dialling prefix ('+91'), not a country, so it is
     * deliberately NOT mapped to `country`. A test rather than a comment,
     * because the column name is inviting and the mistake is silent: the screen
     * would simply read "Country: +91".
     *
     * @test
     */
    public function the_dialling_prefix_column_is_never_published_as_a_country(): void
    {
        $this->widenProfileTable();
        DB::table('org_details')->where('sub_institute_id', 4)->update(['country_code' => '+91']);

        $row = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT)
            ->assertStatus(200)
            ->json('0');

        $this->assertNull($row['country']);
        $this->assertNotContains('country', $row['profile_fields']);
    }

    /** @test */
    public function editing_writes_every_field_this_tenant_can_hold(): void
    {
        $this->widenProfileTable();

        $this->withHeaders($this->auth())
            ->patchJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT, [
                'name'               => 'SIDS Health',
                'legalName'          => 'SIDS Health Pvt Ltd',
                'email'              => 'hello@sids.test',
                'phone'              => '+91 88888 11111',
                'address'            => '9 Ring Road',
                'registrationNumber' => 'U11111GJ2020P',
                'employeeCount'      => '201-500',
            ])
            ->assertStatus(200)
            // The shipped contract, kept: clients already depend on {ok:true}.
            ->assertJson(['ok' => true]);

        $identity = DB::table('institute_detail')->where('sub_institute_id', 4)->first();
        $this->assertSame('SIDS Health', $identity->organization_name);

        $profile = DB::table('org_details')->where('sub_institute_id', 4)->first();
        $this->assertSame('SIDS Health Pvt Ltd', $profile->legal_name);
        $this->assertSame('hello@sids.test', $profile->email);
        $this->assertSame('+91 88888 11111', $profile->mobile_no);
        $this->assertSame('9 Ring Road', $profile->registered_address);
        $this->assertSame('U11111GJ2020P', $profile->cin);
        $this->assertSame('201-500', $profile->employee_count);
    }

    /**
     * A field the tenant cannot hold is DROPPED, not rejected.
     *
     * The client is told up front which fields exist and only offers those, so a
     * request carrying one it cannot hold is a stale tab or another client — and
     * failing the whole edit because of it would throw away the six fields that
     * were perfectly writable.
     *
     * @test
     */
    public function a_field_this_tenant_cannot_hold_is_ignored_rather_than_failing_the_edit(): void
    {
        // org_details is still narrow here: no `email` column exists.
        $this->withHeaders($this->auth())
            ->patchJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT, [
                'legalName' => 'Still Writable Ltd',
                'email'     => 'nowhere@sids.test',
            ])
            ->assertStatus(200);

        $profile = DB::table('org_details')->where('sub_institute_id', 4)->first();
        $this->assertSame('Still Writable Ltd', $profile->legal_name);
        $this->assertFalse(Schema::hasColumn('org_details', 'email'));
    }

    /**
     * An edit made ENTIRELY of fields this tenant cannot hold is a different
     * case, and it must say so rather than reporting a successful no-op.
     *
     * @test
     */
    public function an_edit_of_nothing_writable_is_refused(): void
    {
        $this->withHeaders($this->auth())
            ->patchJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT, [
                'website' => 'https://nowhere.test',
            ])
            ->assertStatus(422)
            ->assertJson(['error' => 'no_editable_fields_for_tenant']);
    }
}
