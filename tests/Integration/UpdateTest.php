<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for update() method
 */
class UpdateTest extends noneDBTestCase
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

    /** Records as find() returns them after setUp: [key => record] */
    private function original(): array
    {
        return [
            ['username' => 'john', 'email' => 'john@test.com', 'age' => 25, 'key' => 0],
            ['username' => 'jane', 'email' => 'jane@test.com', 'age' => 30, 'key' => 1],
            ['username' => 'bob', 'email' => 'bob@test.com', 'age' => 25, 'key' => 2],
        ];
    }

    private function all(): array
    {
        return $this->noneDB->find($this->testDbName, 0);
    }

    // ==========================================
    // SUCCESSFUL UPDATE TESTS
    // ==========================================

    /**
     * @test
     */
    public function updateExistingFieldReturnsN1(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['email' => 'newemail@test.com']]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        $this->assertSame(['n' => 1], $result);
    }

    /**
     * @test
     */
    public function updateExistingFieldChangesValue(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['email' => 'newemail@test.com']]
        ];

        $this->noneDB->update($this->testDbName, $update);

        $expected = $this->original();
        $expected[0]['email'] = 'newemail@test.com';
        $this->assertSame($expected, $this->all(), 'only that field of that record changed, the key stays');
    }

    /**
     * @test
     */
    public function updateAddNewField(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['phone' => '555-1234']]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertCount(1, $result);
        $record = $result[0];
        ksort($record); // field order is not specified
        $this->assertSame(['age' => 25, 'email' => 'john@test.com', 'key' => 0, 'phone' => '555-1234', 'username' => 'john'], $record,
            'a new field is added to the stored record');
    }

    /**
     * @test
     */
    public function updateMultipleFields(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['email' => 'new@test.com', 'age' => 26, 'status' => 'active']]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame('new@test.com', $result[0]['email']);
        $this->assertSame(26, $result[0]['age']);
        $this->assertSame('active', $result[0]['status']);
        $this->assertSame('john', $result[0]['username']);
        $this->assertSame(0, $result[0]['key']);
    }

    /**
     * @test
     */
    public function updateMultipleRecords(): void
    {
        $update = [
            ['age' => 25],
            ['set' => ['age' => 26]]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        // john and bob both have age=25
        $this->assertSame(['n' => 2], $result);
        $this->assertSame([26, 30, 26], array_column($this->all(), 'age'));
    }

    /**
     * @test
     */
    public function updateByKey(): void
    {
        $update = [
            ['key' => [0]],
            ['set' => ['updated' => true]]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        $this->assertSame(['n' => 1], $result);

        $found = $this->noneDB->find($this->testDbName, ['key' => 0]);
        $this->assertTrue($found[0]['updated']);
        $this->assertSame([true, null, null], array_map(function ($r) { return $r['updated'] ?? null; }, $this->all()));
    }

    /**
     * @test
     */
    public function updateByMultipleKeys(): void
    {
        $update = [
            ['key' => [0, 2]],
            ['set' => ['marked' => true]]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        $this->assertSame(['n' => 2], $result);
        $this->assertSame([true, null, true], array_map(function ($r) { return $r['marked'] ?? null; }, $this->all()));
    }

    /**
     * @test docs/API.md: key accepts a digit string ("5") as well
     */
    public function updateByDigitStringKey(): void
    {
        $this->assertSame(['n' => 1], $this->noneDB->update($this->testDbName, [['key' => '1'], ['set' => ['age' => 31]]]));
        $this->assertSame([25, 31, 25], array_column($this->all(), 'age'));
        $this->assertSame(['n' => 0], $this->noneDB->update($this->testDbName, [['key' => '1.0'], ['set' => ['age' => 99]]]), 'not a digit string');
        $this->assertSame([25, 31, 25], array_column($this->all(), 'age'));
    }

    /**
     * @test
     */
    public function updatePreservesOtherFields(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['age' => 26]]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame([['username' => 'john', 'email' => 'john@test.com', 'age' => 26, 'key' => 0]], $result);
    }

    // ==========================================
    // ERROR CASES
    // ==========================================

    /**
     * @test
     */
    public function updateNonArrayReturnsError(): void
    {
        $result = $this->noneDB->update($this->testDbName, 'not an array');

        $this->assertSame(['n' => 0, 'error' => 'Please check your update parameters'], $result);
        $this->assertSame($this->original(), $this->all());
    }

    /**
     * @test
     */
    public function updateMissingSetKeyReturnsError(): void
    {
        $update = [
            ['username' => 'john'],
            ['values' => ['age' => 26]] // Wrong key, should be 'set'
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        $this->assertSame(['n' => 0, 'error' => 'Please check your update parameters'], $result);
        $this->assertSame($this->original(), $this->all());
    }

    /**
     * @test
     */
    public function updateWithReservedKeyInSetReturnsError(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['key' => 999]]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        $this->assertSame(['n' => 0, 'error' => 'Please check your update parameters'], $result);
        $this->assertSame($this->original(), $this->all(), 'keys never change');
    }

    /**
     * @test
     */
    public function updateSingleElementArrayReturnsError(): void
    {
        $update = [
            ['username' => 'john']
            // Missing second element with 'set'
        ];

        $result = $this->noneDB->update($this->testDbName, $update); // no warning either

        $this->assertSame(['n' => 0, 'error' => 'Please check your update parameters'], $result);
        $this->assertSame($this->original(), $this->all());
    }

    /**
     * @test
     */
    public function updateNoMatchingRecords(): void
    {
        $update = [
            ['username' => 'nonexistent'],
            ['set' => ['age' => 99]]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        $this->assertSame(['n' => 0], $result);
        $this->assertSame($this->original(), $this->all());
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function updateWithEmptySet(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => []]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        // docs/API.md: "An empty set changes nothing and returns the number of matching records"
        $this->assertSame(['n' => 1], $result);
        $this->assertSame($this->original(), $this->all());
    }

    /**
     * @test
     */
    public function updateToNullValue(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['email' => null]]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertArrayHasKey('email', $result[0], 'the field stays, with the value null');
        $this->assertNull($result[0]['email']);
        $this->assertSame([['username' => 'john', 'email' => null, 'age' => 25, 'key' => 0]], $this->noneDB->find($this->testDbName, ['email' => null]));
    }

    /**
     * @test
     */
    public function updateToEmptyString(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['email' => '']]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame('', $result[0]['email']);
    }

    /**
     * @test
     */
    public function updateToZero(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['age' => 0]]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame(0, $result[0]['age']);
    }

    /**
     * @test
     */
    public function updateToFalse(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['active' => false]]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertFalse($result[0]['active']);
    }

    /**
     * @test
     */
    public function updateWithNestedArray(): void
    {
        $update = [
            ['username' => 'john'],
            ['set' => ['address' => ['city' => 'NYC', 'zip' => '10001']]]
        ];

        $this->noneDB->update($this->testDbName, $update);
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertSame(['city' => 'NYC', 'zip' => '10001'], $result[0]['address']);
    }

    /**
     * @test
     */
    public function updateAllRecords(): void
    {
        // Use empty filter to match all
        $update = [
            [],
            ['set' => ['updated' => true]]
        ];

        $result = $this->noneDB->update($this->testDbName, $update);

        $this->assertSame(['n' => 3], $result);
        $this->assertSame([true, true, true], array_column($this->all(), 'updated'));
        $this->assertSame([0, 1, 2], array_column($this->all(), 'key'));
    }

    /**
     * @test an update of a database that doesn't exist matches nothing and creates nothing
     */
    public function updateOfMissingDatabaseMatchesNothing(): void
    {
        $this->assertSame(['n' => 0], $this->noneDB->update('missing-db', [[], ['set' => ['a' => 1]]]));
        $this->assertDatabaseNotExists('missing-db');
    }
}
