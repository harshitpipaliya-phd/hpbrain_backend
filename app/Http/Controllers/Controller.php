<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('tenantId');
    }

    protected function actorId(Request $request): string
    {
        return (string) $request->attributes->get('auth.userId');
    }
}
