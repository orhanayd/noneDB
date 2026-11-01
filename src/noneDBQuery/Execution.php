<?php
// noneDBQuery: get(), sorting, joins, grouping, projection
// Built into noneDB.php by tools/build.php: edit here, never noneDB.php
trait noneDBQuery_Execution {
    // ==========================================
    // EXECUTION
    // ==========================================

    /**
     * Records the spatial filters allow ([key => record]); nearest() returns its candidates
     * in distance order with _distance set. Null when there are no spatial filters.
     * @param float|null $nearestRadius Only the records within this many meters for a nearest() filter
     *        (null: all records in distance order)
     * @param float|null $nearestBoundary Set when the radius was used: records with a smaller _distance
     *        are exactly those of the full list (anything outside the radius is at least this far)
     */
    private function spatialCandidates(?float $nearestRadius = null, ?float &$nearestBoundary = null): ?array {
        $nearestBoundary = null;
        if (empty($this->spatialFilters)) {
            return null;
        }
        $result = null;
        foreach ($this->spatialFilters as $filter) {
            switch ($filter['type']) {
                case 'withinDistance':
                    $rows = $this->db->withinDistance($this->dbname, $filter['field'], $filter['lon'], $filter['lat'], $filter['distance'], ['includeDistance' => true]);
                    break;
                case 'withinBBox':
                    $rows = $this->db->withinBBox($this->dbname, $filter['field'], $filter['minLon'], $filter['minLat'], $filter['maxLon'], $filter['maxLat']);
                    break;
                case 'nearest':
                    // Records in distance order; the k cut happens after the other filters
                    if ($filter['k'] <= 0) {
                        $rows = [];
                    } elseif ($nearestRadius !== null) {
                        $rows = $this->db->withinDistance($this->dbname, $filter['field'], $filter['lon'], $filter['lat'], $nearestRadius, ['includeDistance' => true]);
                        $nearestBoundary = round($nearestRadius, 2);
                    } else {
                        $all = max(1, $this->db->count($this->dbname));
                        $rows = $this->db->nearest($this->dbname, $filter['field'], $filter['lon'], $filter['lat'], $all, ['includeDistance' => true]);
                    }
                    break;
                case 'withinPolygon':
                    $rows = $this->db->withinPolygon($this->dbname, $filter['field'], $filter['polygon']);
                    break;
                default:
                    $rows = [];
            }
            $keyed = [];
            foreach ($rows as $row) {
                if (isset($row['key'])) {
                    $keyed[$row['key']] = $row;
                }
            }
            if ($result === null) {
                $result = $keyed;
            } elseif ($filter['type'] === 'nearest') {
                // nearest() orders and cuts by its own distance: its order and _distance win
                $result = array_intersect_key($keyed, $result);
            } else {
                // Intersect by key, keep the order and fields of the earlier result
                $result = array_intersect_key($result, $keyed);
                foreach ($result as $key => $row) {
                    if (isset($keyed[$key]['_distance']) && !isset($row['_distance'])) {
                        $result[$key]['_distance'] = $keyed[$key]['_distance'];
                    }
                }
            }
        }
        return $result ?? [];
    }

    /**
     * The plan of the query's filters (see noneDB's Planner): what field indexes, global field indexes and
     * key lookups can narrow down. Every record read is still checked against every filter.
     * @return array
     */
    private function buildPlan(): array {
        $where = [];
        foreach ($this->whereFilters as $group) {
            $where[] = $this->groupPlan($group);
        }
        $node = count($where) === 1 ? $where[0] : (empty($where) ? null : ['and', $where]);
        if (!empty($this->orWhereFilters)) {
            // where groups (all) OR any orWhere group; an empty orWhere() group matches everything
            $branches = $node === null ? [] : [$node];
            foreach ($this->orWhereFilters as $group) {
                $branches[] = $this->groupPlan($group);
            }
            $node = ['or', $branches];
        }
        $and = $node === null ? [] : [$node];
        foreach ($this->whereInFilters as $filter) {
            $and[] = $this->inPlan($filter['field'], $filter['raw'] ?? []);
        }
        foreach ($this->betweenFilters as $between) {
            $and[] = ['range', $between['field'], '$gte', $between['min']];
            $and[] = ['range', $between['field'], '$lte', $between['max']];
        }
        return self::simplifyPlan(['and', $and]);
    }

    /**
     * Plan of one where()/orWhere() group (all its conditions)
     */
    private function groupPlan(array $group): array {
        $and = [];
        foreach ($group as $field => $value) {
            $field = (string)$field;
            if (!$this->isOperatorSpec($value)) {
                if ($field === 'key') {
                    $and[] = ['keys', $this->kernel()->findFilter->normalizeKeyFilter($value)];
                } elseif (!is_array($value)) {
                    $and[] = ['eq', $field, $value];
                }
                continue;
            }
            foreach ($value as $operator => $operand) {
                if ($operator === '$eq' && !is_array($operand)) {
                    $and[] = $field === 'key' ? ['keys', $this->kernel()->findFilter->normalizeKeyFilter($operand)] : ['eq', $field, $operand];
                } elseif ($operator === '$in' && is_array($operand)) {
                    $and[] = $field === 'key' ? ['keys', $this->kernel()->findFilter->normalizeKeyFilter(array_values($operand))] : $this->inPlan($field, $operand);
                } elseif (in_array($operator, ['$gt', '$gte', '$lt', '$lte'], true) && $field !== 'key' && !is_array($operand)) {
                    $and[] = ['range', $field, $operator, $operand];
                }
                // $ne, $nin, $exists, $like, $regex, $contains: checked on the records only
            }
        }
        return self::simplifyPlan(['and', $and]);
    }

    private function inPlan(string $field, array $values): array {
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                return ['any']; // indexes hold no arrays
            }
        }
        return ['in', $field, array_values($values)];
    }

    private static function simplifyPlan(array $node): array {
        if ($node[0] !== 'and' && $node[0] !== 'or') {
            return $node;
        }
        $children = [];
        foreach ($node[1] as $child) {
            $child = self::simplifyPlan($child);
            if ($child[0] === 'any') {
                if ($node[0] === 'or') {
                    return ['any']; // a branch that matches anything
                }
                continue; // AND with "anything" is the rest
            }
            $children[] = $child;
        }
        if (empty($children)) {
            return ['any'];
        }
        return count($children) === 1 ? $children[0] : [$node[0], $children];
    }

    /**
     * True when the filters are one plain where() group with a key filter: find() keeps the order of
     * the keys asked for, which a scan in key order wouldn't
     */
    private function keyOrderLookup(): bool {
        if (count($this->whereFilters) !== 1 || !empty($this->orWhereFilters) || !array_key_exists('key', $this->whereFilters[0])) {
            return false;
        }
        foreach ($this->whereFilters[0] as $value) {
            if ($this->isOperatorSpec($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Filtered records (no join, grouping or projection)
     * @param bool $earlyExit Stop once offset+limit records are collected (only when order can't change)
     */
    private function filteredRecords(bool $earlyExit, ?int $target = null, ?int $topK = null): array {
        // One shared lock for every read the query makes (count, spatial rounds, records), so they
        // all see the same state of the database
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->locks->withReadLock($dbname, function () use ($earlyExit, $target, $topK) {
            return $this->filteredRecordsLocked($earlyExit, $target, $topK);
        });
    }

    /**
     * @param int|null $target Matches enough to stop early (exists()); default: offset + limit without sort
     */
    private function filteredRecordsLocked(bool $earlyExit, ?int $target = null, ?int $topK = null): array {
        $nearest = null;
        $nearestFilters = 0;
        foreach ($this->spatialFilters as $filter) {
            if ($filter['type'] === 'nearest') {
                $nearest = $nearest === null ? $filter['k'] : min($nearest, $filter['k']);
                $nearestFilters++;
            }
        }
        // A single nearest() as the first spatial filter (its distances are the ones kept) on an indexed
        // field doesn't need every record in distance order: search a growing radius until k records match
        $nearestRadius = null;
        $total = 0;
        if ($nearestFilters === 1 && $this->spatialFilters[0]['type'] === 'nearest' && $nearest > 0
            && $this->kernel()->spatialQueries->usesSpatialIndex($this->dbname, $this->spatialFilters[0]['field'])) {
            $nearestRadius = 1000.0;
            $total = $this->db->count($this->dbname);
        }

        [$single, $where, $orWhere, $needWhere, $needAdvanced] = $this->compiledFilters();
        if ($target !== null && $earlyExit && $nearest === null) {
            // exists(): independent of sort
        } else {
            $target = ($earlyExit && $nearest === null && empty($this->sortKeys) && $this->limitCount !== null)
                ? $this->offsetCount + $this->limitCount
                : PHP_INT_MAX;
        }

        while (true) {
            $boundary = null;
            $spatial = $this->spatialCandidates($nearestRadius, $boundary);
            if ($spatial !== null) {
                $records = array_values($spatial);
                $whereApplied = false;
            } elseif ($this->keyOrderLookup()) {
                $records = $this->db->find($this->dbname, $this->whereFilters[0]);
                $records = $records === false ? [] : $records;
                $whereApplied = true;
            } else {
                // Indexes narrow the segments and keys down; the records are read in batches and the scan
                // stops once enough match. A sort with a small limit keeps only the best ones (top-k).
                return $this->scanFiltered($target, $topK);
            }

            $filtered = [];
            foreach ($records as $record) {
                if (!$whereApplied) {
                    if ($single !== null) {
                        if (!$this->matchesGroup($record, $single)) continue;
                    } elseif ($needWhere && !$this->matchesWhereLogic($record, $where, $orWhere)) continue;
                }
                if ($needAdvanced && !$this->matchesAdvancedFilters($record)) continue;
                $filtered[] = $record;
                if (count($filtered) >= $target) break;
            }

            if ($boundary !== null) {
                // Candidates closer than the radius are exactly the full list's (at the radius itself
                // rounding may leave some out). Enough matches among them: done.
                $certain = [];
                foreach ($filtered as $record) {
                    if (($record['_distance'] ?? INF) < $boundary) {
                        $certain[] = $record;
                    }
                }
                if (count($certain) >= $nearest) {
                    $filtered = $certain;
                    break;
                }
                if (count($records) >= $total) {
                    break; // every record lies within the radius: the list is complete
                }
                // Matches grow with the area (r²): aim for k from the matches so far, at least doubling
                $factor = count($certain) > 0 ? min(16.0, max(2.0, sqrt($nearest / count($certain)) * 1.25)) : 4.0;
                // Half the earth's circumference covers everything: then take the complete list
                $nearestRadius = $nearestRadius * $factor >= 20040000.0 ? null : $nearestRadius * $factor;
                continue;
            }
            break;
        }

        if ($nearest !== null) {
            // Stable on every PHP version: equal distances keep the candidate order
            $positions = array_keys($filtered);
            $distances = array_map(function ($r) { return $r['_distance'] ?? INF; }, $filtered);
            array_multisort($distances, SORT_ASC, SORT_NUMERIC, $positions, SORT_ASC, SORT_NUMERIC);
            $sorted = [];
            foreach (array_slice($positions, 0, $nearest) as $position) {
                $sorted[] = $filtered[$position];
            }
            $filtered = $sorted;
        }
        return $filtered;
    }

    /**
     * The filters compiled once per query: [the one where() group when it is the only one (no AND/OR logic
     * per record) or null, where() groups, orWhere() groups, any where/orWhere, any other filter]
     */
    private function compiledFilters(): array {
        $needWhere = !empty($this->whereFilters) || !empty($this->orWhereFilters);
        $where = $needWhere ? $this->compileGroups($this->whereFilters) : [];
        $orWhere = $needWhere ? $this->compileGroups($this->orWhereFilters) : [];
        $single = ($needWhere && count($where) === 1 && empty($orWhere)) ? $where[0] : null;
        return [$single, $where, $orWhere, $needWhere, $this->hasAdvancedFilters()];
    }

    /**
     * Visit the records matching the filters in key order through the plan's scan, none kept: $visit($key,
     * $record) returns false to stop; the first $offset matches are skipped and at most $limit visited. One
     * segment's records are held at a time. Not for spatial filters and key lookups (filteredRecordsLocked()).
     * Caller holds the lock.
     * @param bool $whole The visitor never stops: each segment is read in one go
     * @return array The scan's statistics (explain())
     */
    private function eachMatch(callable $visit, bool $whole, int $offset = 0, ?int $limit = null): array {
        [$single, $where, $orWhere, $needWhere, $needAdvanced] = $this->compiledFilters();
        $left = $limit ?? PHP_INT_MAX;
        if ($left <= 0) {
            return [];
        }
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->planner->scanRecords($dbname, $this->buildPlan(),
            function (array $records) use ($visit, $single, $where, $orWhere, $needWhere, $needAdvanced, &$offset, &$left) {
                foreach ($records as $key => $record) {
                    if ($single !== null) {
                        if (!$this->matchesGroup($record, $single)) continue;
                    } elseif ($needWhere && !$this->matchesWhereLogic($record, $where, $orWhere)) {
                        continue;
                    }
                    if ($needAdvanced && !$this->matchesAdvancedFilters($record)) continue;
                    if ($offset > 0) {
                        $offset--;
                        continue;
                    }
                    if ($visit($key, $record) === false || --$left === 0) {
                        return false;
                    }
                }
                return true;
            }, $whole);
    }

    /**
     * filteredRecordsLocked() without spatial filters: the plan's candidates, every filter checked
     * @param int $target Stop after this many matches
     * @param int|null $topK Keep only the best $topK by the sort keys (records arrive in key order)
     */
    private function scanFiltered(int $target, ?int $topK): array {
        $filtered = [];
        $prune = $topK === null ? PHP_INT_MAX : max(4 * $topK, 1024);
        $this->lastScan = $this->eachMatch(function ($key, array $record) use (&$filtered, $target, $topK, $prune) {
            $filtered[] = $record;
            if ($topK !== null) {
                if (count($filtered) > $prune) {
                    $filtered = array_slice($this->applySort($filtered), 0, $topK);
                }
            } elseif (count($filtered) >= $target) {
                return false;
            }
            return true;
        }, $target === PHP_INT_MAX);
        return $filtered;
    }

    /**
     * True when the results are the matches in key order (no groupBy(), join, sort, spatial filter,
     * withDistance() or key lookup): aggregates and last() then read them through eachMatch(), one segment
     * held at a time, instead of collecting them
     */
    private function streamsResults(): bool {
        return $this->groupByField === null && empty($this->joinConfigs) && empty($this->sortKeys) && empty($this->spatialFilters)
            && $this->distanceConfig === null && !$this->keyOrderLookup();
    }

    /**
     * Every record of the results in order, without projection (the aggregates): $visit($record)
     */
    private function eachResult(callable $visit): void {
        if (!$this->streamsResults()) {
            foreach ($this->results(false) as $record) {
                $visit($record);
            }
            return;
        }
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        $this->kernel()->locks->withReadLock($dbname, function () use ($visit) {
            $this->eachMatch(function ($key, array $record) use ($visit) {
                $visit($record);
            }, $this->limitCount === null, $this->offsetCount, $this->limitCount);
        });
    }

    /**
     * Add _distance for withDistance()
     */
    private function applyDistanceCalculation(array $records): array {
        if ($this->distanceConfig === null) {
            return $records;
        }
        $field = $this->distanceConfig['field'];
        foreach ($records as &$record) {
            $geometry = $record[$field] ?? null;
            if (!is_array($geometry) || !$this->db->validateGeoJSON($geometry)['valid']) {
                continue;
            }
            $distance = $this->kernel()->spatialQueries->distanceToGeometry((float)$this->distanceConfig['lon'], (float)$this->distanceConfig['lat'], $geometry);
            if (is_finite($distance)) { // a geometry without any position has no distance
                $record['_distance'] = round($distance, 4);
            }
        }
        unset($record);
        return $records;
    }

    private function applyJoins(array $records): array {
        foreach ($this->joinConfigs as $join) {
            if ($join['foreignKey'] === 'key') {
                // Joining on record keys: read only the keys asked for
                $keys = [];
                foreach ($records as $record) {
                    $lk = self::joinKey($record[$join['localKey']] ?? null);
                    if (is_int($lk) && $lk >= 0) {
                        $keys[$lk] = $lk;
                    }
                }
                $foreignData = empty($keys) ? [] : $this->db->find($join['foreignDb'], ['key' => array_values($keys)]);
            } else {
                // Only the foreign records whose value can join: the local raw values (a whole number also as
                // its float and canonical string, which join it too), through the foreign field index when
                // there is one. The join identity (joinKey) is only compared, never used as a filter value.
                $values = [];
                foreach ($records as $record) {
                    $value = $record[$join['localKey']] ?? null;
                    $lk = self::joinKey($value);
                    if ($lk === null) {
                        continue;
                    }
                    if (is_int($lk)) {
                        $values['i' . $lk] = $lk;
                        $values['d' . $lk] = (float)$lk;
                        $values['s' . $lk] = (string)$lk;
                    } else {
                        $values[$lk] = $value;
                    }
                }
                $foreignData = empty($values) ? [] : $this->db->query($join['foreignDb'])->whereIn($join['foreignKey'], array_values($values))->get();
            }
            if ($foreignData === false) {
                $foreignData = [];
            }
            $foreignIndexed = [];
            foreach ($foreignData as $fRecord) {
                $fk = self::joinKey($fRecord[$join['foreignKey']] ?? null);
                if ($fk !== null) {
                    $foreignIndexed[$fk] = $fRecord; // the last record with a key wins
                }
            }
            foreach ($records as &$record) {
                $lk = self::joinKey($record[$join['localKey']] ?? null);
                $record[$join['alias']] = $lk !== null ? ($foreignIndexed[$lk] ?? null) : null;
            }
            unset($record);
        }
        return $records;
    }

    /**
     * Join identity of a value (only to compare, never to query): whole numbers in the int64 range,
     * 5.0 and the canonical string "5" are the same as 5 (record keys join with user_id values);
     * other floats by their bits (a float used as an array key was truncated: 1.5 joined 1); other
     * strings as themselves ("007" is not 7, "1.5" is not 1.5); booleans only booleans (true joined 1
     * and "1"); null and arrays never join.
     * @return int|string|null
     */
    private static function joinKey($v) {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            if (is_finite($v) && floor($v) === $v && $v >= -9.2233720368547758E18 && $v < 9.2233720368547758E18) {
                return (int)$v;
            }
            return 'd' . bin2hex(pack('E', $v + 0.0));
        }
        if (is_string($v)) {
            if (preg_match('/\A(0|-?[1-9][0-9]*)\z/', $v) === 1 && (string)(int)$v === $v) {
                return (int)$v;
            }
            return 's' . $v;
        }
        if (is_bool($v)) {
            return $v ? 'b1' : 'b0';
        }
        return null;
    }

    private function applyGroupBy(array $records): array {
        $groups = [];
        // Exact identity (as distinct()): json_encode() followed serialize_precision and split -0.0 from 0.0
        $valueKey = $this->kernel()->values->valueKeyFunction();
        foreach ($records as $record) {
            $value = $record[$this->groupByField] ?? null;
            $groupKey = $valueKey(is_float($value) ? $value + 0.0 : $value);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['_group' => $value, '_items' => [], '_count' => 0];
            }
            $groups[$groupKey]['_items'][] = $record;
            $groups[$groupKey]['_count']++;
        }

        foreach ($this->havingFilters as $having) {
            if (!in_array($having['operator'], ['>', '<', '>=', '<=', '=', '!='], true)) {
                continue; // unknown operator: the condition is ignored (for every group)
            }
            $groups = array_filter($groups, function ($group) use ($having) {
                if ($having['aggregate'] === 'count') {
                    $aggValue = $group['_count'];
                } elseif (!preg_match('/^(sum|avg|min|max):.+$/', $having['aggregate'])) {
                    return true; // unknown aggregate: ignored
                } else {
                    [$aggType, $aggField] = explode(':', $having['aggregate'], 2);
                    $values = [];
                    foreach ($group['_items'] as $item) {
                        $number = \noneDB\Internal\Query\Values::numberOf($item[$aggField] ?? null);
                        if ($number !== null) {
                            $values[] = $number;
                        }
                    }
                    switch ($aggType) {
                        case 'sum': $aggValue = array_sum($values); break;
                        case 'avg': $aggValue = count($values) > 0 ? array_sum($values) / count($values) : 0; break;
                        case 'min': $aggValue = count($values) > 0 ? min($values) : null; break;
                        default:    $aggValue = count($values) > 0 ? max($values) : null; break;
                    }
                }
                if ($aggValue === null) {
                    return false;
                }
                switch ($having['operator']) {
                    case '>': return $aggValue > $having['value'];
                    case '<': return $aggValue < $having['value'];
                    case '>=': return $aggValue >= $having['value'];
                    case '<=': return $aggValue <= $having['value'];
                    case '=': return $aggValue == $having['value'];
                    case '!=': return $aggValue != $having['value'];
                    default: return true; // unknown operator: ignored
                }
            });
        }
        return array_values($groups);
    }

    private function applySort(array $records): array {
        if (empty($this->sortKeys) || count($records) < 2) {
            return $records;
        }
        // Every sort field becomes two columns, kind and value (noneDB::sortKey), sorted by
        // array_multisort with the same comparison as <=>. The original position is the last column,
        // so records that compare equal keep their order (a stable sort on every PHP version).
        $records = array_values($records);
        $sortKey = $this->kernel()->values->sortKeyFunction();
        $columns = [];
        foreach ($this->sortKeys as $sk) {
            $kinds = [];
            $values = [];
            foreach ($records as $record) {
                [$kinds[], $values[]] = $sortKey(is_array($record) && array_key_exists($sk['field'], $record) ? $record[$sk['field']] : null);
            }
            $order = $sk['order'] === 'desc' ? SORT_DESC : SORT_ASC;
            array_push($columns, $kinds, $order, SORT_REGULAR, $values, $order, SORT_REGULAR);
        }
        $columns[] = array_keys($records);
        array_multisort(...$columns);
        $sorted = [];
        foreach ($columns[count($columns) - 1] as $position) {
            $sorted[] = $records[$position];
        }
        return $sorted;
    }

    private function applyProjection(array $records): array {
        if (empty($this->selectFields) && empty($this->exceptFields)) {
            return $records;
        }
        if ($this->groupByField !== null) {
            // Group rows: _group, _count and _items in select()/except() apply to the group rows; other
            // fields apply to the items of each group (select(['name']) must not empty a group)
            $groupFields = ['_group', '_count', '_items'];
            $select = $this->selectFields;
            $except = $this->exceptFields;
            $this->selectFields = array_values(array_diff($select, $groupFields));
            $this->exceptFields = array_values(array_diff($except, $groupFields));
            $groupBy = $this->groupByField;
            $this->groupByField = null;
            try {
                foreach ($records as &$group) {
                    if (!empty($this->selectFields) || !empty($this->exceptFields)) {
                        $group['_items'] = $this->applyProjection($group['_items']);
                    }
                    $keep = array_values(array_intersect($select, $groupFields));
                    if (!empty($keep)) {
                        if (!empty($this->selectFields)) {
                            $keep[] = '_items';
                        }
                        $group = array_intersect_key($group, array_flip($keep));
                    }
                    foreach (array_intersect($except, $groupFields) as $field) {
                        unset($group[$field]);
                    }
                }
                unset($group);
            } finally {
                $this->groupByField = $groupBy;
                $this->selectFields = $select;
                $this->exceptFields = $except;
            }
            return $records;
        }
        return array_map(function ($record) {
            if (!empty($this->selectFields)) {
                $filtered = [];
                foreach ($this->selectFields as $field) {
                    if (array_key_exists($field, $record)) {
                        $filtered[$field] = $record[$field];
                    }
                }
                if (isset($record['key']) && !array_key_exists('key', $filtered)) {
                    $filtered['key'] = $record['key'];
                }
                if ($this->distanceConfig !== null && isset($record['_distance']) && !array_key_exists('_distance', $filtered)) {
                    $filtered['_distance'] = $record['_distance'];
                }
                return $filtered;
            }
            foreach ($this->exceptFields as $field) {
                unset($record[$field]);
            }
            return $record;
        }, $records);
    }

    /**
     * How the query reads its records: per segment whether it is skipped (a global field index or
     * the keys rule it out), read through an index (candidates) or scanned; how it sorts (top-k, full) and
     * whether it stops early. Every record read is checked against every filter ("residual": always).
     * Nothing is built or written; an index a query would build first counts as a scan. With $analyze the
     * query runs too and "actual" reports what it read and returned.
     * @return array
     */
    public function explain(bool $analyze = false): array {
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        $plan = $this->buildPlan();
        $info = $this->kernel()->planner->explainPlan($dbname, $plan);
        $segments = $info['segments'];
        $lateJoins = !empty($this->joinConfigs) && $this->groupByField === null && !$this->sortsByJoinAlias();
        $topK = $this->topKSize($lateJoins);
        if (!empty($this->spatialFilters)) {
            $access = 'spatial';
        } elseif ($segments['total'] === 0 || $segments['skipped'] === $segments['total']) {
            $access = 'none';
        } elseif ($segments['scanned'] === 0) {
            $access = 'index';
        } elseif ($segments['indexed'] === 0) {
            $access = 'scan';
        } else {
            $access = 'mixed';
        }
        $joins = [];
        foreach ($this->joinConfigs as $join) {
            $joins[] = ['db' => $join['foreignDb'], 'on' => $join['localKey'] . ' = ' . $join['foreignKey'],
                'strategy' => $join['foreignKey'] === 'key' ? 'key-lookup' : 'in-lookup', 'when' => $lateJoins ? 'after-slice' : 'before-sort'];
        }
        $result = [
            'explain' => 1,
            'db' => $this->dbname,
            'sharded' => $info['sharded'],
            'access' => $access,
            'segments' => $segments,
            'plan' => $info['plan'],
            'planTruncated' => $info['planTruncated'] ?? false,
            'estimatedRead' => $info['estimatedRead'],
            'filter' => $this->keyOrderLookup() ? ['keys-in-filter-order'] : $plan,
            'residual' => true,
            'spatial' => empty($this->spatialFilters) ? null : array_column($this->spatialFilters, 'type'),
            'sort' => empty($this->sortKeys) ? 'none' : ($topK !== null ? 'top-k' : 'full'),
            'earlyExit' => empty($this->sortKeys) && $this->groupByField === null && $this->limitCount !== null
                && (empty($this->joinConfigs) || $lateJoins) && empty($this->spatialFilters),
            'joins' => $joins,
            'groupBy' => $this->groupByField,
        ];
        if ($analyze) {
            $this->lastScan = null;
            $start = microtime(true);
            $rows = $this->get();
            $result['actual'] = [
                'recordsRead' => $this->lastScan['recordsRead'] ?? null,
                'segmentsRead' => $this->lastScan === null ? null : $this->lastScan['segments'] - $this->lastScan['skipped'],
                'returned' => count($rows),
                'ms' => round((microtime(true) - $start) * 1000, 3),
            ];
        }
        return $result;
    }

    /**
     * Execute query and get all results
     * @return array
     */
    public function get(): array {
        return $this->results(true);
    }

    /**
     * get() with or without select()/except() (aggregates read the fields themselves)
     */
    private function results(bool $project): array {
        if (!empty($this->joinConfigs)) {
            // Every database of the query read in one consistent state (shared locks held together)
            $databases = array_merge([$this->dbname], array_column($this->joinConfigs, 'foreignDb'));
            return $this->kernel()->snapshot->withSnapshot($databases, function () use ($project) {
                return $this->resultsLocked($project);
            });
        }
        return $this->resultsLocked($project);
    }

    private function resultsLocked(bool $project): array {
        // Joins only add fields: without groupBy() and without sorting by a join alias they run on the
        // records left after sort, offset and limit, which also allows stopping early
        $lateJoins = !empty($this->joinConfigs) && $this->groupByField === null && !$this->sortsByJoinAlias();
        $canEarlyExit = (empty($this->joinConfigs) || $lateJoins) && $this->groupByField === null && empty($this->sortKeys);
        $results = $this->filteredRecords($canEarlyExit, null, $this->topKSize($lateJoins));
        $results = $this->applyDistanceCalculation($results);
        if (!$lateJoins) {
            $results = $this->applyJoins($results);
        }
        if ($this->groupByField !== null) {
            $results = $this->applyGroupBy($results); // having() only applies to groups
        }
        $results = $this->applySort($results);
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }
        if ($lateJoins) {
            $results = $this->applyJoins($results);
        }
        return $project ? $this->applyProjection($results) : $results;
    }

    /**
     * Size of the top-k a sorted query with a small limit needs (offset + limit), or null: no limit, a
     * large one, grouping, joins before the sort, or a sort by withDistance()'s _distance
     */
    private function topKSize(bool $lateJoins): ?int {
        if (empty($this->sortKeys) || $this->limitCount === null || $this->groupByField !== null
            || (!empty($this->joinConfigs) && !$lateJoins) || $this->distanceConfig !== null) {
            return null;
        }
        $k = $this->offsetCount + $this->limitCount;
        return $k <= 1000 ? max(1, $k) : null;
    }

    private function sortsByJoinAlias(): bool {
        $aliases = array_column($this->joinConfigs, 'alias');
        foreach ($this->sortKeys as $sk) {
            if (in_array($sk['field'], $aliases, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The records a write (update/delete/removeFields) applies to, one segment at a time: $apply($dbname,
     * segment, [key => record]) once a segment's matches are complete. Filters, sort, offset and limit apply;
     * select/except/join don't matter. Without sort, spatial filters and a key lookup the matches stream from
     * the scan (one segment held); otherwise the target keys come first and their records are read segment by
     * segment. Caller holds the exclusive lock.
     */
    private function eachTargetSegment(callable $apply): void {
        if ($this->groupByField !== null) {
            throw new \LogicException('noneDB: update(), delete() and removeFields() cannot be combined with groupBy()');
        }
        $kernel = $this->kernel();
        $dbname = $kernel->layout->normalizeDbName($this->dbname);
        $kernel->buffer->flushPendingBuffers($dbname);
        // A missing database has no segments: nothing is written, and the filters are still checked
        if (!empty($this->sortKeys) || !empty($this->spatialFilters) || $this->keyOrderLookup()) {
            foreach ($kernel->findFilter->segmentMatches($dbname, ['key' => $this->targetKeys()]) as $segment => $records) {
                $apply($dbname, $segment, $records);
            }
            return;
        }
        $size = $kernel->segments->isSharded($dbname) ? $kernel->segments->shardSizeOf($dbname) : null;
        $segment = null;
        $held = [];
        $this->eachMatch(function ($key, array $record) use ($apply, $dbname, $size, &$segment, &$held) {
            $of = $size === null ? null : intdiv($key, $size);
            if (!empty($held) && ($of !== $segment || count($held) >= \noneDB\Internal\Storage\RecordIO::BATCH)) {
                $apply($dbname, $segment, $held); // the scan is past that segment, or holds a batch of it
                $held = [];
            }
            $segment = $of;
            $held[$key] = $record;
        }, $this->limitCount === null, $this->offsetCount, $this->limitCount);
        if (!empty($held)) {
            $apply($dbname, $segment, $held);
        }
    }

    /**
     * Keys of the records a write applies to when sort, spatial filters or a key lookup decide (see
     * eachTargetSegment())
     * @return int[]
     */
    private function targetKeys(): array {
        $records = $this->applySort($this->applyDistanceCalculation($this->filteredRecords(empty($this->sortKeys))));
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $records = array_slice($records, $this->offsetCount, $this->limitCount);
        }
        $keys = [];
        foreach ($records as $record) {
            if (isset($record['key']) && is_int($record['key'])) {
                $keys[] = $record['key'];
            }
        }
        return $keys;
    }

    /**
     * Run a write under the database's exclusive lock, so selecting and writing are one atomic step
     * ($op: the operation, for getDurability())
     */
    private function withWriteLock(callable $fn, string $op) {
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->locks->withLock($dbname, true, $fn, $op);
    }

    /**
     * Get first matching record
     * @return array|null
     */
    public function first(): ?array {
        $originalLimit = $this->limitCount;
        $this->limitCount = $originalLimit === null ? 1 : min(1, $originalLimit); // limit(0): nothing
        try {
            $results = $this->get();
        } finally {
            $this->limitCount = $originalLimit;
        }
        return $results[0] ?? null;
    }

    /**
     * Get last matching record
     * @return array|null
     */
    public function last(): ?array {
        if (!$this->streamsResults()) {
            $results = $this->get();
            return count($results) > 0 ? $results[count($results) - 1] : null;
        }
        $last = null;
        $this->eachResult(function (array $record) use (&$last) {
            $last = $record;
        });
        return $last === null ? null : $this->applyProjection([$last])[0];
    }

    /**
     * Count matching records (groups, with groupBy())
     * @return int
     */
    public function count(): int {
        if ($this->isUnfiltered()) {
            return $this->db->count($this->dbname, 0);
        }
        if ($this->groupByField !== null) {
            return count($this->get());
        }
        if (empty($this->spatialFilters) && !$this->keyOrderLookup()) {
            return $this->countMatches(); // record by record, none kept in memory
        }
        $results = $this->filteredRecords(true);
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }
        return count($results);
    }

    private function countMatches(): int {
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->locks->withReadLock($dbname, function () {
            $count = 0;
            $this->eachMatch(function () use (&$count) {
                $count++;
            }, $this->limitCount === null, $this->offsetCount, $this->limitCount);
            return $count;
        });
    }

    /**
     * True when count() can use the stored counters
     * @return bool
     */
    private function isUnfiltered(): bool {
        return empty($this->whereFilters)
            && empty($this->orWhereFilters)
            && !$this->hasAdvancedFilters()
            && empty($this->spatialFilters)
            && $this->groupByField === null
            && empty($this->havingFilters)
            && $this->limitCount === null
            && $this->offsetCount === 0;
    }

    /**
     * Check if any records match
     * @return bool
     */
    public function exists(): bool {
        if ($this->limitCount === 0) {
            return false;
        }
        if ($this->groupByField !== null || !empty($this->havingFilters)) {
            return $this->first() !== null; // having() may drop every group: no early exit
        }
        // Sort, joins and projection don't change whether a record exists: stop at offset + 1 matches
        return count($this->filteredRecords(true, $this->offsetCount + 1)) > $this->offsetCount;
    }

}
