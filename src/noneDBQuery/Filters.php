<?php
// noneDBQuery: filter compilation and matching
// Built into noneDB.php by tools/build.php: edit here, never noneDB.php
trait noneDBQuery_Filters {
    // ==========================================
    // FILTER HELPERS
    // ==========================================

    /**
     * True for a filter value that is an operator spec: a non-empty array whose keys all start with "$"
     * @throws \InvalidArgumentException when operators and plain keys are mixed
     */
    private function isOperatorSpec($value): bool {
        if (!is_array($value) || empty($value)) {
            return false;
        }
        $operators = 0;
        foreach (array_keys($value) as $key) {
            if (is_string($key) && $key !== '' && $key[0] === '$') {
                $operators++;
            }
        }
        if ($operators > 0 && $operators !== count($value)) {
            throw new \InvalidArgumentException('noneDB: a filter value cannot mix operators ($gt, $in, ...) with plain keys');
        }
        return $operators > 0;
    }

    /**
     * Compile where()/orWhere() groups once per query run: operators are validated and patterns
     * turned into regexes here instead of for every record.
     * @return array[] per group a list of [field, operator or null for a plain value, operand]
     * @throws \InvalidArgumentException for unknown operators or invalid operands
     */
    private function compileGroups(array $groups): array {
        $compiled = [];
        foreach ($groups as $group) {
            $conditions = [];
            foreach ($group as $field => $value) {
                if ($this->isOperatorSpec($value)) {
                    foreach ($value as $operator => $operand) {
                        $conditions[] = [(string)$field, $operator, $this->compileOperand($operator, $operand)];
                    }
                } elseif ((string)$field === 'key') {
                    // As find(): key 5, "5" or a list of them (only one plain where() went through find())
                    $conditions[] = ['key', '#keys', array_flip($this->kernel()->findFilter->normalizeKeyFilter($value))];
                } else {
                    $conditions[] = [(string)$field, null, $value];
                }
            }
            $compiled[] = $conditions;
        }
        return $compiled;
    }

    private function compileOperand(string $operator, $operand) {
        switch ($operator) {
            case '$in':
            case '$nin':
                if (!is_array($operand)) {
                    throw new \InvalidArgumentException("noneDB: {$operator} needs an array");
                }
                return $this->membership($operand);
            case '$like':
                return $this->likeSpec($operand);
            case '$regex':
                return self::regexOperand($operand);
            case '$gt':
            case '$gte':
            case '$lt':
            case '$lte':
            case '$ne':
            case '$eq':
            case '$exists':
            case '$contains':
                return $operand;
            default:
                throw new \InvalidArgumentException("noneDB: unknown operator '{$operator}' (supported: " . implode(', ', self::OPERATORS) . ')');
        }
    }

    /**
     * Check if a record matches one compiled where()/orWhere() group (AND of its conditions).
     * Plain values compare with ===; operator conditions apply the operator.
     */
    private function matchesGroup(array $record, array $conditions): bool {
        foreach ($conditions as [$field, $operator, $operand]) {
            if ($operator === null) {
                if (!array_key_exists($field, $record) || $record[$field] !== $operand) {
                    return false;
                }
            } elseif ($operator === '#keys') {
                if (!isset($record['key']) || !is_int($record['key']) || !isset($operand[$record['key']])) {
                    return false;
                }
            } elseif (!$this->matchesOperator($record, $field, $operator, $operand)) {
                return false;
            }
        }
        return true;
    }

    /**
     * where() groups AND-ed, OR any orWhere() group. Without where(), only orWhere() groups decide.
     * @param array[] $where compiled where() groups
     * @param array[] $orWhere compiled orWhere() groups
     */
    private function matchesWhereLogic(array $record, array $where, array $orWhere): bool {
        if (!empty($where)) {
            $all = true;
            foreach ($where as $conditions) {
                if (!$this->matchesGroup($record, $conditions)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        foreach ($orWhere as $conditions) {
            if ($this->matchesGroup($record, $conditions)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compiled like()/$like pattern ("^" start anchor, trailing "$" end anchor, rest literal)
     */
    private function likeSpec($pattern): array {
        if (!is_scalar($pattern)) {
            throw new \InvalidArgumentException('noneDB: like pattern must be a string');
        }
        return \noneDB\Internal\Query\Values::compileLike((string)$pattern);
    }

    /**
     * Regex for a $regex operand: the pattern body, case-insensitive (delimiter added here)
     */
    private static function regexOperand($pattern): string {
        if (!is_string($pattern) || $pattern === '') {
            throw new \InvalidArgumentException('noneDB: $regex needs a non-empty string pattern');
        }
        // Escape the delimiter, but not where "~" is already escaped: skip every "\x" pair first (a "\\"
        // before "~" is an escaped backslash, the "~" still needs escaping)
        $body = preg_replace('/\\\\.(*SKIP)(*FAIL)|~/s', '\\~', $pattern);
        $regex = '~' . $body . '~i' . (preg_match('//u', $pattern) === 1 ? 'u' : '');
        if (@preg_match($regex, '') === false) {
            throw new \InvalidArgumentException("noneDB: invalid \$regex pattern '{$pattern}'");
        }
        return $regex;
    }

    /**
     * Apply a single comparison operator (operand compiled by compileOperand(): $like/$regex are regexes)
     */
    private function matchesOperator(array $record, string $field, string $operator, $operand): bool {
        $hasField = array_key_exists($field, $record);
        $fieldValue = $hasField ? $record[$field] : null;

        switch ($operator) {
            case '$gt':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c > 0;
            case '$gte':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c >= 0;
            case '$lt':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c < 0;
            case '$lte':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c <= 0;
            case '$ne':
                return !$hasField || $fieldValue !== $operand;
            case '$eq':
                return $hasField && $fieldValue === $operand;
            case '$in':
                return $hasField && $this->isMember($fieldValue, $operand);
            case '$nin':
                return !$hasField || !$this->isMember($fieldValue, $operand);
            case '$exists':
                return $operand ? $hasField : !$hasField;
            case '$like':
                if (!$hasField || !is_scalar($fieldValue) || is_bool($fieldValue)) {
                    return false;
                }
                return \noneDB\Internal\Query\Values::likeMatch($operand, (string)$fieldValue);
            case '$regex':
                if (!$hasField || !is_scalar($fieldValue) || is_bool($fieldValue)) {
                    return false;
                }
                return \noneDB\Internal\Query\Values::regexMatch($operand, (string)$fieldValue);
            case '$contains':
                if (!$hasField) {
                    return false;
                }
                if (is_array($fieldValue)) {
                    return in_array($operand, $fieldValue, true);
                }
                if (is_string($fieldValue) && is_scalar($operand) && !is_bool($operand)) {
                    return (string)$operand === '' || strpos($fieldValue, (string)$operand) !== false;
                }
                return false;
            default:
                throw new \InvalidArgumentException("noneDB: unknown operator '{$operator}' (supported: " . implode(', ', self::OPERATORS) . ')');
        }
    }

    /**
     * Check if a record matches all the other filters (whereNot, whereIn, whereNotIn, like, notLike,
     * between, notBetween, search)
     */
    private function matchesAdvancedFilters(array $record): bool {
        foreach ($this->whereNotFilters as $fields) {
            foreach ($fields as $conditions) {
                if ($this->matchesGroup($record, $conditions)) {
                    return false;
                }
            }
        }

        foreach ($this->whereInFilters as $filter) {
            if (!array_key_exists($filter['field'], $record)) return false;
            if (!$this->isMember($record[$filter['field']], $filter['values'])) return false;
        }

        foreach ($this->whereNotInFilters as $filter) {
            if (array_key_exists($filter['field'], $record) && $this->isMember($record[$filter['field']], $filter['values'])) {
                return false;
            }
        }

        foreach ($this->likeFilters as $like) {
            $value = $record[$like['field']] ?? null;
            if (!is_scalar($value) || is_bool($value)) return false;
            if (!\noneDB\Internal\Query\Values::likeMatch($like['like'], (string)$value)) return false;
        }

        foreach ($this->notLikeFilters as $notLike) {
            $value = $record[$notLike['field']] ?? null;
            if (is_scalar($value) && !is_bool($value) && \noneDB\Internal\Query\Values::likeMatch($notLike['like'], (string)$value)) {
                return false;
            }
        }

        foreach ($this->betweenFilters as $between) {
            if (!array_key_exists($between['field'], $record)) return false;
            $value = $record[$between['field']];
            $lo = \noneDB\Internal\Query\Values::compareValues($value, $between['min']);
            $hi = \noneDB\Internal\Query\Values::compareValues($value, $between['max']);
            if ($lo === null || $hi === null || $lo < 0 || $hi > 0) return false;
        }

        foreach ($this->notBetweenFilters as $notBetween) {
            if (!array_key_exists($notBetween['field'], $record)) continue;
            $value = $record[$notBetween['field']];
            $lo = \noneDB\Internal\Query\Values::compareValues($value, $notBetween['min']);
            $hi = \noneDB\Internal\Query\Values::compareValues($value, $notBetween['max']);
            if ($lo !== null && $hi !== null && $lo >= 0 && $hi <= 0) return false;
        }

        foreach ($this->searchFilters as $search) {
            $term = (string)$search['term'];
            if ($term === '') continue;
            $searchFields = empty($search['fields']) ? array_keys($record) : $search['fields'];
            $found = false;
            foreach ($searchFields as $field) {
                if (empty($search['fields']) && ($field === 'key' || $field === '_distance')) continue; // not content
                $value = $record[$field] ?? null;
                if (!is_scalar($value) || is_bool($value)) continue;
                if (self::containsIgnoreCase((string)$value, $term)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) return false;
        }

        return true;
    }

    /**
     * Case-insensitive substring test with Unicode case folding when mbstring is available
     */
    private static function containsIgnoreCase(string $haystack, string $needle): bool {
        if (\noneDB\Internal\Query\Values::hasMbstring() && preg_match('//u', $haystack) === 1 && preg_match('//u', $needle) === 1) {
            return strpos(\noneDB\Internal\Query\Values::foldCase($haystack), \noneDB\Internal\Query\Values::foldCase($needle)) !== false;
        }
        return stripos($haystack, $needle) !== false;
    }

    private function hasAdvancedFilters(): bool {
        return !empty($this->whereNotFilters) || !empty($this->whereInFilters) || !empty($this->whereNotInFilters)
            || !empty($this->likeFilters) || !empty($this->notLikeFilters) || !empty($this->betweenFilters)
            || !empty($this->notBetweenFilters) || !empty($this->searchFilters);
    }

}
