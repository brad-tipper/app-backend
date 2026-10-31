<?php

namespace BradTipper\AppBackend\Sync;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\Api\Input;
use BradTipper\AppBackend\Api\Uuid;
use BradTipper\AppBackend\Auth\ApiMemberExtension;
use BradTipper\AppBackend\OpenApi\Spec;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * REST resource for an offline-first entity:
 *
 *   GET    /v1/{resource}?updated_since=&include_deleted=   delta fetch
 *   GET    /v1/{resource}/{id}
 *   PUT    /v1/{resource}/{id}                            idempotent create-or-update
 *   DELETE /v1/{resource}/{id}[?permanent=true]           soft (default) or hard delete
 *
 * Ids are client-generated UUIDs, so a write queued offline can be replayed
 * safely. Concrete controllers supply the model mapping.
 */
abstract class SyncController extends ApiController
{
    /** @return class-string<DataObject> */
    abstract protected static function modelClass(): string;

    /** Plural path segment, e.g. `lists`. */
    abstract protected static function resourcePath(): string;

    /** Schema name of one item, e.g. `List`. */
    abstract protected static function itemName(): string;

    /** @return array<string, array<string, mixed>> JSON schema properties of the read model (without the shared sync fields) */
    abstract protected static function itemProperties(): array;

    /** @return array<string, array<string, mixed>> properties accepted by PUT */
    abstract protected static function writeProperties(): array;

    /** @return string[] required keys of the PUT body */
    protected static function writeRequired(): array
    {
        return [];
    }

    /** Rows this member may see (own rows, plus shared ones). */
    abstract protected function visibleTo(Member $member): DataList;

    /** Whether this member may modify an existing row. */
    protected function canEdit(Member $member, DataObject $row): bool
    {
        return (int) $row->OwnerID === (int) $member->ID;
    }

    /** Whether this member may hard-delete / soft-delete a row (owner-only by default). */
    protected function canDelete(Member $member, DataObject $row): bool
    {
        return (int) $row->OwnerID === (int) $member->ID;
    }

    /**
     * Validates and returns the fields to write. Record errors on `$in`; the
     * caller asserts validity.
     *
     * @return array<string, mixed>
     */
    abstract protected function readFields(Input $in, Member $member, ?DataObject $existing): array;

    /** @return array<string, mixed> */
    abstract protected function toApi(DataObject $row): array;

    /** Owner of a new row; defaults to the signed-in member. @param array<string, mixed> $fields */
    protected function ownerIdFor(Member $member, array &$fields): int
    {
        return (int) $member->ID;
    }

    /** Plan limits, entitlement checks, etc. Throw an ApiException to refuse. */
    protected function beforeSave(Member $member, DataObject $row, bool $isNew, bool $restoring): void
    {
    }

    protected function afterSave(Member $member, DataObject $row, bool $isNew): void
    {
    }

    protected function dispatch(HTTPRequest $request): HTTPResponse
    {
        $member = $this->requireMember($request);
        $id = $request->param('ID');
        $method = strtoupper($request->httpMethod());

        if ($id === null || $id === '') {
            $this->requireMethod($request, 'GET');
            return $this->json($this->listRows($request, $member));
        }
        if (!Uuid::isValid($id)) {
            throw ApiException::notFound();
        }
        $id = strtolower($id);

        return match ($method) {
            'GET' => $this->json($this->toItem($this->findOr404($member, $id))),
            'PUT', 'PATCH' => $this->upsertRow($request, $member, $id),
            'DELETE' => $this->removeRow($request, $member, $id),
            default => throw ApiException::methodNotAllowed(),
        };
    }

    /** @return array<string, mixed> */
    private function listRows(HTTPRequest $request, Member $member): array
    {
        $list = $this->visibleTo($member);
        $since = $request->getVar('updated_since');
        if (is_string($since) && $since !== '') {
            $time = strtotime($since);
            if ($time === false) {
                throw ApiException::validation(['updated_since' => ['Must be an ISO 8601 timestamp.']]);
            }
            $list = $list->filter('LastEdited:GreaterThanOrEqual', gmdate('Y-m-d H:i:s', $time));
        }
        if (!in_array($request->getVar('include_deleted'), ['1', 'true', 1, true], true)) {
            $list = $list->filter('Deleted', false);
        }
        $serverTime = gmdate(DATE_ATOM);
        $items = [];
        foreach ($list->sort('LastEdited', 'ASC') as $row) {
            $items[] = $this->toItem($row);
        }
        return ['items' => $items, 'server_time' => $serverTime];
    }

    private function upsertRow(HTTPRequest $request, Member $member, string $id): HTTPResponse
    {
        $class = static::modelClass();
        $row = $this->visibleTo($member)->filter('UUID', $id)->first();
        if (!$row && $class::get()->filter('UUID', $id)->exists()) {
            // The id belongs to someone else; do not reveal that.
            throw ApiException::notFound();
        }
        $isNew = $row === null;
        if ($isNew && strtoupper($request->httpMethod()) === 'PATCH') {
            throw ApiException::notFound();
        }
        if (!$isNew && !$this->canEdit($member, $row)) {
            throw ApiException::forbidden();
        }

        $in = Input::fromRequest($request);
        $fields = $this->readFields($in, $member, $row);
        $deleted = $in->bool('deleted');
        $in->assertValid();

        $restoring = !$isNew && $row->Deleted && $deleted === false;
        if ($isNew) {
            $row = $class::create();
            $row->UUID = $id;
            $row->OwnerID = $this->ownerIdFor($member, $fields);
        }
        $row->update($fields);
        if ($deleted !== null) {
            $row->Deleted = $deleted;
        }
        $this->beforeSave($member, $row, $isNew, $restoring);
        $row->write();
        $this->afterSave($member, $row, $isNew);

        return $this->json($this->toItem($row), $isNew ? 201 : 200);
    }

    private function removeRow(HTTPRequest $request, Member $member, string $id): HTTPResponse
    {
        $row = $this->findOr404($member, $id);
        if (!$this->canDelete($member, $row)) {
            throw ApiException::forbidden();
        }
        if (in_array($request->getVar('permanent'), ['1', 'true'], true)) {
            $row->delete();
        } else {
            $row->Deleted = true;
            $row->write();
        }
        return $this->noContent();
    }

    /**
     * Every row the member can see, deleted ones included (for the data export).
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Member $member): array
    {
        $rows = [];
        foreach ($this->visibleTo($member)->sort('Created', 'ASC') as $row) {
            $rows[] = $this->toItem($row);
        }
        return $rows;
    }

    private function findOr404(Member $member, string $id): DataObject
    {
        $row = $this->visibleTo($member)->filter('UUID', $id)->first();
        if (!$row) {
            throw ApiException::notFound();
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function toItem(DataObject $row): array
    {
        return [
            'id' => $row->UUID,
            'owner_id' => $row->Owner()->UUID,
            ...$this->toApi($row),
            'deleted' => (bool) $row->Deleted,
            'deleted_at' => ApiMemberExtension::iso($row->DeletedAt),
            'created_at' => ApiMemberExtension::iso($row->Created),
            'updated_at' => ApiMemberExtension::iso($row->LastEdited),
        ];
    }

    // ---- OpenAPI ------------------------------------------------------

    public static function describe(Spec $spec): void
    {
        $name = static::itemName();
        $path = '/v1/' . static::resourcePath();
        $tag = [ucfirst(static::resourcePath())];
        $idParam = ['id' => Spec::string('uuid')];

        $props = [
            'id' => Spec::string('uuid'),
            'owner_id' => Spec::string('uuid'),
            ...static::itemProperties(),
            'deleted' => Spec::boolean(),
            'deleted_at' => Spec::string('date-time', true),
            'created_at' => Spec::string('date-time'),
            'updated_at' => Spec::string('date-time'),
        ];
        // deleted_at is always sent but optional for clients building rows locally (not every entity is trashable).
        $spec->schema($name, Spec::object($props, array_values(array_diff(array_keys($props), ['deleted_at']))));
        $spec->schema("{$name}List", Spec::object([
            'items' => Spec::arrayOf(Spec::ref($name)),
            'server_time' => Spec::string('date-time'),
        ]));
        $spec->schema("{$name}Input", Spec::object(
            [...static::writeProperties(), 'deleted' => Spec::boolean()],
            static::writeRequired(),
        ));
        $spec->schema("{$name}Patch", Spec::object([...static::writeProperties(), 'deleted' => Spec::boolean()], []));

        $plural = ucfirst(static::resourcePath());
        $spec->operation('get', $path, "list{$plural}", [
            'tags' => $tag, 'summary' => "List {$name} rows (delta sync)", 'auth' => true,
            'query' => [
                'updated_since' => ['schema' => Spec::string('date-time')],
                'include_deleted' => ['schema' => Spec::boolean()],
            ],
            'responses' => [200 => Spec::ref("{$name}List")],
        ]);
        $spec->operation('get', "{$path}/{id}", "get{$name}", [
            'tags' => $tag, 'summary' => "Get one {$name}", 'auth' => true, 'path' => $idParam,
            'responses' => [200 => Spec::ref($name)],
        ]);
        $spec->operation('put', "{$path}/{id}", "put{$name}", [
            'tags' => $tag, 'summary' => "Create or update a {$name} (idempotent)", 'auth' => true, 'path' => $idParam,
            'body' => Spec::ref("{$name}Input"),
            'responses' => [200 => Spec::ref($name), 201 => Spec::ref($name)],
        ]);
        $spec->operation('patch', "{$path}/{id}", "patch{$name}", [
            'tags' => $tag, 'summary' => "Update fields of a {$name}", 'auth' => true, 'path' => $idParam,
            'body' => Spec::ref("{$name}Patch"),
            'responses' => [200 => Spec::ref($name)],
        ]);
        $spec->operation('delete', "{$path}/{id}", "delete{$name}", [
            'tags' => $tag, 'summary' => "Delete a {$name} (soft by default)", 'auth' => true, 'path' => $idParam,
            'query' => ['permanent' => ['schema' => Spec::boolean()]],
            'responses' => [204 => null],
        ]);
    }
}
