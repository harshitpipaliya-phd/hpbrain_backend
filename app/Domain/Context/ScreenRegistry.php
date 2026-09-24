<?php

declare(strict_types=1);

namespace App\Domain\Context;

use App\Domain\Universal\EntityResolver;

/**
 * Which universal entity a screen is about.
 *
 * WHY THIS EXISTS AT ALL. A context lookup arrives as `?screen=person-profile&
 * objectId=1608`, and `1608` on its own is not resolvable: ids in this system
 * are primary keys of per-tenant SOURCE TABLES, so 1608 is a person in tbluser
 * and 2050 is a department in hrms_departments, both for tenant 1000018. Asking
 * "which table holds row 1608" by probing tables until one answers would return
 * whichever table was tried first — a specific, checkable and quite possibly
 * false claim about what the user is looking at.
 *
 * So the binding is DECLARED. A screen either states which entity it is about
 * or it resolves nothing. This is configuration, not inference: adding a screen
 * is one line here, and a screen that is absent produces
 * `screen_not_bound_to_entity` rather than a guess.
 *
 * THE KEYS ARE THE SPA'S OWN VIEW NAMES where they exist (web/src/App.tsx
 * declares them as the `View` union), plus the `-profile` forms the object
 * screens are addressed by. Both spellings are listed deliberately: the shell
 * navigates to 'people' while the screen the user ends up looking at is one
 * person, and a caller may honestly name either.
 *
 * ONLY ENTITIES IN EntityResolver::ENTITIES MAY APPEAR. That is enforced in
 * entityFor() rather than trusted, because a typo here would otherwise reach
 * EntityResolver as an unmappable entity name and surface as a 404 about the
 * organization — which would send whoever read it to look at the tenant instead
 * of at this file.
 */
final class ScreenRegistry
{
    /**
     * screen identifier => universal entity the screen's object is.
     *
     * A screen listed with null IS bound — deliberately, to nothing. Those are
     * the tenant-wide screens (signals, analytics, the graph): they have a
     * tenant and a user but no single object, and saying so is different from
     * not knowing.
     *
     * @var array<string, string|null>
     */
    private const SCREENS = [
        // Object screens.
        'person-profile'       => 'Person',
        'people'               => 'Person',
        'student-profile'      => 'Student',
        'students'             => 'Student',
        'department-profile'   => 'OrganizationUnit',
        'departments'          => 'OrganizationUnit',
        'organization-profile' => 'Organization',
        'organization'         => 'Organization',
        'position'             => 'Position',

        // Tenant-wide screens: no object, and that is the answer rather than a
        // gap. Listed so the difference between "this screen has no object" and
        // "nobody has told us what this screen is" stays visible.
        'home'                 => null,
        'details'              => null,
        'signals'              => null,
        'evidence'             => null,
        'analytics'            => null,
        'executive'            => null,
        'graph'                => null,
        'workspace'            => null,
        'aiassistant'          => null,
        'aiworkspace'          => null,
        'search'               => null,
        'tasks'                => null,
        'settings'             => null,
        'ingestion'            => null,
    ];

    /** Whether this screen identifier is one the application has declared. */
    public function knows(string $screen): bool
    {
        return array_key_exists($screen, self::SCREENS);
    }

    /**
     * The universal entity a screen's object is, or null.
     *
     * Null means one of two different things, and the caller must be able to
     * tell them apart — use knows() for that. Null from a KNOWN screen is
     * "this screen has no single object"; null from an unknown screen is
     * "nothing has declared what this screen shows".
     */
    public function entityFor(string $screen): ?string
    {
        $entity = self::SCREENS[$screen] ?? null;

        if ($entity === null) {
            return null;
        }

        // A binding naming an entity the Brain has no vocabulary for is a
        // defect in this file. Refusing it here keeps it from arriving at
        // EntityResolver disguised as a missing tenant mapping.
        return in_array($entity, EntityResolver::ENTITIES, true) ? $entity : null;
    }

    /** @return array<int, string> sorted, so error payloads are stable */
    public function screens(): array
    {
        $names = array_keys(self::SCREENS);
        sort($names);

        return $names;
    }
}
