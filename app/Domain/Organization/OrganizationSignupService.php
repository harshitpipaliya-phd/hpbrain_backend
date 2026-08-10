<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Universal\EntityResolver;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service organization signup: creates a TENANT and its first administrator.
 *
 * WHY THIS IS NOT OrganizationRepository::create(). That method creates the two
 * rows the Brain reads (institute_detail, org_details) and one 'Employee'
 * profile. It creates no tenant root, no client, no user, and no entity
 * mappings — because it was built for an authenticated admin adding an
 * organization inside an ERP that already exists. Self-service signup has no
 * such ERP to lean on: the tenant does not exist yet, so every layer under it
 * has to be created here.
 *
 * It also allocates its tenant id as `MAX(institute_detail.sub_institute_id)+1`,
 * which is not safe to reuse. institute_detail is a SATELLITE table with no
 * unique key and no foreign key; the real tenant register is school_setup, and
 * the two are not in step (live: max sub_institute_id is 8, while school_setup
 * holds ids up to 11). MAX()+1 therefore hands out 9 — an id that already
 * belongs to a different customer, whose rows the new tenant's people would
 * then be foreign-keyed into. This class takes the id from school_setup's
 * AUTO_INCREMENT instead, which is the only allocator the database itself
 * guarantees to be unique and concurrency-safe.
 *
 * THE ORDER BELOW IS FORCED BY THE FOREIGN KEYS, not chosen:
 *
 *   tblclient                                     -> clientId
 *     school_setup.client_id -> tblclient.id      -> subInstituteId (THE TENANT)
 *       institute_detail.sub_institute_id          (Brain: Organization)
 *       org_details.sub_institute_id               (Brain: OrganizationProfile)
 *       tbluserprofilemaster.sub_institute_id      -> adminProfileId
 *         tbluser.sub_institute_id
 *         tbluser.user_profile_id -> tbluserprofilemaster.id
 *
 * Then, outside the ERP entirely:
 *
 *   hpbrain_entity_mappings for the new tenant.
 *
 * THAT LAST STEP IS NOT OPTIONAL AND IS THE EASIEST ONE TO FORGET. Login
 * searches only tenants that map the Person entity (AuthController::
 * findPersonByEmail -> EntityResolver::everyTenantWith), so a tenant with no
 * mapping rows cannot sign in at all — its users are not merely unauthorized,
 * they are invisible to the lookup. Four tenants on the live database (5, 9, 10
 * and 11) are in exactly that state today. A signup that skipped this would
 * create a complete, correct, permanently unreachable organization.
 *
 * ATOMICITY. Everything above is one transaction. A partially provisioned
 * tenant is worse than no tenant: it holds an id, it may hold a user whose
 * globally-unique email is now taken, and it cannot log in. Only DML runs
 * inside, so there is no implicit DDL commit to defeat the rollback.
 *
 * PASSWORDS. Hash::make only, plain_password explicitly NULL. The legacy
 * institute signup wrote the literal string 'admin' into both password and
 * plain_password and emailed it; that is the behaviour this replaces, so it is
 * worth saying that plain_password is written rather than left out — writing
 * NULL states the intent, and AuthController::verifyErpPassword treats a
 * non-null plain_password as a legacy credential to be migrated on next login.
 *
 * THE ADMINISTRATOR IS DERIVED, NOT COLLECTED. The signup form asks for an
 * organization; it does not ask who is filling it in. The tenant administrator
 * is still a real, complete tbluser row — it has to be, because it is the only
 * way anyone signs in — but every field of it comes from the organization:
 *
 *   email            = the organization email
 *   password         = the password chosen on the form, hashed
 *   user_profile_id  = this tenant's 'Admin' profile
 *   first_name       = 'Administrator'   (a role, honestly labelled)
 *   last_name        = ''                (unknown; not invented)
 *   user_name        = 'admin.<org slug>'
 *   mobile           = the organization mobile, or NULL
 *
 * That is legitimate rather than a shortcut, and the schema is what says so:
 * first_name, last_name, mobile and user_name are all NULLABLE, and the live
 * database already holds 168 users with no user_name and 100 with no mobile.
 * The three columns tbluser actually demands — password, email,
 * user_profile_id — are all supplied from real input.
 *
 * A NAME IS NOT INVENTED FOR THE PERSON. last_name is written as an empty
 * string rather than guessed from the email local part or the organization
 * name. The product does not know who this is yet, and a fabricated surname
 * would be indistinguishable from a real one the moment a second user joins.
 * 'Administrator' as the first name is a description of the account, which is
 * exactly what the account is until someone edits it.
 */
final class OrganizationSignupService
{
    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    /**
     * The user profiles every new tenant gets.
     *
     * 'Admin' is what makes the signing-up person an administrator:
     * AuthController::resolveRole() maps a profile name containing 'admin' to
     * the tenant_admin role, which Role::permissions() grants everything —
     * including settings.manage, which every ingestion route requires.
     *
     * 'Employee' is separately mandatory. PersonRepository resolves it on every
     * person create and throws when it is missing, so an organization without
     * one cannot add its own people. OrganizationRepository::create() provisions
     * it for the same reason.
     *
     * 'HR' completes the set the live tenants actually carry (verified against
     * tenants 7 and 8, which hold exactly Admin/Employee/HR).
     *
     * @var array<int, string>
     */
    private const PROFILES = ['Admin', 'Employee', 'HR'];

    private const ADMIN_PROFILE = 'Admin';

    /**
     * The derived administrator's display name.
     *
     * A description of the account rather than a guess at a person. See the
     * class docblock on why no surname is fabricated.
     */
    private const ADMIN_FIRST_NAME = 'Administrator';

    /**
     * @param  array<string, mixed>  $input  validated SignupRequest payload
     * @return array{tenantId: string, clientId: int, userId: int, adminProfileId: int,
     *               organizationName: string, adminEmail: string, adminFirstName: string,
     *               adminLastName: string, profileIds: array<string, int>}
     *
     * @throws SignupConflictException when a unique key is taken by a concurrent signup
     */
    public function provision(array $input): array
    {
        try {
            $result = DB::transaction(function () use ($input): array {
                return $this->write($input);
            });
        } catch (QueryException $e) {
            // The pre-flight checks in SignupRequest close the common case, but
            // they are a read followed by a write and two signups can interleave
            // between them. tbluser.email carries a GLOBAL unique index, so the
            // database is the only thing that can actually settle it. Translated
            // here rather than surfacing as a 500, because a second person
            // claiming an address is an ordinary outcome, not a server fault.
            if ($this->isDuplicateKey($e)) {
                throw new SignupConflictException(
                    'That email address is already registered.',
                    previous: $e,
                );
            }

            throw $e;
        }

        // Outside the transaction: the mappings are committed, so anything that
        // resolves this tenant during the rest of the request must not keep a
        // cached "unmapped" answer. Cheap, and the alternative is a login that
        // works only after the next request.
        $this->resolver->flush($result['tenantId']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{tenantId: string, clientId: int, userId: int, adminProfileId: int,
     *               organizationName: string, adminEmail: string, adminFirstName: string,
     *               adminLastName: string, profileIds: array<string, int>}
     */
    private function write(array $input): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $year = now()->format('Y');

        $orgName = trim((string) $input['organizationName']);
        $email = (string) $input['organizationEmail'];
        $mobile = ($input['organizationMobile'] ?? '') !== '' ? (string) $input['organizationMobile'] : null;

        // The organization is the contact. No person's name was collected, so
        // the organization's own name is the honest value for every
        // contact/handler column rather than a placeholder.
        $contact = $orgName;

        // ---- 1. Client -----------------------------------------------------
        // The billing/ownership parent. school_setup.client_id points at it, so
        // it has to exist first even though self-service signup is one school
        // per client.
        $clientId = (int) DB::table('tblclient')->insertGetId([
            'client_name'           => $orgName,
            'short_code'            => $this->shortCode($orgName),
            'email'                 => $email,
            'address'               => $input['address'] ?? null,
            'city'                  => $input['city'] ?? null,
            'state'                 => $input['state'] ?? null,
            'country'               => $input['country'] ?? null,
            'contact_person'        => $contact,
            'contact_person_mobile' => $mobile,
            // Real column name, misspelled in the schema. Spelled as the
            // database spells it, not as it should have been.
            'contact_persoon_email' => $email,
            'number_of_schools'     => 1,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        // ---- 2. Tenant root ------------------------------------------------
        // THIS INSERT ALLOCATES THE TENANT ID. Roughly sixty ERP tables carry a
        // foreign key to school_setup.id under the name sub_institute_id, and
        // the JWT tenant claim is this value. Taken from AUTO_INCREMENT via
        // insertGetId — never computed from a MAX().
        //
        // institute_type is deliberately not written. It is nullable, nothing in
        // the Brain reads it, and requiring a school/college choice from an
        // organization that may be neither was the legacy form's mistake.
        $subInstituteId = (int) DB::table('school_setup')->insertGetId([
            'SchoolName'    => $orgName,
            'ShortCode'     => $this->shortCode($orgName),
            'ContactPerson' => $contact,
            'Mobile'        => $mobile,
            'Email'         => $email,
            'Logo'          => $input['logo'] ?? null,
            'SortOrder'     => 0,
            'client_id'     => $clientId,
            'is_lms'        => 'N',
            'syear'         => $year,
            'expire_date'   => now()->addYear()->format('Y-m-d'),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $tenantId = (string) $subInstituteId;

        // ---- 3. Organization, as the Brain reads it ------------------------
        // EntityMappingSeeder maps the universal Organization entity to
        // institute_detail, and AuthController::loadOrganization joins it on
        // every login. Without this row the organization resolves to the
        // "Organization {id}" placeholder and OrganizationRepository::list()
        // returns nothing, so the workspace opens with no organization at all.
        //
        // created_by is filled in at step 6, once there is a user to credit.
        DB::table('institute_detail')->insert([
            'sub_institute_id'    => $subInstituteId,
            'organization_name'   => $orgName,
            'organization_code'   => $this->shortCode($orgName),
            'organization_email'  => $email,
            'organization_ph_no'  => $mobile,
            'address'             => $input['address'] ?? null,
            'industry_type'       => $input['industry'] ?? null,
            'handler_name'        => $contact,
            'handler_email'       => $email,
            'handler_mobile'      => $mobile,
            'created_at'          => $now,
            'updated_at'          => $now,
            'deleted_at'          => null,
        ]);

        // ---- 4. Organization profile ---------------------------------------
        DB::table('org_details')->insert([
            'sub_institute_id' => $subInstituteId,
            'legal_name'       => $input['legalName'] ?? $orgName,
            'industry'         => $input['industry'] ?? null,
            'logo'             => $input['logo'] ?? null,
            'email'            => $email,
            'mobile_no'        => $mobile,
            'country_code'     => '+91',
            'registered_address' => $input['address'] ?? null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        // ---- 5. User profiles ----------------------------------------------
        /** @var array<string, int> $profileIds */
        $profileIds = [];

        foreach (self::PROFILES as $index => $name) {
            $profileIds[$name] = (int) DB::table('tbluserprofilemaster')->insertGetId([
                // The ERP's own convention: Admin is the root profile and points
                // at itself as parent (see the legacy institute signup, which
                // writes parent_id 1 for Admin and 0 for the rest).
                'parent_id'        => $name === self::ADMIN_PROFILE ? 1 : 0,
                'name'             => $name,
                'description'      => $name,
                'sort_order'       => $index + 1,
                'status'           => 1,
                'sub_institute_id' => $subInstituteId,
                'client_id'        => $clientId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        $adminProfileId = $profileIds[self::ADMIN_PROFILE];

        // ---- 6. The administrator ------------------------------------------
        // DERIVED, NOT COLLECTED — see the class docblock. password is NOT NULL
        // with no default; email is NOT NULL and globally unique;
        // user_profile_id is NOT NULL. Those three are the only columns the
        // table actually demands, and all three come from real input: the
        // password the user chose, the organization email, and this tenant's
        // own Admin profile. Everything else below is nullable.
        $userId = (int) DB::table('tbluser')->insertGetId([
            'user_name'        => $this->userName($orgName),
            'password'         => Hash::make($input['password']),
            // Never the raw password. See the class docblock.
            'plain_password'   => null,
            'first_name'       => self::ADMIN_FIRST_NAME,
            'middle_name'      => '',
            // Empty rather than a guess. The product does not know who this is.
            'last_name'        => '',
            'email'            => $email,
            'mobile'           => $mobile,
            'user_profile_id'  => $adminProfileId,
            'sub_institute_id' => $subInstituteId,
            'client_id'        => $clientId,
            'is_admin'         => 1,
            'status'           => 1,
            'join_year'        => $year,
            'joined_date'      => now()->format('Y-m-d'),
            'created_at'       => $now,
            'updated_at'       => $now,
            'deleted_at'       => null,
        ]);

        // Now that the creator exists, credit them. Done as an update rather
        // than by reordering the inserts because institute_detail must exist
        // before... nothing, in fact — but the user needs a profile, the
        // profile needs the tenant, and the tenant is what institute_detail
        // describes. The cycle is real; this breaks it.
        DB::table('institute_detail')->where('sub_institute_id', $subInstituteId)
            ->update(['created_by' => $userId, 'updated_by' => $userId]);
        DB::table('org_details')->where('sub_institute_id', $subInstituteId)
            ->update(['created_by' => $userId, 'updated_by' => $userId]);

        // ---- 7. Entity mappings --------------------------------------------
        // The seeder's constructor takes an explicit tenant list for exactly
        // this: a tenant that is not yet in the organization register it would
        // otherwise read. Reusing it rather than restating 39 mapping rows here
        // keeps one definition of where the institute ERP keeps its entities —
        // if that description changes, signup follows it automatically.
        (new EntityMappingSeeder([$tenantId]))->run();

        return [
            'tenantId'         => $tenantId,
            'clientId'         => $clientId,
            'userId'           => $userId,
            'adminProfileId'   => $adminProfileId,
            'organizationName' => $orgName,
            // Returned rather than re-derived by the controller, so the values
            // in the response are the values that were actually written.
            'adminEmail'       => $email,
            'adminFirstName'   => self::ADMIN_FIRST_NAME,
            'adminLastName'    => '',
            'profileIds'       => $profileIds,
        ];
    }

    /**
     * A short, stable code for the organization.
     *
     * Not unique and not used as a key — organization_code is a display and
     * search convenience. Derived rather than asked for, because one more
     * required field on a signup form for something the product can infer is a
     * field that gets filled with rubbish.
     */
    private function shortCode(string $name): string
    {
        $upper = strtoupper((string) preg_replace('/[^A-Za-z0-9 ]/', '', $name));
        $words = preg_split('/\s+/', trim($upper), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return 'ORG';
        }

        // One word -> first eight characters. Several -> initials, capped, so
        // "Fiber Valley Networks Private Limited" becomes FVNPL, not a sentence.
        $code = count($words) === 1
            ? substr($words[0], 0, 8)
            : implode('', array_map(static fn (string $w): string => $w[0], array_slice($words, 0, 6)));

        return $code === '' ? 'ORG' : $code;
    }

    /**
     * A readable login name for the derived administrator: admin.<org slug>.
     *
     * user_name is nullable and NOT unique in this schema (verified: plain
     * index, and 168 live rows have none), and nothing authenticates against
     * it — login is by email. It exists so the row is identifiable to a human
     * reading the ERP's own screens, which is why it names the organization
     * rather than being a bare 'admin' repeated per tenant.
     */
    private function userName(string $orgName): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $orgName));

        return 'admin'.($slug === '' ? '' : '.'.substr($slug, 0, 24));
    }

    /**
     * Whether a query failure is a unique-constraint violation.
     *
     * MySQL reports SQLSTATE 23000 with driver code 1062. SQLite reports 23000
     * too, but with 'UNIQUE constraint failed' in the message and a different
     * driver code — and the test suite runs on SQLite, so both are matched or
     * the translation would be untestable.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
