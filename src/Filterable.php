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

    public function filterable(array $columns)
    {
        $this->filterable = $columns;

        return $this;
    }

    public function filter($object)
    {
        $builder = $object instanceof Builder ? $object : $object->getQuery();
        $model = $builder->getModel();
        $filterableColumns = $this->filterable ?? $model::$filterable ?? null;

        $queryParams = array_merge(request()->all(), $this->rawParams);

        foreach ($queryParams as $key => $value) {
            if ($key === 'with') {
                $this->withRelations($builder, $value);
            } elseif (
                $this->isColumnExist($model, $key) &&
                (
                    !$filterableColumns ||
                    key_exists($key, $this->rawParams) ||
                    in_array($key, $filterableColumns)
                )
            ) {
                if (is_array($value)) $this->arrayParamsFilter($builder, $key, $value);
                else $this->keyValueFilter($builder, $key, $value);
            }
        }

        return $object;
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

    private function withRelations($query, $relations)
    {
        $model = $query->getModel();
        foreach (explode(',', $relations) as $relation) {
            if (
                (!isset($model::$publicRelations) || in_array($relation, $model::$publicRelations)) &&
                method_exists($model, $relation)
            ) {
                $query = $query->with([$relation => function ($query) {
                    $model = $query->getModel();
                    $query->select($model::$publicFields ?? '*');
                }]);
            }
        }
    }

    private function arrayParamsFilter(Builder $builder, string $key, array $value): void
    {
        if (isset($value['from'], $value['to'])) {
            $builder->whereBetween($key, [$value['from'], $value['to']]);
        } elseif (isset($value['from'])) {
            $builder->where($key, '>=', $value['from']);
        } elseif (isset($value['to'])) {
            $builder->where($key, '<=', $value['to']);
        } elseif (isset($value['orderBy']) && in_array($value['orderBy'], ['asc', 'desc'])) {
            if ($value['orderBy'] == 'desc') $builder->orderBy($key, $value['orderBy']);
            else $builder->orderBy($key);
        }
    }

    private function keyValueFilter(Builder $builder, string $key, string $value): void
    {
        if (in_array($value, ['null', 'today', 'past', 'future'])) {
            if ($value === 'null') $builder->where($key, null);
            elseif ($value === 'today') $builder->whereDate($key, date('Y-m-d'));
            elseif ($value === 'future') $this->arrayParamsFilter($builder, $key, ['from' => date('Y-m-d')]);
            elseif ($value === 'past') $this->arrayParamsFilter($builder, $key, ['to' => date('Y-m-d')]);
        } else {
            $values = explode(',', $value);

            if (count($values) === 1) {
                if (is_numeric($value)) {
                    $builder->where($key, $value);
                } else {
                    $builder->where($key, 'like', "%$value%");
                }
            } else {
                $builder->whereIn($key, $values);
            }
        }
    }
}
