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

        if (
            key_exists('orderBy', $params) &&
            (
                $this->canFilterColumn($model, $params['orderBy'], $filterableColumns) ||
                (key_exists($params['orderBy'], $filterablePivotColumns) &&
                    $this->isRelationshipExists($model, $filterablePivotColumns[$params['orderBy']]))
            )
        ) {
            $direction = $params['order'] ?? 'asc';
            $builder->orderBy($params['orderBy'], in_array($direction, ['asc', 'desc']) ? $direction : 'asc');
        }

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
                if (is_array($value)) {
                    $this->arrayParamsFilter($builder, $key, $value, $filterablePivotColumns[$key]);
                } else {
                    $this->pivotColumnFilter($builder, $key, $value, $filterablePivotColumns[$key]);
                }
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
        foreach (explode(',', $relations) as $relation) {
            $nested = explode('.', $relation);
            $relation = $nested[0];
            $subrelations = array_slice($nested, 1);
            $this->processRelations($query, $relation, $subrelations);
        }
    }

    private function processRelations($query, $relation, ?array $subrelations = [])
    {
        $model = $query->getModel();

        if ($this->isRelationshipExists($model, $relation)) {
            $requestedFields = $this->queryParams['select'][$relation] ?? null;
            $requestedFields = $requestedFields ? explode(',', $requestedFields) : $requestedFields;

            $query = $query->with([$relation => function ($query) use ($requestedFields, $subrelations) {
                $model = $query->getModel();
                $publicFields = $model::$publicFields ?? null;

                if ($requestedFields && $publicFields) $fields = array_intersect($publicFields, $requestedFields);

                $query->select($fields ?? $publicFields ?? $requestedFields ?? '*');

                if (count($subrelations)) {
                    $this->processRelations($query, $subrelations[0], array_slice($subrelations, 1));
                }
            }]);
        }
    }

    private function withCountRelations($query, $value)
    {
        $model = $query->getModel();
        if (is_string($value)) {
            $relations = explode(',', $value);
            foreach ($relations as $relation) {
                if ($this->isRelationshipExists($model, $relation)) {
                    $query->withCount($relation);
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $relation => $filters) {
                if ($this->isRelationshipExists($model, $relation)) {
                    $query->withCount([$relation => function ($query) use ($filters) {
                        $this->rawParams($filters)->filter($query);
                    }]);
                }
            }
        }
    }

    private function hasRelations($query, $value)
    {
        $model = $query->getModel();
        if (is_string($value)) {
            $relations = explode(',', $value);
            foreach ($relations as $relation) {
                if ($this->isRelationshipExists($model, $relation)) {
                    $query->has($relation);
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $relation => $filters) {
                if ($this->isRelationshipExists($model, $relation)) {
                    $query->whereHas($relation, function ($query) use ($filters) {
                        $this->rawParams($filters)->filter($query);
                    });
                }
            }
        }
    }

    private function arrayParamsFilter(Builder $builder, string $key, array $value, ?string $relation = null): void
    {
        $table = $this->getTableName($builder);
        if ($relation) {
            $tablePrefix = '';
        } else {
            $tablePrefix = "$table.";
        }

        if (isset($value['from'], $value['to'])) {
            $builder->whereBetween($tablePrefix . $key, [$value['from'], $value['to']]);
        } elseif (isset($value['from'])) {
            $builder->where($tablePrefix . $key, '>=', $value['from']);
        } elseif (isset($value['to'])) {
            $builder->where($tablePrefix . $key, '<=', $value['to']);
        } elseif (isset($value['orderBy']) && in_array($value['orderBy'], ['asc', 'desc'])) {
            if ($value['orderBy'] == 'desc') $builder->orderBy($tablePrefix . $key, $value['orderBy']);
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
            if ($arr1 = array_filter($filters, function ($v, $k) {
                return strpos($k, '.') === false;
            }, 1)) {
                $this->tempParams($arr1)->filter($query);
            }

            // filter nested relationship
            $arr2 = array_filter($filters, function ($v, $k) {
                return strpos($k, '.') !== false;
            }, 1);
            $filters2 = [];
            foreach ($arr2 as $k => $v) {
                $parts = explode('.', $k);
                $filters2[$parts[0]][$parts[1]] = $v;
            }

            foreach ($filters2 as $r => $f) {
                if ($this->isRelationshipExists($query->getModel(), $r)) {
                    $this->relationshipFilter($query, $r, $f);
                }
            }
        });
    }

    private function pivotColumnFilter(Builder $builder, string $key, string $value, string $relation)
    {
        $pivotTable = $this->getPivotTableName($builder->getModel(), $relation);
        $prefix = $pivotTable ? $pivotTable . '.' : '';
        $builder->whereHas($relation, function ($query) use ($key, $value, $prefix) {
            if (is_numeric($value)) {
                $query->where($prefix . $key, $value);
            } else {
                $query->where($prefix . $key, 'like', "%$value%");
            }
        });
    }

    private function getPivotTableName(Model $model, string $relation): string
    {
        return $model->{$relation}()->getTable();
    }

    private function getTableName($object): string
    {
        if ($object instanceof Builder) return $object->getModel()->getTable();
        elseif ($object instanceof Model) return $object->getTable();
    }
}