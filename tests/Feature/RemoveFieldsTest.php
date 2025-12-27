<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Comprehensive tests for removeFields() method
 * This method permanently removes specified fields from matching records
 */
class RemoveFieldsTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data with various fields
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'score' => 85, 'department' => 'IT', 'active' => true, 'notes' => 'Some notes'],
            ['name' => 'Bob', 'age' => 30, 'city' => 'Ankara', 'email' => 'bob@yahoo.com', 'score' => 90, 'department' => 'HR', 'active' => true, 'notes' => 'Other notes'],
            ['name' => 'Charlie', 'age' => 35, 'city' => 'Istanbul', 'email' => 'charlie@gmail.com', 'score' => 75, 'department' => 'IT', 'active' => false, 'notes' => null],
            ['name' => 'David', 'age' => 28, 'city' => 'Izmir', 'email' => 'david@hotmail.com', 'score' => 95, 'department' => 'Sales', 'active' => true],
            ['name' => 'Eve', 'age' => 22, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'score' => 80, 'department' => 'IT', 'active' => true, 'notes' => 'Eve notes'],
        ]);
    }

    // ==========================================
    // BASIC FUNCTIONALITY
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsBasicUsage(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $this->assertEquals(4, $result['n']); // 4 records have 'notes' field
        $this->assertContains('notes', $result['fields_removed']);

        // Verify fields are removed
        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayNotHasKey('notes', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsMultipleFields(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes', 'score']);

        $this->assertGreaterThanOrEqual(4, $result['n']);
        $this->assertContains('notes', $result['fields_removed']);
        $this->assertContains('score', $result['fields_removed']);

        // Verify both fields are removed
        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayNotHasKey('notes', $record);
            $this->assertArrayNotHasKey('score', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsPreservesOtherFields(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        // Verify other fields are preserved
        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('age', $record);
            $this->assertArrayHasKey('city', $record);
            $this->assertArrayHasKey('email', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsReturnsCorrectCount(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->removeFields(['notes']);

        // Istanbul has 3 records: Alice, Charlie, Eve
        // Alice and Eve have notes, Charlie has notes=null
        $this->assertGreaterThanOrEqual(2, $result['n']);
    }

    // ==========================================
    // EDGE CASES - EMPTY AND INVALID INPUT
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithEmptyArray(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields([]);

        $this->assertEquals(0, $result['n']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No fields specified', $result['error']);
    }

    /**
     * @test
     */
    public function removeFieldsWithNonExistentField(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['nonexistent_field']);

        // No records have this field, so no updates
        $this->assertEquals(0, $result['n']);
        $this->assertEmpty($result['fields_removed']);
    }

    /**
     * @test
     */
    public function removeFieldsWithMixedExistentNonExistent(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes', 'nonexistent']);

        // Only 'notes' should be in fields_removed
        $this->assertContains('notes', $result['fields_removed']);
        $this->assertNotContains('nonexistent', $result['fields_removed']);
    }

    /**
     * @test
     */
    public function removeFieldsCannotRemoveKeyField(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['key']);

        $this->assertEquals(0, $result['n']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('key', $result['error']);

        // Verify key is still present
        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayHasKey('key', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsKeyWithOtherFields(): void
    {
        // Try to remove key along with valid fields
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['key', 'notes']);

        // key should be ignored, notes should be removed
        $this->assertGreaterThan(0, $result['n']);
        $this->assertContains('notes', $result['fields_removed']);
        $this->assertNotContains('key', $result['fields_removed']);

        // Verify key is still present, notes is removed
        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayHasKey('key', $record);
            $this->assertArrayNotHasKey('notes', $record);
        }
    }

    // ==========================================
    // WITH FILTERS
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithWhereFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->removeFields(['notes']);

        // Only IT department records should be affected
        $itRecords = $this->noneDB->find($this->testDbName, ['department' => 'IT']);
        foreach ($itRecords as $record) {
            $this->assertArrayNotHasKey('notes', $record);
        }

        // HR record should still have notes
        $hrRecords = $this->noneDB->find($this->testDbName, ['department' => 'HR']);
        foreach ($hrRecords as $record) {
            $this->assertArrayHasKey('notes', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsWithWhereInFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->removeFields(['score']);

        // Istanbul (3) + Ankara (1) = 4 records affected
        $this->assertEquals(4, $result['n']);

        // Izmir should still have score
        $izmirRecords = $this->noneDB->find($this->testDbName, ['city' => 'Izmir']);
        foreach ($izmirRecords as $record) {
            $this->assertArrayHasKey('score', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsWithBetweenFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->between('age', 25, 30)
            ->removeFields(['email']);

        // Alice(25), Bob(30), David(28) = 3 records
        $this->assertEquals(3, $result['n']);

        // Verify Charlie(35) and Eve(22) still have email
        $remainingWithEmail = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 30)
            ->get();

        foreach ($remainingWithEmail as $record) {
            $this->assertArrayHasKey('email', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsWithOrWhereFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Izmir'])
            ->orWhere(['city' => 'Ankara'])
            ->removeFields(['department']);

        // Izmir (1) + Ankara (1) = 2 records
        $this->assertEquals(2, $result['n']);

        // Istanbul records should still have department
        $istanbulRecords = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);
        foreach ($istanbulRecords as $record) {
            $this->assertArrayHasKey('department', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsWithSearchFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->removeFields(['active']);

        // Alice, Charlie, Eve have gmail = 3 records
        $this->assertEquals(3, $result['n']);
    }

    /**
     * @test
     */
    public function removeFieldsWithNoMatchingRecords(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->removeFields(['notes']);

        $this->assertEquals(0, $result['n']);
    }

    // ==========================================
    // SPECIAL FIELD VALUES
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithNullValue(): void
    {
        // Charlie has notes = null
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Charlie'])
            ->removeFields(['notes']);

        // Should still remove the field even if value is null
        $this->assertEquals(1, $result['n']);

        $charlie = $this->noneDB->first($this->testDbName, ['name' => 'Charlie']);
        $this->assertArrayNotHasKey('notes', $charlie);
    }

    /**
     * @test
     */
    public function removeFieldsWithBooleanValue(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['active']);

        $this->assertEquals(5, $result['n']);

        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayNotHasKey('active', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsWithNumericValue(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['age']);

        $this->assertEquals(5, $result['n']);

        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayNotHasKey('age', $record);
        }
    }

    // ==========================================
    // PARTIAL FIELD EXISTENCE
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWhenOnlySomeRecordsHaveField(): void
    {
        // David doesn't have 'notes' field
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        // Only 4 records have notes (Alice, Bob, Charlie, Eve)
        $this->assertEquals(4, $result['n']);

        // David should be unchanged
        $david = $this->noneDB->first($this->testDbName, ['name' => 'David']);
        $this->assertArrayHasKey('name', $david);
        $this->assertArrayHasKey('age', $david);
    }

    // ==========================================
    // MULTIPLE OPERATIONS
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsMultipleTimes(): void
    {
        // First removal
        $result1 = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        // Second removal on same records
        $result2 = $this->noneDB->query($this->testDbName)
            ->removeFields(['score']);

        // Third removal - notes already removed, should return 0
        $result3 = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $this->assertGreaterThan(0, $result1['n']);
        $this->assertGreaterThan(0, $result2['n']);
        $this->assertEquals(0, $result3['n']); // Already removed

        // Verify both fields are removed
        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayNotHasKey('notes', $record);
            $this->assertArrayNotHasKey('score', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsThenUpdate(): void
    {
        // Remove a field
        $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->removeFields(['notes']);

        // Update the same record
        $this->noneDB->update($this->testDbName, [
            ['name' => 'Alice'],
            ['set' => ['status' => 'updated']]
        ]);

        $alice = $this->noneDB->first($this->testDbName, ['name' => 'Alice']);
        $this->assertArrayNotHasKey('notes', $alice);
        $this->assertEquals('updated', $alice['status']);
    }

    /**
     * @test
     */
    public function removeFieldsThenDelete(): void
    {
        $initialCount = $this->noneDB->count($this->testDbName);

        // Remove a field from IT department
        $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->removeFields(['notes']);

        // Delete some records
        $this->noneDB->query($this->testDbName)
            ->where(['department' => 'HR'])
            ->delete();

        $finalCount = $this->noneDB->count($this->testDbName);
        $this->assertLessThan($initialCount, $finalCount);

        // IT records should still exist without notes
        $itRecords = $this->noneDB->find($this->testDbName, ['department' => 'IT']);
        foreach ($itRecords as $record) {
            $this->assertArrayNotHasKey('notes', $record);
        }
    }

    // ==========================================
    // DATA INTEGRITY
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsPreservesRecordCount(): void
    {
        $countBefore = $this->noneDB->count($this->testDbName);

        $this->noneDB->query($this->testDbName)
            ->removeFields(['notes', 'score']);

        $countAfter = $this->noneDB->count($this->testDbName);

        $this->assertEquals($countBefore, $countAfter);
    }

    /**
     * @test
     */
    public function removeFieldsPreservesKeyValues(): void
    {
        $recordsBefore = $this->noneDB->find($this->testDbName, 0);
        $keysBefore = array_column($recordsBefore, 'key');

        $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $recordsAfter = $this->noneDB->find($this->testDbName, 0);
        $keysAfter = array_column($recordsAfter, 'key');

        $this->assertEquals($keysBefore, $keysAfter);
    }

    /**
     * @test
     */
    public function removeFieldsPreservesFieldValues(): void
    {
        $aliceBefore = $this->noneDB->first($this->testDbName, ['name' => 'Alice']);
        $originalAge = $aliceBefore['age'];
        $originalCity = $aliceBefore['city'];

        $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->removeFields(['notes']);

        $aliceAfter = $this->noneDB->first($this->testDbName, ['name' => 'Alice']);

        $this->assertEquals($originalAge, $aliceAfter['age']);
        $this->assertEquals($originalCity, $aliceAfter['city']);
    }

    // ==========================================
    // COMPLEX SCENARIOS
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsComplexFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->between('age', 20, 30)
            ->where(['active' => true])
            ->removeFields(['notes', 'score']);

        // Istanbul: Alice(25,active), Eve(22,active)
        // Ankara: Bob(30,active)
        // = 3 records
        $this->assertEquals(3, $result['n']);
    }

    /**
     * @test
     */
    public function removeFieldsAllFieldsExceptRequired(): void
    {
        // Remove all optional fields, keep name
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['age', 'city', 'email', 'score', 'department', 'active', 'notes']);

        // All records should now only have 'name' and 'key'
        $records = $this->noneDB->find($this->testDbName, 0);
        foreach ($records as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('key', $record);
            $this->assertArrayNotHasKey('age', $record);
            $this->assertArrayNotHasKey('city', $record);
        }
    }

    /**
     * @test
     */
    public function removeFieldsReturnValueStructure(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('n', $result);
        $this->assertArrayHasKey('fields_removed', $result);
        $this->assertIsInt($result['n']);
        $this->assertIsArray($result['fields_removed']);
    }

    // ==========================================
    // CHAINABLE METHOD CHECK
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsIsTerminalMethod(): void
    {
        // removeFields should return result array, not $this
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('n', $result);

        // Should NOT be chainable
        $this->assertNotInstanceOf(\noneDBQuery::class, $result);
    }
}
