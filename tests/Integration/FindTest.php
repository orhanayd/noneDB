<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for find() method
 *
 * Comprehensive tests for querying and filtering records.
 */
class FindTest extends noneDBTestCase
{
    /**
     * Set up test data
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Insert sample data for most tests
        $data = [
            ['username' => 'john', 'email' => 'john@test.com', 'age' => 25, 'active' => true],
            ['username' => 'jane', 'email' => 'jane@test.com', 'age' => 30, 'active' => true],
            ['username' => 'bob', 'email' => 'bob@test.com', 'age' => 25, 'active' => false],
            ['username' => 'alice', 'email' => 'alice@test.com', 'age' => 35, 'active' => true],
        ];

        $this->noneDB->insert($this->testDbName, $data);
    }

    /** The records of setUp as find() returns them: in key order, with the key added */
    private function expectedAll(): array
    {
        return [
            ['username' => 'john', 'email' => 'john@test.com', 'age' => 25, 'active' => true, 'key' => 0],
            ['username' => 'jane', 'email' => 'jane@test.com', 'age' => 30, 'active' => true, 'key' => 1],
            ['username' => 'bob', 'email' => 'bob@test.com', 'age' => 25, 'active' => false, 'key' => 2],
            ['username' => 'alice', 'email' => 'alice@test.com', 'age' => 35, 'active' => true, 'key' => 3],
        ];
    }

    private function keys(array $records): array
    {
        return array_column($records, 'key');
    }

    // ==========================================
    // FIND ALL RECORDS
    // ==========================================

    /**
     * @test
     */
    public function findAllWithInteger0(): void
    {
        $result = $this->noneDB->find($this->testDbName, 0);

        $this->assertSame($this->expectedAll(), $result);
    }

    /**
     * @test
     */
    public function findAllReturnsAllRecords(): void
    {
        $result = $this->noneDB->find($this->testDbName, 0);

        $this->assertSame(['john', 'jane', 'bob', 'alice'], array_column($result, 'username'));
        // docs/API.md: 0 and null (and any integer) mean every record
        $this->assertSame($result, $this->noneDB->find($this->testDbName, null));
        $this->assertSame($result, $this->noneDB->find($this->testDbName, 7));
    }

    /**
     * @test
     */
    public function findAllAddsKeyField(): void
    {
        // Key IS added for all find results for consistency
        $result = $this->noneDB->find($this->testDbName, 0);

        // Each record has its key, as an int, in key order
        $this->assertSame([0, 1, 2, 3], $this->keys($result));
    }

    // ==========================================
    // FIND BY KEY
    // ==========================================

    /**
     * @test
     */
    public function findBySingleKey(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 0]);

        $this->assertSame([$this->expectedAll()[0]], $result);
    }

    /**
     * @test
     */
    public function findByMultipleKeys(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => [0, 2]]);

        $this->assertSame([$this->expectedAll()[0], $this->expectedAll()[2]], $result);
    }

    /**
     * @test docs/API.md: records "in the order of a key list"; a key listed twice is returned once
     */
    public function findByKeyListKeepsTheOrderOfTheList(): void
    {
        $this->assertSame([3, 0, 2], $this->keys($this->noneDB->find($this->testDbName, ['key' => [3, 0, 2, 0]])));
    }

    /**
     * @test docs/API.md: key accepts an integer, a digit string or a list of them; anything else matches
     * nothing; it is combined with the other filters (AND). "5\n" is not key 5.
     */
    public function findByKeyAcceptsOnlyIntegersAndDigitStrings(): void
    {
        $this->assertSame([$this->expectedAll()[1]], $this->noneDB->find($this->testDbName, ['key' => '1']));
        $this->assertSame([1, 3], $this->keys($this->noneDB->find($this->testDbName, ['key' => ['1', 3]])));
        foreach ([1.0, "1\n", ' 1', '1 ', '1e0', '+1', true, null, -1, [], [[1]]] as $bad) {
            $this->assertSame([], $this->noneDB->find($this->testDbName, ['key' => $bad]), var_export($bad, true));
        }
        $this->assertSame([1], $this->keys($this->noneDB->find($this->testDbName, ['key' => [0, 1, 2], 'age' => 30])), 'AND with other fields');
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['key' => 0, 'age' => 30]));
    }

    /**
     * @test docs/API.md: find() returns false for filters that are neither arrays, integers nor null
     */
    public function findWithAnInvalidFilterReturnsFalse(): void
    {
        foreach (['john', 1.5, true, new \stdClass()] as $filter) {
            $this->assertFalse($this->noneDB->find($this->testDbName, $filter), gettype($filter));
        }
    }

    /**
     * @test
     */
    public function findByKeyReturnsKeyInResult(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 1]);

        $this->assertSame([$this->expectedAll()[1]], $result);
    }

    /**
     * @test
     */
    public function findByNonExistentKey(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 999]);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function findByKeyArrayWithNonExistent(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => [0, 999, 1]]);

        // Only existing keys, in the order of the list
        $this->assertSame([0, 1], $this->keys($result));
    }

    /**
     * @test
     */
    public function findByLastKey(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 3]);

        $this->assertSame([$this->expectedAll()[3]], $result);
    }

    // ==========================================
    // FIND BY FIELD FILTER
    // ==========================================

    /**
     * @test
     */
    public function findBySingleField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame([$this->expectedAll()[0]], $result);
    }

    /**
     * @test
     */
    public function findBySingleFieldReturnsKeyField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame(0, $result[0]['key']);
    }

    /**
     * @test
     */
    public function findByMultipleFieldsWithANDLogic(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 25, 'active' => true]);

        // Only john has age=25 AND active=true
        $this->assertSame([$this->expectedAll()[0]], $result);
    }

    /**
     * @test
     */
    public function findNoMatchReturnsEmptyArray(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'nonexistent']);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function findMultipleMatches(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 25]);

        // john and bob both have age=25, in key order
        $this->assertSame([0, 2], $this->keys($result));
    }

    /**
     * @test
     */
    public function findByBooleanField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['active' => false]);

        $this->assertSame([$this->expectedAll()[2]], $result);
    }

    /**
     * @test
     */
    public function findByIntegerField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 35]);

        $this->assertSame([$this->expectedAll()[3]], $result);
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['age' => 35.0]), 'identical (===): 35.0 is not 35');
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['age' => '35']));
    }

    /**
     * @test
     */
    public function findByNonExistentField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['nonexistent' => 'value']);

        $this->assertSame([], $result);
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['nonexistent' => null]), 'null matches only an existing field');
    }

    /**
     * @test
     */
    public function findUsesStrictTypeComparison(): void
    {
        // Insert data with different types
        $this->noneDB->insert('typetest', ['value' => '25']);
        $this->noneDB->insert('typetest', ['value' => 25]);

        // Should use strict comparison
        $result = $this->noneDB->find('typetest', ['value' => 25]);

        // Only integer 25 should match
        $this->assertSame([['value' => 25, 'key' => 1]], $result);
        $this->assertSame([['value' => '25', 'key' => 0]], $this->noneDB->find('typetest', ['value' => '25']));
    }

    // ==========================================
    // FIND WITH NULL RECORDS
    // ==========================================

    /**
     * @test
     */
    public function findSkipsNullRecords(): void
    {
        // Delete a record (sets it to null)
        $this->noneDB->delete($this->testDbName, ['username' => 'jane']);

        $result = $this->noneDB->find($this->testDbName, 0);

        // A deleted record is gone: no placeholder, the others keep their keys
        $this->assertSame([0, 2, 3], $this->keys($result));
        $filtered = $this->noneDB->find($this->testDbName, ['active' => true]);

        // jane was deleted, so only john and alice should match
        $this->assertSame(['john', 'alice'], array_column($filtered, 'username'));
    }

    /**
     * @test
     */
    public function findAfterDeleteReturnsCorrectRecords(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'bob']);

        $result = $this->noneDB->find($this->testDbName, ['age' => 25]);

        // Only john should remain with age=25
        $this->assertSame([$this->expectedAll()[0]], $result);
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function findNonExistentDB(): void
    {
        $result = $this->noneDB->find('nonexistent-db', 0);

        // docs/API.md: [] for a database that doesn't exist; reading never creates a database
        $this->assertSame([], $result);
        $this->assertDatabaseNotExists('nonexistent-db');
        $this->assertDirectoryDoesNotExist($this->dbPath('nonexistent-db', 'home'), 'no file at all, not even a lock file');
    }

    /**
     * @test
     */
    public function findEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');

        $result = $this->noneDB->find('emptydb', 0);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function findPreservesAllFields(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame([$this->expectedAll()[0]], $result);
    }

    /**
     * @test
     */
    public function findWithEmptyFilter(): void
    {
        $result = $this->noneDB->find($this->testDbName, []);

        // Empty array filter should return all records (same as filter 0)
        $this->assertSame($this->expectedAll(), $result);
    }

    /**
     * @test
     */
    public function findWithNullValue(): void
    {
        $this->noneDB->insert('nulltest', ['field' => null, 'other' => 'test']);
        $this->noneDB->insert('nulltest', ['other' => 'no field']);
        $this->noneDB->insert('nulltest', ['field' => false, 'other' => 'false']);

        $result = $this->noneDB->find('nulltest', ['field' => null]);

        // docs/API.md: null matches only a field that exists with the value null
        $this->assertSame([['field' => null, 'other' => 'test', 'key' => 0]], $result);
    }

    /**
     * @test
     */
    public function findWithEmptyStringValue(): void
    {
        $this->noneDB->insert('emptytest', ['field' => '', 'other' => 'test']);
        $this->noneDB->insert('emptytest', ['field' => null, 'other' => 'null']);
        $this->noneDB->insert('emptytest', ['field' => 0, 'other' => 'zero']);

        $result = $this->noneDB->find('emptytest', ['field' => '']);

        $this->assertSame([['field' => '', 'other' => 'test', 'key' => 0]], $result);
    }

    /**
     * @test
     */
    public function findResultsAreIndexedFromZero(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 25]);

        $this->assertSame([0, 1], array_keys($result), 'a list, not keyed by record key');
    }

    /**
     * @test
     */
    public function findWithSpecialCharsInDBName(): void
    {
        // Invalid names are rejected on every operation
        $this->expectException(\InvalidArgumentException::class);
        $this->noneDB->find('test<>db', 0);
    }

    /**
     * @test
     */
    public function findWithZeroAsFieldValue(): void
    {
        $this->noneDB->insert('zerotest', ['value' => 0]);
        $this->noneDB->insert('zerotest', ['value' => false]);
        $this->noneDB->insert('zerotest', ['value' => '0']);

        $result = $this->noneDB->find('zerotest', ['value' => 0]);

        $this->assertSame([['value' => 0, 'key' => 0]], $result);
    }

    /**
     * @test
     */
    public function findWithFalseAsFieldValue(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['active' => false]);

        $this->assertSame([$this->expectedAll()[2]], $result);
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['active' => 0]), 'false is not 0');
    }

    /**
     * @test docs/API.md: arrays must be equal as a whole
     */
    public function findByArrayValueMatchesTheWholeArray(): void
    {
        $this->noneDB->insert('arrays', [['tags' => ['a', 'b']], ['tags' => ['b', 'a']], ['tags' => ['a']], ['tags' => ['a', 'b', 'c']]]);
        $this->assertSame([0], $this->keys($this->noneDB->find('arrays', ['tags' => ['a', 'b']])));
        $this->assertSame([2], $this->keys($this->noneDB->find('arrays', ['tags' => ['a']])));
        $this->assertSame([], $this->noneDB->find('arrays', ['tags' => 'a']));
    }

    /**
     * @test
     */
    public function findKeyFieldIsInteger(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertIsInt($result[0]['key']);
    }
}
