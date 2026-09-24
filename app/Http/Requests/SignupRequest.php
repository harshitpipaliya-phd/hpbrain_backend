<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for self-service organization signup.
 *
 * THE FORM ASKS FOR AN ORGANIZATION, NOT FOR A PERSON. Four inputs: name,
 * email, an optional mobile, and a password. Everything the administrator
 * record needs beyond that is derived by OrganizationSignupService, because it
 * can be — see that class for what and why.
 *
 * THE ORGANIZATION EMAIL IS THE LOGIN EMAIL, and that is a claim about the
 * database rather than a convenience. Verified against the live schema:
 *
 *   - tbluser.email is the ONLY unique index in the six tables signup writes,
 *     and it is the column AuthController::findPersonByEmail() searches.
 *   - institute_detail.organization_email, school_setup.Email, org_details.email
 *     and tblclient.email carry no unique index at all, so the same address
 *     sitting in all five places conflicts with nothing.
 *
 * So one address can be both without inventing an identifier, without a second
 * authentication path, and without touching the login code. The uniqueness rule
 * therefore moved onto organizationEmail: it is now the thing that has to be
 * free in tbluser.
 *
 * NO OTP, NO INSTITUTE TYPE, NO CAPTCHA. None are accepted, and institute_type
 * is a nullable column nothing in the Brain reads.
 *
 * WHAT IS AND IS NOT A DUPLICATE, decided against the live database:
 *
 *   email      UNIQUE, enforced here AND by tbluser_email_unique. The index is
 *              GLOBAL, not per tenant.
 *
 *   org name   UNIQUE among live organizations. school_setup holds no duplicate
 *              SchoolName, so uniqueness is the actual convention; enforcing it
 *              stops two people creating indistinguishable tenants.
 *              Soft-deleted rows are ignored, so a name frees up on removal.
 *
 *   mobile     NOT unique and NOT required. Nullable in all five tables that
 *              hold it, already NULL for 100 of 389 live users, and 109 numbers
 *              are shared by more than one person. It is optional contact
 *              detail, so it is validated for FORMAT only and only when given.
 *
 * PASSWORD RULES ARE DELIBERATELY MINIMAL. No composition requirement: no
 * mixed case, no digit, no symbol. Those rules push people towards
 * "Password1!" and are not what makes a stored credential safe — the bcrypt
 * hash does that, and it is unchanged. What remains is a length floor and a
 * confirmation, which are the two things that actually catch a mistake.
 */
final class SignupRequest extends FormRequest
{
    /**
     * Minimum password length.
     *
     * Eight, which is the NIST SP 800-63B floor and the one length rule that is
     * not arbitrary. Named rather than inlined so it is a single edit if the
     * product wants it lower — nothing else in the codebase depends on it.
     */
    private const MIN_PASSWORD = 8;

    /** Public endpoint: authorization is the throttle and the validation below. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ---- Organization --------------------------------------------
            'organizationName' => [
                'required', 'string', 'min:2', 'max:191',
                Rule::unique('school_setup', 'SchoolName')->whereNull('deleted_at'),
            ],

            // Doubles as the administrator's login address, so it carries the
            // uniqueness rule for tbluser.email.
            'organizationEmail' => [
                'required', 'email:rfc', 'max:191',
                Rule::unique('tbluser', 'email'),
            ],

            // Optional. Nullable in every table that stores it; asking for it
            // as a required field would be inventing a constraint the database
            // does not have.
            'organizationMobile' => ['nullable', 'string', 'regex:/^[6-9][0-9]{9}$/'],

            // ---- Security --------------------------------------------------
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD, 'confirmed'],

            // ---- Optional organization detail, supported by the schema ------
            // Not on the form. Accepted so an API client can supply them, since
            // every one maps to a real nullable column.
            'logo'      => ['nullable', 'string', 'max:191', 'url'],
            'legalName' => ['nullable', 'string', 'max:191'],
            'industry'  => ['nullable', 'string', 'max:191'],
            'address'   => ['nullable', 'string', 'max:191'],
            'city'      => ['nullable', 'string', 'max:191'],
            'state'     => ['nullable', 'string', 'max:191'],
            'country'   => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organizationName.required' => 'Enter your organization name.',
            'organizationName.unique'   => 'An organization with this name already exists.',
            'organizationEmail.required' => 'Enter your organization email.',
            'organizationEmail.email'    => 'Enter a valid email address.',
            'organizationEmail.unique'   => 'That email address is already registered.',
            'organizationMobile.regex'   => 'Enter a valid 10-digit mobile number.',
            'password.required'  => 'Choose a password.',
            'password.min'       => 'Use at least '.self::MIN_PASSWORD.' characters.',
            'password.confirmed' => 'The two passwords do not match.',
            'logo.url'           => 'The logo must be a full URL.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organizationName'   => 'organization name',
            'organizationEmail'  => 'organization email',
            'organizationMobile' => 'organization mobile',
        ];
    }

    /**
     * Trim before validating, so " Acme " and "Acme" are the same organization
     * for the uniqueness check rather than two rows that look identical.
     *
     * The password is deliberately NOT trimmed: leading or trailing spaces are
     * legitimate characters in a password the user chose, and silently removing
     * them here would store a different credential than the one they typed.
     */
    protected function prepareForValidation(): void
    {
        $trim = static fn (mixed $v): mixed => is_string($v) ? trim($v) : $v;

        $this->merge(array_map($trim, $this->only([
            'organizationName', 'organizationEmail', 'organizationMobile',
            'legalName', 'industry', 'address', 'city', 'state', 'country',
        ])));
    }
}
