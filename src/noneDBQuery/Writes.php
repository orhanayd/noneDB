<?php
// noneDBQuery: update, delete, removeFields
// Built into noneDB.php by tools/build.php: edit here, never noneDB.php
trait noneDBQuery_Writes {
    // ==========================================
    // WRITE METHODS
    // ==========================================

    /**
     * Update matching records (sort/offset/limit select which ones)
     * @param array $set Fields to update
     * @return array ["n" => count]
     */
    public function update(array $set): array {
        $error = $this->kernel()->crud->updateSetError($set);
        if ($error !== null) {
            return $error;
        }
        return $this->withWriteLock(function () use ($set) {
            $n = 0;
            $this->eachTargetSegment(function (string $dbname, ?int $segment, array $records) use ($set, &$n) {
                $n += $this->kernel()->crud->updateRecords($dbname, $segment, $records, $set);
            });
            return ['n' => $n];
        }, 'update');
    }

    /**
     * Delete matching records (sort/offset/limit select which ones)
     * @return array ["n" => count]
     */
    public function delete(): array {
        return $this->withWriteLock(function () {
            $n = 0;
            $this->eachTargetSegment(function (string $dbname, ?int $segment, array $records) use (&$n) {
                $n += $this->kernel()->writer->deleteSegmentRecords($dbname, $segment, $records);
            });
            return ['n' => $n];
        }, 'delete');
    }

    /**
     * Remove fields from the stored matching records (permanent)
     * @param array $fields Fields to remove ("key" can't be removed)
     * @return array ["n" => count of updated records, "fields_removed" => array of removed fields]
     */
    public function removeFields(array $fields): array {
        if (empty($fields)) {
            return ['n' => 0, 'fields_removed' => [], 'error' => 'No fields specified'];
        }
        $fieldsToRemove = array_values(array_filter($fields, function ($f) { return $f !== 'key'; }));
        if (empty($fieldsToRemove)) {
            return ['n' => 0, 'fields_removed' => [], 'error' => 'Cannot remove reserved field: key'];
        }

        return $this->withWriteLock(function () use ($fieldsToRemove) {
            $n = 0;
            $removed = [];
            // Fields are removed from the stored records, never from a projected/joined result
            $this->eachTargetSegment(function (string $dbname, ?int $segment, array $records) use ($fieldsToRemove, &$n, &$removed) {
                $replacements = [];
                foreach ($records as $key => $record) {
                    $changed = false;
                    foreach ($fieldsToRemove as $field) {
                        if (array_key_exists($field, $record)) {
                            unset($record[$field]);
                            $removed[$field] = true;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $replacements[$key] = $record;
                    }
                }
                $n += $this->kernel()->writer->updateSegmentRecords($dbname, $segment, $replacements);
            });
            return [
                'n' => $n,
                'fields_removed' => array_values(array_filter($fieldsToRemove, function ($f) use ($removed) { return isset($removed[$f]); }))
            ];
        }, 'removeFields');
    }
}
