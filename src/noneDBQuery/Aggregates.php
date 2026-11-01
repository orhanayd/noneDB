<?php
// noneDBQuery: sum, avg, min, max, distinct (count() is in Execution)
// Built into noneDB.php by tools/build.php: edit here, never noneDB.php
trait noneDBQuery_Aggregates {
    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * Sum and number of the numeric values of a field in the results (booleans and non-numeric values are ignored)
     * @return array [sum, count]
     */
    private function numericTotal(string $field): array {
        $sum = 0;
        $count = 0;
        $this->eachResult(function (array $record) use ($field, &$sum, &$count) {
            $number = \noneDB\Internal\Query\Values::numberOf($record[$field] ?? null);
            if ($number !== null) {
                $sum += $number;
                $count++;
            }
        });
        return [$sum, $count];
    }

    /**
     * Sum of field values
     * @param string $field
     * @return float
     */
    public function sum(string $field): float {
        return (float)$this->numericTotal($field)[0];
    }

    /**
     * Average of field values
     * @param string $field
     * @return float
     */
    public function avg(string $field): float {
        [$sum, $count] = $this->numericTotal($field);
        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Minimum value of field (same ordering as sort())
     * @param string $field
     * @return mixed|null
     */
    public function min(string $field) {
        $min = null;
        $found = false;
        $this->eachResult(function (array $record) use ($field, &$min, &$found) {
            if (isset($record[$field]) && (!$found || \noneDB\Internal\Query\Values::compareSortValues($record[$field], $min) < 0)) {
                $min = $record[$field];
                $found = true;
            }
        });
        return $min;
    }

    /**
     * Maximum value of field (same ordering as sort())
     * @param string $field
     * @return mixed|null
     */
    public function max(string $field) {
        $max = null;
        $found = false;
        $this->eachResult(function (array $record) use ($field, &$max, &$found) {
            if (isset($record[$field]) && (!$found || \noneDB\Internal\Query\Values::compareSortValues($record[$field], $max) > 0)) {
                $max = $record[$field];
                $found = true;
            }
        });
        return $max;
    }

    /**
     * Get unique values of field
     * @param string $field
     * @return array
     */
    public function distinct(string $field): array {
        $seen = [];
        $values = [];
        $this->eachResult(function (array $record) use ($field, &$seen, &$values) {
            \noneDB\Internal\Query\Values::collectUnique([$record], $field, $seen, $values);
        });
        return $values;
    }

}
