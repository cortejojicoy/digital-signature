<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\PersonnelDirectory;
use Kukux\DigitalSignature\Hub\Personnel;
use LogicException;

/**
 * The default PersonnelDirectory: an Eloquent model the hub app fills from
 * the HR Kafka topics, mapped by config('signature.hub.personnel').
 *
 *   'model'   => App\Models\Personnel::class,
 *   'columns' => ['key' => 'uuid', 'emp_no' => 'employee_number', 'name' => 'full_name', …]
 *
 * Search is deliberately narrow (D12): active people only, a name fragment of
 * at least `min_search` characters or an exact employee number, never more
 * than `max_results` rows. The identification form is open to anyone who
 * paired a computer, so it must not be a way to list the university.
 */
class EloquentPersonnelDirectory implements PersonnelDirectory
{
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, (int) config('signature.hub.personnel.max_results', 10)));
        $longEnough = mb_strlen($query) >= (int) config('signature.hub.personnel.min_search', 3);
        $name = $this->column('name');
        $empNo = $this->column('emp_no');

        return $this->active()
            ->where(function (Builder $q) use ($query, $longEnough, $name, $empNo) {
                $q->where($empNo, $query);

                if ($longEnough) {
                    // LIKE wildcards in what the person typed are literal.
                    $q->orWhere($name, 'like', '%'.addcslashes($query, '%_\\').'%');
                }
            })
            ->orderBy($name)
            ->limit($limit)
            ->get()
            ->map(fn (Model $row) => $this->toPersonnel($row))
            ->all();
    }

    public function find(string $key): ?Personnel
    {
        $row = $this->query()->where($this->column('key'), $key)->first();

        return $row === null ? null : $this->toPersonnel($row);
    }

    /** By exact employee number, active or not (the `signature:hub-admin` command). */
    public function findByEmpNo(string $empNo): ?Personnel
    {
        $row = $this->query()->where($this->column('emp_no'), $empNo)->first();

        return $row === null ? null : $this->toPersonnel($row);
    }

    /** By exact email, active people only (the hub API's people?email= lookup). */
    public function findByEmail(string $email): ?Personnel
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        $row = $this->active()->where($this->column('email'), $email)->first();

        return $row === null ? null : $this->toPersonnel($row);
    }

    public function isActive(string $key): bool
    {
        return $this->find($key)?->active ?? false;
    }

    public function toClaims(Personnel $person): array
    {
        return [
            'sub'      => $person->key,
            'name'     => $person->name,
            'email'    => $person->email,
            'emp_no'   => $person->empNo,
            'unit'     => $person->unit,
            'position' => $person->position,
        ];
    }

    /**
     * The personnel model's query, for the admin Personnel page.
     *
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        $model = config('signature.hub.personnel.model');

        if (! is_string($model) || $model === '' || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw new LogicException(
                'Hub mode needs to know who works here. Set SIGNATURE_HUB_PERSONNEL_MODEL (signature.hub.personnel.model) '
                .'to your Kafka-fed personnel model and map its columns, or bind '
                .PersonnelDirectory::class.' yourself.'
            );
        }

        return $model::query();
    }

    /** The configured column for a field: key, emp_no, name, email, unit, position, active. */
    public function column(string $field): string
    {
        return (string) (config("signature.hub.personnel.columns.{$field}") ?: $field);
    }

    public function toPersonnel(Model $row): Personnel
    {
        $value = fn (string $field) => ($column = config("signature.hub.personnel.columns.{$field}")) ? $row->getAttribute($column) : null;
        $active = $value('active');

        return new Personnel(
            key: (string) $value('key'),
            name: (string) $value('name'),
            empNo: ($emp = $value('emp_no')) === null ? null : (string) $emp,
            email: $value('email'),
            unit: $value('unit'),
            position: $value('position'),
            // No `active` column mapped: everyone in the table is current.
            active: $active === null ? true : (bool) $active,
        );
    }

    /** @return Builder<Model> */
    protected function active(): Builder
    {
        $query = $this->query();

        if ($column = config('signature.hub.personnel.columns.active')) {
            $query->where($column, true);
        }

        return $query;
    }
}
