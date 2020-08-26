<?php

namespace MarksIhor\LaravelFiltering;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

trait Filterable
{
    private $rawParams = [];
    private $filterable = null;
    private $filterablePivot = null;
    private $filterableRelations = null;
    private $prefix = '';
    private $queryParams = [];

    public function filterable(array $columns)
    {
        $this->filterable = $columns;

        return $this;
    }

    public function filterablePivot(array $columns)
    {
        $this->filterablePivot = $columns;

        return $this;
    }

    public function filterableRelations(array $relations)
    {
        $this->filterableRelations = $relations;

        return $this;
    }

    public function filter($object)
    {
        $builder = $object instanceof Builder ? $object : $object->getQuery();
        $model = $builder->getModel();
        $filterableColumns = $this->filterable ?? $model::$filterable ?? null;
        $filterablePivotColumns = $this->filterablePivot ?? $model::$filterablePivot ?? [];
        $filterableRelations = $this->filterableRelations ?? $model::$filterableRelations ?? [];
        $this->queryParams = $queryParams = array_merge(request()->all(), $this->rawParams);

        foreach ($queryParams as $key => $value) {
            if (strpos($key, '->') !== false || strpos($key, '__') !== false) {
                // to filter json columns
                $delimiter = strpos($key, '->') !== false ? '->' : '__';
                $column = strtok($key, $delimiter);
                if ($this->canFilterColumn($model, $column, $filterableColumns)) {
                    if (is_string($value)) {
                        $this->keyValueFilter($builder, str_replace($delimiter, '->', $key), $value);
                    }
                }
            } elseif ($key === 'with') {
                // to load specified related models
                $this->withRelations($builder, $value);
            } elseif ($this->canFilterColumn($model, $key, $filterableColumns)) {
                // key value filter
                if (is_array($value)) {
                    $this->arrayParamsFilter($builder, $key, $value);
                } else {
                    $this->keyValueFilter($builder, $key, $value);
                }
            } elseif (
                !$this->isColumnExist($model, $key) &&
                $this->isRelationshipExists($model, $key) &&
                in_array($key, $filterableRelations)
            ) {
                $this->relationshipFilter($builder, $key, $value);
            } elseif (
                !$this->isColumnExist($model, $key) &&
                key_exists($key, $filterablePivotColumns) &&
                $this->isRelationshipExists($model, $filterablePivotColumns[$key])
            ) {
                $this->pivotColumnFilter($builder, $key, $value, $filterablePivotColumns[$key]);
            }
        }

        return $object;
    }

    private function canFilterColumn($model, string $column, $filterableColumns): bool
    {
        return $this->isColumnExist($model, $column) &&
            (
                !$filterableColumns ||
                key_exists($column, $this->rawParams) ||
                in_array($column, $filterableColumns)
            );
    }

    public function rawParams(array $params)
    {
        $this->rawParams = $params;

        return $this;
    }

    private function isColumnExist(Model $model, string $column): bool
    {
        return Schema::hasColumn($model->getTable(), $column);
    }

    private function isRelationshipExists(Model $model, string $relation): bool
    {
        return (!isset($model::$publicRelations) ||
                in_array($relation, $model::$publicRelations)) &&
            method_exists($model, $relation);
    }

    private function withRelations($query, $relations)
    {
        $model = $query->getModel();
        foreach (explode(',', $relations) as $relation) {
            if ($this->isRelationshipExists($model, $relation)) {
                $requestedFields = $this->queryParams['fields'][$relation] ?? null;
                $requestedFields = $requestedFields ? explode(',', $requestedFields) : $requestedFields;

                $query = $query->with([$relation => function ($query) use ($requestedFields) {
                    $model = $query->getModel();
                    $publicFields = $model::$publicFields ?? null;

                    if ($requestedFields && $publicFields) $fields = array_intersect($publicFields, $requestedFields);

                    $query->select($fields ?? $publicFields ?? $requestedFields ?? '*');
                }]);
            }
        }
    }

    private function arrayParamsFilter(Builder $builder, string $key, array $value): void
    {
        $table = $this->getTableName($builder);

        if (isset($value['from'], $value['to'])) {
            $builder->whereBetween($table . '.' . $key, [$value['from'], $value['to']]);
        } elseif (isset($value['from'])) {
            $builder->where($table . '.' . $key, '>=', $value['from']);
        } elseif (isset($value['to'])) {
            $builder->where($table . '.' . $key, '<=', $value['to']);
        } elseif (isset($value['orderBy']) && in_array($value['orderBy'], ['asc', 'desc'])) {
            if ($value['orderBy'] == 'desc') $builder->orderBy($table . '.' . $key, $value['orderBy']);
            else $builder->orderBy($key);
        }
    }

    private function keyValueFilter(Builder $builder, string $key, string $value): void
    {
        $table = $this->getTableName($builder);

        if (in_array($value, ['null', 'today', 'past', 'future'])) {
            if ($value === 'null') $builder->where($table . '.' . $key, null);
            elseif ($value === 'today') $builder->whereDate($table . '.' . $key, date('Y-m-d'));
            elseif ($value === 'future') $this->arrayParamsFilter($builder, $key, ['from' => date('Y-m-d')]);
            elseif ($value === 'past') $this->arrayParamsFilter($builder, $key, ['to' => date('Y-m-d')]);
        } else {
            $values = explode(',', $value);

            if (count($values) === 1) {
                if (is_numeric($value)) {
                    $builder->where($table . '.' . $key, $value);
                } else {
                    $builder->where($table . '.' . $key, 'like', "%$value%");
                }
            } else {
                $builder->whereIn($table . '.' . $key, $values);
            }
        }
    }

    private function relationshipFilter(Builder $builder, string $relationship, array $filters)
    {
        $builder->whereHas($relationship, function ($query) use ($filters) {
            $this->rawParams($filters)->filter($query);
        });
    }

    private function pivotColumnFilter(Builder $builder, string $key, string $value, string $relation)
    {
        $builder->whereHas($relation, function ($query) use ($key, $value) {
            if (is_numeric($value)) {
                $query->where($key, $value);
            } else {
                $query->where($key, 'like', "%$value%");
            }
        });
    }

    private function getTableName($object): string
    {
        if ($object instanceof Builder) return $object->getModel()->getTable();
        elseif ($object instanceof Model) return $object->getTable();
    }
}