<?php

namespace noneDB\Tests\Feature;

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

        $this->assertEquals(1, $result['n']);
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
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertEquals('newemail@test.com', $result[0]['email']);
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

        $this->assertArrayHasKey('phone', $result[0]);
        $this->assertEquals('555-1234', $result[0]['phone']);
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

        $this->assertEquals('new@test.com', $result[0]['email']);
        $this->assertEquals(26, $result[0]['age']);
        $this->assertEquals('active', $result[0]['status']);
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
        $this->assertEquals(2, $result['n']);
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

        $this->assertEquals(1, $result['n']);

        $found = $this->noneDB->find($this->testDbName, ['key' => 0]);
        $this->assertTrue($found[0]['updated']);
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

        $this->assertEquals(2, $result['n']);
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

        $this->assertEquals('john', $result[0]['username']);
        $this->assertEquals('john@test.com', $result[0]['email']);
        $this->assertEquals(26, $result[0]['age']);
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

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(0, $result['n']);
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

        $this->assertArrayHasKey('error', $result);
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

        $this->assertArrayHasKey('error', $result);
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

        $result = @$this->noneDB->update($this->testDbName, $update);

        $this->assertArrayHasKey('error', $result);
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

        $this->assertEquals(0, $result['n']);
        $this->assertArrayNotHasKey('error', $result);
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

        // Empty set = no changes, but should match 1 record
        $this->assertEquals(1, $result['n']);
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

        $this->assertNull($result[0]['email']);
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

        $this->assertEquals('', $result[0]['email']);
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

        $this->assertEquals(0, $result[0]['age']);
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

        $this->assertEquals('NYC', $result[0]['address']['city']);
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

        $this->assertEquals(3, $result['n']);
    }
}
