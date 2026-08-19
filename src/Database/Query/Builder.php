<?php

namespace Awobaz\Compoships\Database\Query;

use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Builder extends BaseQueryBuilder
{
    /**
     * Add a "where in" clause to the query.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|string|string[] $column
     * @param mixed                                                           $values
     * @param string                                                          $boolean
     * @param bool                                                            $not
     *
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        // Here we implement custom support for multi-column 'IN'
        if (is_array($column)) {
            $inOperator = $not ? 'NOT IN' : 'IN';
            $connection = $this->getConnection();
            $prefix = $connection->getTablePrefix();
            $grammar = $connection->getQueryGrammar();

            foreach ($column as &$value) {
                if (!$grammar->isExpression($value) && !Str::contains($value, '.')) {
                    $value = $prefix.$value;
                }
            }

            if ($connection->getDriverName() === 'sqlsrv') {
                foreach ($column as $column_number => $column_name) {
                    $column_values = array_unique(Arr::pluck($values, $column_number));
                    $values_placeholders = implode(', ', array_fill(0, count($column_values), '?'));

                    $this->whereRaw("{$column_name} {$inOperator} ({$values_placeholders})", Arr::flatten($column_values), $boolean);
                }

                return $this;
            } elseif (
                !in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb', 'pgsql']) ||
                    Arr::some($values, fn ($value) => in_array(null, $value, true))
            ) {
                // use a series of OR/AND clauses when optimized row value expressions can't be used
                return $this->where(function ($query) use ($column, $values) {
                    foreach ($values as $value) {
                        $query->orWhere(function ($query) use ($column, $value) {
                            foreach ($column as $index => $aColumn) {
                                $query->where($aColumn, $value[$index]);
                            }
                        });
                    }
                });
            }

            $columns = implode(', ', array_map(
                fn ($v) => $grammar->isExpression($v) ? $v->getValue($grammar) : $grammar->wrap($v),
                $column
            ));
            $tuplePlaceholders = '('.implode(', ', array_fill(0, count($column), '?')).')';
            $placeholderList = implode(', ', array_fill(0, count($values), $tuplePlaceholders));

            $this->whereRaw("({$columns}) {$inOperator} ({$placeholderList})", Arr::flatten($values), $boolean);

            return $this;
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and')
    {
        // If the column and values are arrays, we will assume it is a multi-columns relationship
        // and we adjust the 'where' clauses accordingly
        if (is_array($first) && is_array($second)) {
            $type = 'Column';

            foreach ($first as $index => $f) {
                $this->wheres[] = [
                    'type'     => $type,
                    'first'    => $f,
                    'operator' => $operator,
                    'second'   => $second[$index],
                    'boolean'  => $boolean,

                ];
            }

            return $this;
        }

        return parent::whereColumn($first, $operator, $second, $boolean);
    }
}
