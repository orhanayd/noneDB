<?php

namespace noneDB\Tests\Integration;

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

    /** usernames => keys of what is left */
    private function left(): array
    {
        return array_column($this->noneDB->find($this->testDbName, 0), 'key', 'username');
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

        $this->assertSame(['n' => 1], $result);
    }

    /**
     * @test
     */
    public function deleteByFieldValueRemovesRecord(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame([], $result);
        $this->assertSame(['jane' => 1, 'bob' => 2], $this->left(), 'the others keep their keys');
    }

    /**
     * @test
     */
    public function deleteByKey(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['key' => [0]]);

        $this->assertSame(['n' => 1], $result);
        $this->assertSame(['jane' => 1, 'bob' => 2], $this->left());
    }

    /**
     * @test
     */
    public function deleteByMultipleKeys(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['key' => [0, 2]]);

        $this->assertSame(['n' => 2], $result);
        $this->assertSame(['jane' => 1], $this->left());
    }

    /**
     * @test
     */
    public function deleteMultipleRecords(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['age' => 25]);

        // john and bob both have age=25
        $this->assertSame(['n' => 2], $result);
        $this->assertSame(['jane' => 1], $this->left());
    }

    /**
     * @test
     * Delete removes record completely (no null placeholder)
     */
    public function deleteRemovesRecordCompletely(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        // Deleted record cannot be found
        $deleted = $this->noneDB->find($this->testDbName, ['username' => 'john']);
        $this->assertCount(0, $deleted);

        // Other records still exist, unchanged
        $remaining = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame([
            ['username' => 'jane', 'email' => 'jane@test.com', 'age' => 30, 'key' => 1],
            ['username' => 'bob', 'email' => 'bob@test.com', 'age' => 25, 'key' => 2],
        ], $remaining);
    }

    /**
     * @test
     * Delete removes record, other records remain accessible
     */
    public function deleteRemovesRecordKeepsOthers(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        // jane and bob still accessible via public API
        $jane = $this->noneDB->find($this->testDbName, ['username' => 'jane']);
        $bob = $this->noneDB->find($this->testDbName, ['username' => 'bob']);

        $this->assertSame([['username' => 'jane', 'email' => 'jane@test.com', 'age' => 30, 'key' => 1]], $jane);
        $this->assertSame([['username' => 'bob', 'email' => 'bob@test.com', 'age' => 25, 'key' => 2]], $bob);
    }

    /**
     * @test
     */
    public function deleteByMultipleFieldCriteria(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['age' => 25, 'username' => 'john']);

        $this->assertSame(['n' => 1], $result, 'both fields must match (AND)');
        $this->assertSame(['jane' => 1, 'bob' => 2], $this->left());
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

        $this->assertSame(['n' => 0, 'error' => 'Please check your delete parameters'], $result);
        $this->assertSame(['john' => 0, 'jane' => 1, 'bob' => 2], $this->left());
    }

    /**
     * @test
     */
    public function deleteStringReturnsError(): void
    {
        $result = $this->noneDB->delete($this->testDbName, 'john');

        $this->assertSame(['n' => 0, 'error' => 'Please check your delete parameters'], $result);
        $this->assertSame(['john' => 0, 'jane' => 1, 'bob' => 2], $this->left());
    }

    /**
     * @test
     */
    public function deleteIntegerReturnsError(): void
    {
        $result = $this->noneDB->delete($this->testDbName, 123);

        $this->assertSame(['n' => 0, 'error' => 'Please check your delete parameters'], $result);
        // docs/API.md: 0 too (unlike find(), where any integer means every record)
        $this->assertSame(['n' => 0, 'error' => 'Please check your delete parameters'], $this->noneDB->delete($this->testDbName, 0));
        $this->assertSame(['john' => 0, 'jane' => 1, 'bob' => 2], $this->left());
    }

    /**
     * @test
     */
    public function deleteNullReturnsError(): void
    {
        $result = $this->noneDB->delete($this->testDbName, null);

        $this->assertSame(['n' => 0, 'error' => 'Please check your delete parameters'], $result);
        $this->assertSame(['john' => 0, 'jane' => 1, 'bob' => 2], $this->left());
    }

    /**
     * @test
     */
    public function deleteNoMatchingRecords(): void
    {
        $result = $this->noneDB->delete($this->testDbName, ['username' => 'nonexistent']);

        $this->assertSame(['n' => 0], $result);
        $this->assertSame(['john' => 0, 'jane' => 1, 'bob' => 2], $this->left());
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

        $this->assertSame(['n' => 0], $result);
        $this->assertSame(['n' => 0], $this->noneDB->delete($this->testDbName, ['key' => 0]));
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

        // All records deleted
        $this->assertSame([], $result);
        $this->assertSame(0, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function deleteWithEmptyArrayMatchesAll(): void
    {
        $result = $this->noneDB->delete($this->testDbName, []);

        // docs/API.md: an empty filter matches everything
        $this->assertSame(['n' => 3], $result);
        $this->assertSame([], $this->noneDB->find($this->testDbName, 0));
    }

    /**
     * @test
     */
    public function deleteFromNonExistentDB(): void
    {
        $result = $this->noneDB->delete('nonexistent', ['field' => 'value']);

        $this->assertSame(['n' => 0], $result);
        $this->assertDatabaseNotExists('nonexistent');
    }

    /**
     * @test
     */
    public function deleteDoesNotAffectOtherRecords(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'john']);

        $jane = $this->noneDB->find($this->testDbName, ['username' => 'jane']);
        $bob = $this->noneDB->find($this->testDbName, ['username' => 'bob']);

        $this->assertSame([1], array_column($jane, 'key'));
        $this->assertSame([2], array_column($bob, 'key'));
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

        $this->assertSame(['n' => 2], $result);
        $this->assertSame([['name' => 'inactive', 'active' => false, 'key' => 2]], $this->noneDB->find('booltest', 0));
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

        $this->assertSame(['n' => 2], $result);
        $this->assertSame([['value' => 1, 'key' => 1]], $this->noneDB->find('zerotest', 0));
        $this->assertSame(['n' => 0], $this->noneDB->delete('zerotest', ['value' => false]), 'strict: false is not 0');
    }

    /**
     * @test
     * Delete then insert works correctly
     */
    public function deleteThenInsertWorks(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'jane']);
        $this->noneDB->insert($this->testDbName, ['username' => 'newuser']);

        // jane is deleted, cannot be found
        $jane = $this->noneDB->find($this->testDbName, ['username' => 'jane']);
        $this->assertCount(0, $jane);

        // newuser is inserted and can be found; it gets a new key (keys are never reused)
        $newuser = $this->noneDB->find($this->testDbName, ['username' => 'newuser']);
        $this->assertSame([['username' => 'newuser', 'key' => 3]], $newuser);

        // Original records (john, bob) are still there
        $john = $this->noneDB->find($this->testDbName, ['username' => 'john']);
        $this->assertCount(1, $john);
        $bob = $this->noneDB->find($this->testDbName, ['username' => 'bob']);
        $this->assertCount(1, $bob);
    }

    /**
     * @test docs/API.md: keys are never reused, not by deletes and not by compaction. Deleting the newest
     * record (key 2) makes 1 of 3 record versions garbage (> 30%): the database is compacted, and still the
     * next key is 3.
     */
    public function deletingTheNewestRecordNeverFreesItsKey(): void
    {
        $this->assertSame(['n' => 1], $this->noneDB->delete($this->testDbName, ['key' => 2]));
        $this->noneDB->insert($this->testDbName, ['username' => 'next']);
        $this->assertSame(['john' => 0, 'jane' => 1, 'next' => 3], $this->left());
    }

    /**
     * @test docs/API.md: key accepts an integer, a digit string or a list of them; anything else matches nothing
     */
    public function deleteByKeyAcceptsDigitStringsOnly(): void
    {
        foreach (['1.0', '01x', 1.0, true, null, -1, "1\n"] as $bad) {
            $this->assertSame(['n' => 0], $this->noneDB->delete($this->testDbName, ['key' => $bad]), var_export($bad, true));
        }
        $this->assertSame(['n' => 1], $this->noneDB->delete($this->testDbName, ['key' => '1']));
        $this->assertSame(['john' => 0, 'bob' => 2], $this->left());
    }
}
