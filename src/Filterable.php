<?php

namespace MarksIhor\LaravelFiltering;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait Filterable
{
    private $rawParams = [];
    private $filterable = null;
    private $filterablePivot = null;
    private $filterableRelations = null;
    private $prefix = '';
    private $queryParams = [];
    protected $tempParams = null;

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

        $params = $this->tempParams ?: $queryParams;
        foreach ($params as $key => $value) {
            if (strpos($key, '->') !== false || strpos($key, '__') !== false) {
                // to filter json columns
                $delimiter = strpos($key, '->') !== false ? '->' : '__';
                $column = strtok($key, $delimiter);
                if ($this->canFilterColumn($model, $column, $filterableColumns)) {
                    if (is_array($value)) {
                        $this->arrayParamsFilter($builder, str_replace($delimiter, '->', $key), $value);
                    } else {
                        $this->keyValueFilter($builder, str_replace($delimiter, '->', $key), $value);
                    }
                }
            } elseif ($key === 'deleted' && $value == 1) {
                $builder->onlyTrashed();
            } elseif ($key === 'with') {
                // to load specified related models
                $this->withRelations($builder, $value);
            } elseif ($key === 'withCount') {
                // to count specified related models
                $this->withCountRelations($builder, $value);
            } elseif ($key === 'has') {
                // where specified relations > 0
                $this->hasRelations($builder, $value);
            } elseif ($key === 'select' && isset($value[$model->getTable()])) {
                // to select specific fields
                $selectColumns = array_filter(explode(',', $value[$model->getTable()]), function ($item) use ($model) {
                    return $this->isColumnExist($model, $item);
                });
                $builder->select($selectColumns);
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
                if ($value) {
                    $this->relationshipFilter($builder, $key, $value);
                }
            } elseif (
                !$this->isColumnExist($model, $key) &&
                key_exists($key, $filterablePivotColumns) &&
                $this->isRelationshipExists($model, $filterablePivotColumns[$key])
            ) {
                $this->pivotColumnFilter($builder, $key, $value, $filterablePivotColumns[$key]);
            }
        }
        $this->tempParams = null;

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

    public function tempParams(array $params)
    {
        $this->tempParams = $params;

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
                $requestedFields = $this->queryParams['select'][$relation] ?? null;
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

    private function withCountRelations($query, $relations)
    {
        $model = $query->getModel();
        foreach (explode(',', $relations) as $relation) {
            if ($this->isRelationshipExists($model, $relation)) {
                $query->withCount($relation);
            }
        }
    }

    private function hasRelations($query, $relations)
    {
        $model = $query->getModel();
        foreach (explode(',', $relations) as $relation) {
            if ($this->isRelationshipExists($model, $relation)) {
                $query->has($relation);
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

    private function keyValueFilter(Builder $builder, string $key, ?string $value): void
    {
        $table = $this->getTableName($builder);

        if ($value === null) {
            $builder->whereNull($table . '.' . $key);
        } elseif (in_array($value, ['null', 'today', 'past', 'future', 'notNull'])) {
            if ($value === 'null') $builder->whereNull($table . '.' . $key);
            elseif ($value === 'notNull') $builder->whereNotNull($table . '.' . $key);
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
            $this->tempParams($filters)->filter($query);
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