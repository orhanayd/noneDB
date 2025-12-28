<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for delete() method
 */
class DeleteTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $data = [
            ['username' => 'john', 'email' => 'john@test.com', 'age' => 25],
            ['username' => 'jane', 'email' => 'jane@test.com', 'age' => 30],
            ['username' => 'bob', 'email' => 'bob@test.com', 'age' => 25],
        ];

        $this->noneDB->insert($this->testDbName, $data);
    }

    // ==========================================
    // SUCCESSFUL DELETE TESTS
    // ==========================================

    /**
     * @test
     */
    public function deleteByFieldValueReturnsN1(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function deleteByFieldValueRemovesRecord(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function deleteByKey(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['key' => [0]]);

        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function deleteByMultipleKeys(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['key' => [0, 2]]);

        $this->assertEquals(2, $result['n']);
    }

    /**
     * @test
     */
    public function deleteMultipleRecords(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['age' => 25]);

        // john and bob both have age=25
        $this->assertEquals(2, $result['n']);
    }

    /**
     * @test
     * v3.0: Delete removes record completely (no null placeholder)
     */
    public function deleteRemovesRecordCompletely(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        // Deleted record cannot be found
        $deleted = $this->noneDB->find($this->testDbName, ['username' => 'john']);
        $this->assertCount(0, $deleted);

        // Other records still exist
        $remaining = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(2, $remaining);
    }

    /**
     * @test
     * v3.0: Delete removes record, other records remain accessible
     */
    public function deleteRemovesRecordKeepsOthers(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        // jane and bob still accessible via public API
        $jane = $this->noneDB->find($this->testDbName, ['username' => 'jane']);
        $bob = $this->noneDB->find($this->testDbName, ['username' => 'bob']);

        $this->assertCount(1, $jane);
        $this->assertCount(1, $bob);
        $this->assertEquals('jane', $jane[0]['username']);
        $this->assertEquals('bob', $bob[0]['username']);
    }

    /**
     * @test
     */
    public function deleteByMultipleFieldCriteria(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['age' => 25, 'username' => 'john']);

        $this->assertEquals(1, $result['n']);
    }

    // ==========================================
    // ERROR CASES
    // ==========================================

    /**
     * @test
     */
    public function deleteNonArrayReturnsError(): void
    {
        $result = $this->noneDB->delete($this->testDbName, 'not an array');

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function deleteStringReturnsError(): void
    {
        $result = $this->noneDB->delete($this->testDbName, 'john');

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function deleteIntegerReturnsError(): void
    {
        $result = $this->noneDB->delete($this->testDbName, 123);

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function deleteNullReturnsError(): void
    {
        $result = $this->noneDB->delete($this->testDbName, null);

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function deleteNoMatchingRecords(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['username' => 'nonexistent']);

        $this->assertEquals(0, $result['n']);
        $this->assertArrayNotHasKey('error', $result);
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function deleteAlreadyDeletedRecord(): void
    {
        // Delete once
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        // Try to delete again
        $result = $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function deleteAllRecords(): void
    {
        // Delete all by matching common criteria
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);
        $this->noneDB->delete($this->testDbName, ['username' => 'jane']);
        $this->noneDB->delete($this->testDbName, ['username' => 'bob']);

        $result = $this->noneDB->find($this->testDbName, 0);

        // All records deleted - find returns empty (null records filtered)
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function deleteWithEmptyArrayMatchesAll(): void
    {
        $result = $this->noneDB->delete($this->testDbName, []);

        // Empty criteria should match all records
        $this->assertEquals(3, $result['n']);
    }

    /**
     * @test
     */
    public function deleteFromNonExistentDB(): void
    {
        $result = $this->noneDB->delete('nonexistent', ['field' => 'value']);

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function deleteDoesNotAffectOtherRecords(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        $jane = $this->noneDB->find($this->testDbName, ['username' => 'jane']);
        $bob = $this->noneDB->find($this->testDbName, ['username' => 'bob']);

        $this->assertCount(1, $jane);
        $this->assertCount(1, $bob);
    }

    /**
     * @test
     */
    public function deleteByBooleanValue(): void
    {
        $this->noneDB->insert('booltest', [
            ['name' => 'active1', 'active' => true],
            ['name' => 'active2', 'active' => true],
            ['name' => 'inactive', 'active' => false],
        ]);

        $result = $this->noneDB->delete('booltest', ['active' => true]);

        $this->assertEquals(2, $result['n']);
    }

    /**
     * @test
     */
    public function deleteByZeroValue(): void
    {
        $this->noneDB->insert('zerotest', [
            ['value' => 0],
            ['value' => 1],
            ['value' => 0],
        ]);

        $result = $this->noneDB->delete('zerotest', ['value' => 0]);

        $this->assertEquals(2, $result['n']);
    }

    /**
     * @test
     * v3.0: Delete then insert works correctly
     */
    public function deleteThenInsertWorks(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'jane']);
        $this->noneDB->insert($this->testDbName, ['username' => 'newuser']);

        // jane is deleted, cannot be found
        $jane = $this->noneDB->find($this->testDbName, ['username' => 'jane']);
        $this->assertCount(0, $jane);

        // newuser is inserted and can be found
        $newuser = $this->noneDB->find($this->testDbName, ['username' => 'newuser']);
        $this->assertCount(1, $newuser);
        $this->assertEquals('newuser', $newuser[0]['username']);

        // Original records (john, bob) are still there
        $john = $this->noneDB->find($this->testDbName, ['username' => 'john']);
        $this->assertCount(1, $john);
        $bob = $this->noneDB->find($this->testDbName, ['username' => 'bob']);
        $this->assertCount(1, $bob);
    }
}
