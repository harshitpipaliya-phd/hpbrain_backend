<?php

declare(strict_types=1);

namespace App\Domain\Context;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Domain\Universal\SourceSchema;
use App\Domain\Universal\UnsupportedEntityException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the user is currently looking at — resolved, never inferred.
 *
 * WHAT THIS IS. Five reads against real tables, assembled into one payload:
 * the OBJECT on the screen, the USER looking at it, the ORGANIZATION it belongs
 * to, the SIGNALS whose recorded subject IS that object, and the SESSION the
 * request arrived in. Every value is a column from a row that exists.
 *
 * WHAT THIS IS NOT, AND MUST NEVER BECOME. It does not reason. It does not
 * recommend, predict, rank, score, summarise, detect a pattern, retrieve
 * organizational memory, call a model or execute anything. It is the layer that
 * hands Enterprise Brain a set of facts; Enterprise Brain remains the only part
 * of this system permitted to conclude anything from them. The test for whether
 * a change belongs here is simple: if the output would change when no row
 * changed, it does not belong here.
 *
 * READ-ONLY, STRUCTURALLY. Every statement below is a SELECT. There is no
 * insert, update or delete anywhere in this class, and it holds no writer.
 *
 * IT RESOLVES NOTHING ITSELF. Source tables, tenant columns and primary keys
 * all come from EntityResolver, which is the only thing in this application
 * that knows where a tenant keeps its entities; the readable column list comes
 * from SourceSchema. A tenant that does not map an entity gets an absent layer
 * naming that, never a borrowed table — which is the property EntityResolver
 * exists to guarantee and which duplicating any of its logic here would break.
 *
 * THE TENANT IS A PREDICATE ON EVERY READ, INCLUDING THE OBJECT LOOKUP. An
 * object is found by primary key AND tenant key together, so an id belonging to
 * another organization does not resolve — it answers `object_not_found`, the
 * same answer as an id that does not exist anywhere. That equivalence is
 * deliberate: a response that distinguished them would confirm which ids are
 * live in someone else's organization.
 *
 * EVERY ABSENT LAYER NAMES ITS REASON. The full vocabulary, per layer:
 *
 *   object        no_object_requested | object_id_unusable | unknown_screen
 *                 | screen_not_bound_to_entity | unknown_object_type
 *                 | entity_not_mapped_for_tenant | object_not_found
 *   user          user_not_identified | entity_not_mapped_for_tenant
 *                 | user_record_not_found
 *   organization  entity_not_mapped_for_tenant | organization_not_found
 *   signals       no_object_resolved | signal_store_unavailable
 *   session       no_session_context
 *
 * `signals` PRESENT WITH count 0 IS NOT AN ABSENT LAYER. It means the query
 * ran against the real table and this object has no signals — a fact about the
 * organization. Absent means the question could not be asked at all.
 */
final class ContextEngine
{
    /**
     * The most signals one context lookup will carry.
     *
     * Matches the cap PersonProfileService already applies to the same table.
     * A screen showing an object's current signals is not a signals report, and
     * an unbounded read here would let one object on one tenant decide the size
     * of every response.
     */
    private const SIGNAL_LIMIT = 50;

    /**
     * Identity fields read for each entity, and nothing else.
     *
     * NO CONTACT DETAILS, DELIBERATELY. The question this engine answers is
     * "what is on the screen", which needs a label and an id. Email, phone,
     * date of birth and gender are mapped for Person and Student and are
     * pointedly absent from both lists: they are not needed to say what the
     * user is looking at, and a context payload is the wrong place to widen who
     * can read them. PersonController and StudentController remain the surfaces
     * that serve a full record, each under its own route.
     *
     * Unmapped and physically-absent columns are dropped by SourceSchema, so a
     * tenant whose ERP lacks one of these simply does not report it.
     *
     * @var array<string, array<int, string>>
     */
    private const IDENTITY_FIELDS = [
        'Person'              => ['externalRef', 'firstName', 'lastName', 'unit', 'position', 'status'],
        'Student'             => ['externalRef', 'firstName', 'middleName', 'lastName', 'batch', 'status'],
        'OrganizationUnit'    => ['name', 'description', 'parent', 'status'],
        'Organization'        => ['name', 'code', 'industry'],
        'Position'            => ['title', 'status'],
        'PersonProfile'       => ['name', 'status'],
        'OrganizationProfile' => ['legalName'],
    ];

    /**
     * Signal subject types that mean one universal entity.
     *
     * WHY THIS IS NOT JUST THE UNIVERSAL NAME. SignalSubject writes universal
     * names into related_entity_type, and that is the contract. The database
     * does not match it: of the thirty subject-bearing signals in the live
     * installation, twenty-six say `Department` rather than `OrganizationUnit`,
     * written by a seeder rather than by the detector. Matching the universal
     * name alone would report every one of those departments as having no
     * signals, which is false. Matching on related_entity_id ALONE would be
     * worse — ids are primary keys of different tables, so department 2050's
     * signals would attach to person 2050.
     *
     * So the alias is DECLARED, narrowly, and each signal still reports the
     * type actually stored on its row. `Department` is listed because the rows
     * carrying it point at hrms_departments primary keys, which is exactly what
     * OrganizationUnit resolves to — not because the two names are assumed to
     * be related.
     *
     * @var array<string, array<int, string>>
     */
    private const SUBJECT_TYPE_ALIASES = [
        'OrganizationUnit' => ['OrganizationUnit', 'Department'],
    ];

    /**
     * Signal columns the context layer reads.
     *
     * `metadata` is included because it holds the signal's title and
     * description — without it a signal is a severity with no statement, which
     * the Assistant cannot use and must not paraphrase from a rule key. Every
     * other column on the table (dedupe_key, org_id, created_by) is left out.
     *
     * @var array<int, string>
     */
    private const SIGNAL_COLUMNS = [
        'id', 'source', 'classification', 'rule_key', 'priority', 'severity',
        'confidence', 'status', 'related_entity_type', 'related_entity_id',
        'department_id', 'metadata', 'created_date', 'updated_date',
    ];

    /** related_entity_id is VARCHAR(36); a longer id cannot be in the column. */
    private const ID_LIMIT = 36;

    /** @var array<string, bool> table => exists, for this request only */
    private array $tables = [];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly SourceSchema $schema,
        private readonly ScreenRegistry $screens,
    ) {
    }

    public function resolve(ContextQuery $query): ResolvedContext
    {
        $object = $this->resolveObject($query);

        return new ResolvedContext(
            tenantId: $query->tenantId,
            object: $object,
            user: $this->resolveUser($query),
            organization: $this->resolveOrganization($query),
            // Scoped to the object THAT RESOLVED, never to the id that was
            // asked for. An unresolvable id must not be used as a key into the
            // signal table: it would return signals under a subject this tenant
            // has no row for, and the Assistant would present them as being
            // about something that does not exist.
            signals: $this->resolveSignals($query, $object),
            session: $this->resolveSession($query),
        );
    }

    // ---- OBJECT ----------------------------------------------------------

    private function resolveObject(ContextQuery $query): ContextLayer
    {
        $blank = ['type' => null, 'id' => null, 'data' => null];

        $objectId = $query->objectId === null ? null : trim($query->objectId);

        if ($objectId === null || $objectId === '') {
            return ContextLayer::absent('no_object_requested', $blank);
        }

        // Longer than the column that records a signal's subject can hold, so
        // it cannot be an id this system has ever written down.
        if (mb_strlen($objectId) > self::ID_LIMIT) {
            return ContextLayer::absent('object_id_unusable', $blank);
        }

        $entity = $this->entityForRequest($query);

        if (! is_string($entity)) {
            // entityForRequest hands back the reason when it cannot name one.
            return ContextLayer::absent($entity['reason'], $blank);
        }

        try {
            $source = $this->resolver->resolve($query->tenantId, $entity);
        } catch (UnsupportedEntityException) {
            // The tenant has no mapping for this entity. NOT a fallback point:
            // guessing a table here is the exact failure EntityResolver's
            // fail-closed design prevents.
            return ContextLayer::absent(
                'entity_not_mapped_for_tenant',
                ['type' => $entity, 'id' => null, 'data' => null],
            );
        }

        $row = $this->readObject($source, $entity, $objectId, $query->tenantId);

        if ($row === null) {
            // Also the answer for another tenant's id — see the class docblock.
            return ContextLayer::absent(
                'object_not_found',
                ['type' => $entity, 'id' => $objectId, 'data' => null],
            );
        }

        return ContextLayer::present([
            'type' => $entity,
            'id'   => $objectId,
            'data' => $row,
        ]);
    }

    /**
     * Which universal entity the request is about.
     *
     * @return string|array{reason: string} the entity, or why there is none
     */
    private function entityForRequest(ContextQuery $query): string|array
    {
        // An explicit type wins over the screen's binding, because a caller
        // that states one is making a narrower claim than the screen name. It
        // is still checked against the Brain's vocabulary — an unrecognised
        // entity name is refused rather than passed to the resolver, so the
        // error names the parameter rather than the organization.
        if ($query->objectType !== null && $query->objectType !== '') {
            return in_array($query->objectType, EntityResolver::ENTITIES, true)
                ? $query->objectType
                : ['reason' => 'unknown_object_type'];
        }

        $screen = $query->screen === null ? '' : trim($query->screen);

        if ($screen === '' || ! $this->screens->knows($screen)) {
            return ['reason' => 'unknown_screen'];
        }

        $entity = $this->screens->entityFor($screen);

        // A known screen with no entity is a tenant-wide screen. It has no
        // object, and saying so is the answer rather than a gap.
        return $entity ?? ['reason' => 'screen_not_bound_to_entity'];
    }

    /**
     * One row, by primary key and tenant key together.
     *
     * Only the identity columns this entity declares are selected, narrowed by
     * SourceSchema to the ones the physical table has. Nothing loads a model or
     * a full row: the widest read here is seven columns.
     *
     * @return array<string, mixed>|null
     */
    private function readObject(ResolvedSource $source, string $entity, string $objectId, string $tenantId): ?array
    {
        $fields = $this->schema->usable($source, self::IDENTITY_FIELDS[$entity] ?? []);

        $select = [];

        foreach ($fields as $universalField => $column) {
            $select[] = $column.' as '.$universalField;
        }

        // An entity whose identity fields are all unmapped still resolves as
        // present — the row exists, and its id is a fact. Selecting the primary
        // key keeps the statement legal in that case.
        $select[] = $source->primaryKey.' as id';

        $builder = DB::table($source->table)
            ->where($source->primaryKey, $objectId)
            ->where($source->tenantKey, $tenantId);

        $this->excludeDeleted($builder, $source);

        $row = $builder->select($select)->first();

        return $row === null ? null : $this->stringifyIds((array) $row);
    }

    // ---- USER ------------------------------------------------------------

    private function resolveUser(ContextQuery $query): ContextLayer
    {
        if ($query->userId === '') {
            return ContextLayer::absent(
                'user_not_identified',
                ['id' => null, 'role' => null, 'data' => null],
            );
        }

        // The role is a claim on the verified token, so it is reported even
        // when the person's ERP row cannot be read: it is what authorization
        // actually used for this request, and reporting the row's absence while
        // hiding the role would misdescribe the request. It is passed through
        // VERBATIM and never normalised — `member`, which resolveRole() can
        // emit and the Role enum does not contain, must stay visible rather
        // than be rounded to something that looks valid.
        $role = $query->role === null || $query->role === '' ? null : $query->role;

        try {
            $source = $this->resolver->resolve($query->tenantId, 'Person');
        } catch (UnsupportedEntityException) {
            return ContextLayer::absent('entity_not_mapped_for_tenant', [
                'id' => $query->userId, 'role' => $role, 'data' => null,
            ]);
        }

        $row = $this->readObject($source, 'Person', $query->userId, $query->tenantId);

        if ($row === null) {
            // A valid token whose person row is gone — deleted, or belonging to
            // another tenant. The identity is real, the record is not, and both
            // halves of that are said out loud.
            return ContextLayer::absent('user_record_not_found', [
                'id' => $query->userId, 'role' => $role, 'data' => null,
            ]);
        }

        return ContextLayer::present([
            'id'   => $query->userId,
            'role' => $role,
            'data' => $row,
        ]);
    }

    // ---- ORGANIZATION ----------------------------------------------------

    private function resolveOrganization(ContextQuery $query): ContextLayer
    {
        $blank = ['id' => null, 'name' => null, 'data' => null];

        try {
            $source = $this->resolver->resolve($query->tenantId, 'Organization');
        } catch (UnsupportedEntityException) {
            return ContextLayer::absent('entity_not_mapped_for_tenant', $blank);
        }

        $fields = $this->schema->usable($source, self::IDENTITY_FIELDS['Organization']);

        $select = [$source->primaryKey.' as id'];

        foreach ($fields as $universalField => $column) {
            $select[] = $column.' as '.$universalField;
        }

        /*
          ARCHIVED ORGANIZATIONS ARE STILL REPORTED, and deliberately. Archiving
          governs whether an organization is LISTED (OrganizationRepository), not
          what it is called. The alternative is what AuthController's docblock
          records happening once already: the row was filtered out, and the
          response fell through to a manufactured name.

          THERE IS NO MANUFACTURED NAME HERE. Where AuthController answers
          "Organization 8" for a tenant it cannot read — defended there, for the
          login envelope — this layer answers present:false. A placeholder is
          indistinguishable from a real name once it reaches a model, and this
          payload exists to be handed to one.
        */
        $row = DB::table($source->table)
            ->where($source->tenantKey, $query->tenantId)
            ->select($select)
            ->first();

        if ($row === null) {
            return ContextLayer::absent('organization_not_found', $blank);
        }

        $data = $this->stringifyIds((array) $row);

        return ContextLayer::present([
            'id'   => (string) $data['id'],
            'name' => isset($data['name']) && $data['name'] !== null ? (string) $data['name'] : null,
            'data' => $data,
        ]);
    }

    // ---- SIGNALS ---------------------------------------------------------

    private function resolveSignals(ContextQuery $query, ContextLayer $object): ContextLayer
    {
        $blank = ['count' => 0, 'items' => []];

        if (! $object->present) {
            return ContextLayer::absent('no_object_resolved', $blank);
        }

        if (! $this->hasTable('hpbrain_signals')) {
            // The Brain's own schema is not on this connection. Distinct from
            // "no signals": one is an empty organization, the other an
            // incomplete installation, and they must not read the same.
            return ContextLayer::absent('signal_store_unavailable', $blank);
        }

        $entity = (string) $object->data['type'];
        $objectId = (string) $object->data['id'];

        $rows = DB::table('hpbrain_signals')
            ->where('tenant_id', $query->tenantId)
            ->where('related_entity_id', $objectId)
            ->whereIn('related_entity_type', self::SUBJECT_TYPE_ALIASES[$entity] ?? [$entity])
            ->orderByDesc('created_date')
            ->limit(self::SIGNAL_LIMIT)
            ->select(self::SIGNAL_COLUMNS)
            ->get();

        $items = $rows->map(function (object $row): array {
            $signal = (array) $row;

            // Decoded exactly as BaseRepository::hydrate() does it, for the
            // same reason: PDO hands a JSON column back as a string, and a
            // consumer treating it as structured gets six characters instead of
            // an object. Text that does not parse is left as it came rather
            // than nulled — that would destroy the only copy of whatever it is.
            if (isset($signal['metadata']) && is_string($signal['metadata'])) {
                $decoded = json_decode($signal['metadata'], true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $signal['metadata'] = $decoded;
                }
            }

            return $signal;
        })->all();

        // PRESENT WITH count 0 WHEN THERE ARE NONE. The question was asked of
        // the real table and the answer is zero, which is a fact about this
        // object rather than a failure to look.
        return ContextLayer::present([
            'count' => count($items),
            'items' => $items,
        ]);
    }

    // ---- SESSION ---------------------------------------------------------

    private function resolveSession(ContextQuery $query): ContextLayer
    {
        $screen = $query->screen === null ? null : trim($query->screen);
        $screen = $screen === '' ? null : $screen;

        if ($screen === null && $query->sessionId === null) {
            return ContextLayer::absent('no_session_context', [
                'screen' => null, 'screenKnown' => false, 'id' => null, 'expiresAt' => null,
            ]);
        }

        /*
          NOTHING SECRET LEAVES HERE. `id` is the access token's jti — a random
          per-issue identifier the caller is already holding inside the token it
          authenticated with, and which this application uses for revocation
          only on REFRESH tokens (AuthController::refresh). The token itself, the
          Authorization header, cookies and the signing secret are not read by
          this class at all.

          `screenKnown` is reported rather than the screen being silently
          accepted. A screen name nothing has declared is echoed back marked
          false, so a caller sending a typo learns that the object layer had
          nothing to resolve against instead of quietly receiving no object.
        */
        return ContextLayer::present([
            'screen'      => $screen,
            'screenKnown' => $screen !== null && $this->screens->knows($screen),
            'id'          => $query->sessionId,
            'expiresAt'   => $query->expiresAt === null
                ? null
                : gmdate('Y-m-d H:i:s', $query->expiresAt),
        ]);
    }

    // ---- shared ----------------------------------------------------------

    /**
     * Soft-deleted source rows stay out, where the tenant maps a column for it.
     *
     * Mirrors AuthController::activeSourceRows(). An entity with no mapped
     * deletedAt is one whose source system does not record deletion, and no
     * predicate is invented for it.
     */
    private function excludeDeleted(Builder $builder, ResolvedSource $source): void
    {
        if ($this->schema->has($source, 'deletedAt')) {
            $builder->whereNull($source->field('deletedAt'));
        }
    }

    /**
     * Source primary keys are integers in the ERP and strings everywhere in the
     * Brain — hpbrain_signals.related_entity_id is VARCHAR(36). Normalising the
     * id-shaped columns here means a consumer comparing an object id against a
     * signal's subject is comparing like with like.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function stringifyIds(array $row): array
    {
        foreach (['id', 'unit', 'position', 'parent'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                $row[$key] = (string) $row[$key];
            }
        }

        return $row;
    }

    /** Asked once per table per request; the resolver's own cache lifetime. */
    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
