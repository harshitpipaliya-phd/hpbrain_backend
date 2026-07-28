<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class Controller
{
    protected function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('tenantId');
    }

    /**
     * The actor as the Brain knows it: a string id (UUID from
     * hpbrain_auth_users, or 'dev-user-1' under the dev-bypass token). Correct
     * for every hpbrain_* table, all of which type created_by as text.
     */
    protected function actorId(Request $request): string
    {
        return (string) $request->attributes->get('auth.userId');
    }

    /**
     * The actor as the ERP knows it: a numeric tbluser.id, or null.
     *
     * The ERP tables (institute_detail, org_details, hrms_departments, tbluser)
     * all type created_by as BIGINT, while Brain identities are strings. Writing
     * actorId() into them raised
     *
     *     SQLSTATE[22007]: Incorrect integer value: 'dev-user-1' for column created_by
     *
     * which made creating an organization, department or person fail for every
     * caller — not just under the dev token, since hpbrain_auth_users ids are
     * UUIDs too.
     *
     * Resolution order: a numeric id is used as-is; otherwise the Brain user's
     * email is matched against tbluser to find their real ERP row. If neither
     * resolves, the column is left NULL — it is nullable on all four tables, and
     * an honest NULL beats attributing the write to whichever employee happens
     * to hold id 1.
     */
    protected function actorErpId(Request $request): ?int
    {
        $actor = $request->attributes->get('auth.userId');

        if (is_numeric($actor)) {
            return (int) $actor;
        }

        $email = DB::table('hpbrain_auth_users')->where('id', $actor)->value('email');

        if (is_string($email) && $email !== '') {
            $id = DB::table('tbluser')->where('email', $email)->whereNull('deleted_at')->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
}
