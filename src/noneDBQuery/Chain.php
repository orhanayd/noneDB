<?php
// noneDBQuery: chainable methods
// Built into noneDB.php by tools/build.php: edit here, never noneDB.php
trait noneDBQuery_Chain {
    // ==========================================
    // CHAINABLE METHODS (return $this)
    // ==========================================

    /**
     * Add where filters. Each call adds a group; all groups must match (AND).
     * Values are compared with === or, for arrays like ['$gt' => 10], with operators.
     * @param array $filters
     * @return self
     */
    public function where(array $filters): self {
        if (!empty($filters)) {
            foreach ($filters as $value) {
                $this->isOperatorSpec($value); // validates early
            }
            $this->whereFilters[] = $filters;
        }
        return $this;
    }

    /**
     * Add pattern matching filter
     * @param string $field
     * @param string $pattern "^" anchors the start, a trailing "$" the end; everything else is literal
     * @return self
     */
    public function like(string $field, string $pattern): self {
        $this->likeFilters[] = ['field' => $field, 'pattern' => $pattern, 'like' => $this->likeSpec($pattern)];
        return $this;
    }

    /**
     * Add range filter (min <= value <= max)
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @return self
     */
    public function between(string $field, $min, $max): self {
        $this->betweenFilters[] = ['field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Add a sort key. The first call is the primary key; later calls break ties.
     * @param string $field
     * @param string $order 'asc' or 'desc'
     * @return self
     */
    public function sort(string $field, string $order = 'asc'): self {
        $this->sortKeys[] = ['field' => $field, 'order' => strtolower($order) === 'desc' ? 'desc' : 'asc'];
        return $this;
    }

    /**
     * Limit results
     * @param int $count
     * @return self
     */
    public function limit(int $count): self {
        $this->limitCount = max(0, $count);
        return $this;
    }

    /**
     * Skip first N results (for pagination)
     * @param int $count
     * @return self
     */
    public function offset(int $count): self {
        $this->offsetCount = max(0, $count);
        return $this;
    }

    /**
     * Alias for offset() - skip first N results
     * @param int $count
     * @return self
     */
    public function skip(int $count): self {
        return $this->offset($count);
    }

    /**
     * Alias for sort()
     * @param string $field
     * @param string $order 'asc' or 'desc'
     * @return self
     */
    public function orderBy(string $field, string $order = 'asc'): self {
        return $this->sort($field, $order);
    }

    /**
     * Add an OR group: a record matches if all where() groups match OR any orWhere() group does
     * @param array $filters
     * @return self
     */
    public function orWhere(array $filters): self {
        foreach ($filters as $value) {
            $this->isOperatorSpec($value);
        }
        $this->orWhereFilters[] = $filters;
        return $this;
    }

    /**
     * Filter where field value is in given array (strict comparison)
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereIn(string $field, array $values): self {
        $this->whereInFilters[] = ['field' => $field, 'values' => $this->membership(array_values($values)), 'raw' => array_values($values)];
        return $this;
    }

    /**
     * Value list for strict membership tests: longer lists become a hash set of exact identities
     * (noneDB::valueKey: same result as in_array(..., true), without scanning the list per record)
     * @return array|\stdClass
     */
    private function membership(array $values) {
        if (count($values) <= 8) {
            return $values;
        }
        $valueKey = $this->kernel()->values->valueKeyFunction();
        $set = [];
        foreach ($values as $value) {
            $set[$valueKey($value)] = true;
        }
        return (object)['set' => $set, 'key' => $valueKey];
    }

    private function isMember($value, $membership): bool {
        if ($membership instanceof \stdClass) {
            return isset($membership->set[($membership->key)($value)]);
        }
        return in_array($value, $membership, true);
    }

    /**
     * Filter where field value is NOT in given array (strict comparison)
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereNotIn(string $field, array $values): self {
        $this->whereNotInFilters[] = ['field' => $field, 'values' => $this->membership(array_values($values))];
        return $this;
    }

    /**
     * Exclude records where a field equals the value (strict comparison)
     * @param array $filters
     * @return self
     */
    public function whereNot(array $filters): self {
        if (!empty($filters)) {
            // Per field: the field matches when all its conditions match (AND, like in where());
            // a record is excluded when any field matches. Operators are checked now, like where().
            $fields = [];
            foreach ($filters as $field => $value) {
                $fields[] = $this->compileGroups([[$field => $value]])[0];
            }
            $this->whereNotFilters[] = $fields;
        }
        return $this;
    }

    /**
     * Filter where field does NOT match pattern
     * @param string $field
     * @param string $pattern
     * @return self
     */
    public function notLike(string $field, string $pattern): self {
        $this->notLikeFilters[] = ['field' => $field, 'pattern' => $pattern, 'like' => $this->likeSpec($pattern)];
        return $this;
    }

    /**
     * Filter where field value is NOT between min and max
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @return self
     */
    public function notBetween(string $field, $min, $max): self {
        $this->notBetweenFilters[] = ['field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Select only specific fields in results ("key" is always included)
     * @param array $fields
     * @return self
     */
    public function select(array $fields): self {
        $this->selectFields = array_values($fields);
        return $this;
    }

    /**
     * Exclude specific fields from results
     * @param array $fields
     * @return self
     */
    public function except(array $fields): self {
        $this->exceptFields = array_values($fields);
        return $this;
    }

    /**
     * Group results by field. get() then returns groups: ['_group' => value, '_items' => [...], '_count' => n]
     * @param string $field
     * @return self
     */
    public function groupBy(string $field): self {
        $this->groupByField = $field;
        return $this;
    }

    /**
     * Filter groups by aggregate condition. Only used together with groupBy(); without it, and for
     * unknown aggregates or operators, the condition is ignored (passes)
     * @param string $aggregate 'count', 'sum:field', 'avg:field', 'min:field', 'max:field'
     * @param string $operator '>', '<', '>=', '<=', '=', '!='
     * @param mixed $value
     * @return self
     */
    public function having(string $aggregate, string $operator, $value): self {
        $this->havingFilters[] = ['aggregate' => $aggregate, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    /**
     * Case-insensitive full-text search across fields
     * @param string $term Search term
     * @param array $fields Fields to search in (empty = all fields except "key")
     * @return self
     */
    public function search(string $term, array $fields = []): self {
        $this->searchFilters[] = ['term' => $term, 'fields' => array_values($fields)];
        return $this;
    }

    /**
     * Join with another database
     * @param string $foreignDb Foreign database name
     * @param string $localKey Local key field
     * @param string $foreignKey Foreign key field
     * @param string|null $alias Field that receives the joined record (default: the foreign database name;
     *                           it replaces a field of the same name in the result, never in storage)
     * @return self
     */
    public function join(string $foreignDb, string $localKey, string $foreignKey, ?string $alias = null): self {
        $this->joinConfigs[] = [
            'foreignDb' => $foreignDb,
            'localKey' => $localKey,
            'foreignKey' => $foreignKey,
            'alias' => $alias ?? $foreignDb
        ];
        return $this;
    }

}
