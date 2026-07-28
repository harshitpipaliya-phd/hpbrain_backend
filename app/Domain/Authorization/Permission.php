<?php

declare(strict_types=1);

namespace App\Domain\Authorization;

enum Permission: string
{
    case READ             = 'read';
    case CREATE           = 'create';
    case UPDATE           = 'update';
    case DELETE           = 'delete';
    case EVIDENCE_CURATE  = 'evidence.curate';
    case DECISION_APPROVE = 'decision.approve';
    case ESO_EXECUTE      = 'eso.execute';
    case SETTINGS_MANAGE  = 'settings.manage';
    case APIKEY_MANAGE    = 'apikey.manage';
    case EVENTS_MANAGE    = 'events.manage';
    case TENANT_MANAGE    = 'tenant.manage';

    /** @return array<int, string> */
    public static function allValues(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
