<?php
/**
 * Query Documentation Tests
 * Tests all examples from QUERY.md to ensure documentation accuracy
 * @version 3.1.0
 */

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

class QueryDocumentationTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert comprehensive test data for all examples
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'email' => 'john@gmail.com', 'age' => 25, 'role' => 'admin', 'status' => 'active', 'salary' => 75000, 'department' => 'Engineering', 'created_at' => strtotime('-10 days'), 'tags' => ['developer', 'senior']],
            ['name' => 'Jane', 'email' => 'jane@yahoo.com', 'age' => 30, 'role' => 'moderator', 'status' => 'active', 'salary' => 65000, 'department' => 'Design', 'created_at' => strtotime('-5 days'), 'tags' => ['designer']],
            ['name' => 'Bob', 'email' => 'bob@gmail.com', 'age' => 35, 'role' => 'user', 'status' => 'inactive', 'salary' => 55000, 'department' => 'Engineering', 'created_at' => strtotime('-2 days')],
            ['name' => 'Alice', 'email' => 'alice@company.com', 'age' => 28, 'role' => 'editor', 'status' => 'active', 'salary' => 60000, 'department' => 'Marketing', 'created_at' => strtotime('-1 day'), 'tags' => ['content', 'marketing']],
            ['name' => 'Charlie', 'email' => 'charlie@gmail.com', 'age' => 45, 'role' => 'guest', 'status' => 'pending', 'salary' => 0, 'department' => 'Guest', 'created_at' => time()],
            ['name' => 'Diana', 'email' => 'diana@test.com', 'age' => 22, 'role' => 'user', 'status' => 'active', 'salary' => 45000, 'department' => 'Support', 'created_at' => strtotime('-15 days'), 'tags' => ['support']],
            ['name' => 'Eve', 'email' => 'eve@gmail.com', 'age' => 33, 'role' => 'admin', 'status' => 'active', 'salary' => 80000, 'department' => 'Engineering', 'created_at' => strtotime('-20 days'), 'tags' => ['developer', 'lead']],
        ]);
    }

    // ========== QUICK START EXAMPLES ==========

    /**
     * Test Quick Start - Simple Query
     * From: QUERY.md Quick Start section
     */
    public function testQuickStartSimpleQuery(): void
    {
        $users = $this->noneDB->query($this->testDbName)
            ->where(['status' => 'active'])
            ->sort('created_at', 'desc')
            ->limit(10)
            ->get();

        $this->assertGreaterThan(0, count($users));
        foreach ($users as $user) {
            $this->assertEquals('active', $user['status']);
        }
    }

    // ========== COMPARISON OPERATORS ==========

    /**
     * Test $gt operator
     */
    public function testGreaterThanOperator(): void
    {
        $adults = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gte' => 18]])
            ->get();

        $this->assertGreaterThan(0, count($adults));
        foreach ($adults as $user) {
            $this->assertGreaterThanOrEqual(18, $user['age']);
        }
    }

    /**
     * Test range query with $gte and $lte
     */
    public function testRangeQuery(): void
    {
        $workingAge = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gte' => 18, '$lte' => 65]])
            ->get();

        $this->assertGreaterThan(0, count($workingAge));
        foreach ($workingAge as $user) {
            $this->assertGreaterThanOrEqual(18, $user['age']);
            $this->assertLessThanOrEqual(65, $user['age']);
        }
    }

    /**
     * Test $ne operator
     */
    public function testNotEqualOperator(): void
    {
        $nonAdmins = $this->noneDB->query($this->testDbName)
            ->where(['role' => ['$ne' => 'admin']])
            ->get();

        foreach ($nonAdmins as $user) {
            $this->assertNotEquals('admin', $user['role']);
        }
    }

    /**
     * Test $in operator
     */
    public function testInOperator(): void
    {
        $staff = $this->noneDB->query($this->testDbName)
            ->where(['role' => ['$in' => ['admin', 'moderator', 'editor']]])
            ->get();

        $this->assertGreaterThan(0, count($staff));
        foreach ($staff as $user) {
            $this->assertContains($user['role'], ['admin', 'moderator', 'editor']);
        }
    }

    /**
     * Test $nin operator
     */
    public function testNotInOperator(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['status' => ['$nin' => ['inactive', 'pending']]])
            ->get();

        foreach ($results as $user) {
            $this->assertNotContains($user['status'], ['inactive', 'pending']);
        }
    }

    /**
     * Test $exists operator
     */
    public function testExistsOperator(): void
    {
        $withTags = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['$exists' => true]])
            ->get();

        foreach ($withTags as $user) {
            $this->assertArrayHasKey('tags', $user);
        }

        $noTags = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['$exists' => false]])
            ->get();

        foreach ($noTags as $user) {
            $this->assertArrayNotHasKey('tags', $user);
        }
    }

    /**
     * Test $like operator - contains
     */
    public function testLikeContains(): void
    {
        $johns = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$like' => 'john']])
            ->get();

        foreach ($johns as $user) {
            $this->assertStringContainsStringIgnoringCase('john', $user['name']);
        }
    }

    /**
     * Test $like operator - starts with
     */
    public function testLikeStartsWith(): void
    {
        $jNames = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$like' => '^J']])
            ->get();

        foreach ($jNames as $user) {
            $this->assertMatchesRegularExpression('/^J/i', $user['name']);
        }
    }

    /**
     * Test $like operator - ends with
     */
    public function testLikeEndsWith(): void
    {
        $gmails = $this->noneDB->query($this->testDbName)
            ->where(['email' => ['$like' => 'gmail.com$']])
            ->get();

        foreach ($gmails as $user) {
            $this->assertStringEndsWith('gmail.com', $user['email']);
        }
    }

    /**
     * Test $regex operator
     */
    public function testRegexOperator(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$regex' => '^[A-D]']])
            ->get();

        foreach ($results as $user) {
            $this->assertMatchesRegularExpression('/^[A-D]/i', $user['name']);
        }
    }

    /**
     * Test $contains operator with array
     */
    public function testContainsArray(): void
    {
        $developers = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['$contains' => 'developer']])
            ->get();

        foreach ($developers as $user) {
            $this->assertContains('developer', $user['tags']);
        }
    }

    /**
     * Test mixed operators with simple equality
     */
    public function testMixedOperatorsWithEquality(): void
    {
        $activeAdmins = $this->noneDB->query($this->testDbName)
            ->where([
                'role' => 'admin',
                'status' => 'active',
                'salary' => ['$gt' => 50000]
            ])
            ->get();

        foreach ($activeAdmins as $user) {
            $this->assertEquals('admin', $user['role']);
            $this->assertEquals('active', $user['status']);
            $this->assertGreaterThan(50000, $user['salary']);
        }
    }

    // ========== FILTER METHODS ==========

    /**
     * Test orWhere method
     */
    public function testOrWhere(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['role' => 'admin'])
            ->orWhere(['salary' => ['$gte' => 70000]])
            ->get();

        $this->assertGreaterThan(0, count($results));
        foreach ($results as $user) {
            $isAdmin = $user['role'] === 'admin';
            $highSalary = $user['salary'] >= 70000;
            $this->assertTrue($isAdmin || $highSalary);
        }
    }

    /**
     * Test whereIn method
     */
    public function testWhereIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('department', ['Engineering', 'Design'])
            ->get();

        foreach ($results as $user) {
            $this->assertContains($user['department'], ['Engineering', 'Design']);
        }
    }

    /**
     * Test whereNotIn method
     */
    public function testWhereNotIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('role', ['guest', 'user'])
            ->get();

        foreach ($results as $user) {
            $this->assertNotContains($user['role'], ['guest', 'user']);
        }
    }

    /**
     * Test whereNot method
     */
    public function testWhereNot(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['role' => 'guest'])
            ->get();

        foreach ($results as $user) {
            $this->assertNotEquals('guest', $user['role']);
        }
    }

    /**
     * Test like method
     */
    public function testLikeMethod(): void
    {
        // Contains
        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->get();

        foreach ($results as $user) {
            $this->assertStringContainsStringIgnoringCase('gmail', $user['email']);
        }
    }

    /**
     * Test between method
     */
    public function testBetweenMethod(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('salary', 50000, 70000)
            ->get();

        foreach ($results as $user) {
            $this->assertGreaterThanOrEqual(50000, $user['salary']);
            $this->assertLessThanOrEqual(70000, $user['salary']);
        }
    }

    /**
     * Test notBetween method
     */
    public function testNotBetweenMethod(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 35)
            ->get();

        foreach ($results as $user) {
            $outside = $user['age'] < 25 || $user['age'] > 35;
            $this->assertTrue($outside);
        }
    }

    /**
     * Test search method
     */
    public function testSearchMethod(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('gmail', ['email'])
            ->get();

        $this->assertGreaterThan(0, count($results));
    }

    // ========== SORTING & PAGINATION ==========

    /**
     * Test sort method - ascending
     */
    public function testSortAsc(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('name', 'asc')
            ->get();

        $prev = '';
        foreach ($results as $user) {
            $this->assertGreaterThanOrEqual($prev, $user['name']);
            $prev = $user['name'];
        }
    }

    /**
     * Test sort method - descending
     */
    public function testSortDesc(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('salary', 'desc')
            ->get();

        $prev = PHP_INT_MAX;
        foreach ($results as $user) {
            $this->assertLessThanOrEqual($prev, $user['salary']);
            $prev = $user['salary'];
        }
    }

    /**
     * Test limit method
     */
    public function testLimit(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->limit(3)
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * Test offset method
     */
    public function testOffset(): void
    {
        $allResults = $this->noneDB->query($this->testDbName)->get();
        $offsetResults = $this->noneDB->query($this->testDbName)
            ->offset(2)
            ->get();

        $this->assertCount(count($allResults) - 2, $offsetResults);
    }

    /**
     * Test pagination (limit + offset)
     */
    public function testPagination(): void
    {
        $page2 = $this->noneDB->query($this->testDbName)
            ->limit(2)
            ->offset(2)
            ->get();

        $this->assertLessThanOrEqual(2, count($page2));
    }

    // ========== AGGREGATION ==========

    /**
     * Test count method
     */
    public function testCount(): void
    {
        $count = $this->noneDB->query($this->testDbName)->count();
        $this->assertEquals(7, $count);

        $activeCount = $this->noneDB->query($this->testDbName)
            ->where(['status' => 'active'])
            ->count();

        $this->assertGreaterThan(0, $activeCount);
    }

    /**
     * Test sum method
     */
    public function testSum(): void
    {
        $totalSalary = $this->noneDB->query($this->testDbName)
            ->sum('salary');

        $this->assertIsFloat($totalSalary);
        $this->assertGreaterThan(0, $totalSalary);
    }

    /**
     * Test avg method
     */
    public function testAvg(): void
    {
        $avgAge = $this->noneDB->query($this->testDbName)
            ->avg('age');

        $this->assertIsFloat($avgAge);
        $this->assertGreaterThan(0, $avgAge);
    }

    /**
     * Test min method
     */
    public function testMin(): void
    {
        $minAge = $this->noneDB->query($this->testDbName)
            ->min('age');

        $this->assertEquals(22, $minAge);
    }

    /**
     * Test max method
     */
    public function testMax(): void
    {
        $maxSalary = $this->noneDB->query($this->testDbName)
            ->max('salary');

        $this->assertEquals(80000, $maxSalary);
    }

    // ========== TERMINAL METHODS ==========

    /**
     * Test get method
     */
    public function testGet(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['status' => 'active'])
            ->get();

        $this->assertIsArray($results);
    }

    /**
     * Test first method
     */
    public function testFirst(): void
    {
        $first = $this->noneDB->query($this->testDbName)
            ->where(['email' => 'john@gmail.com'])
            ->first();

        $this->assertNotNull($first);
        $this->assertEquals('John', $first['name']);
    }

    /**
     * Test exists method
     */
    public function testExists(): void
    {
        $hasAdmins = $this->noneDB->query($this->testDbName)
            ->where(['role' => 'admin'])
            ->exists();

        $this->assertTrue($hasAdmins);

        $hasSuperadmin = $this->noneDB->query($this->testDbName)
            ->where(['role' => 'superadmin'])
            ->exists();

        $this->assertFalse($hasSuperadmin);
    }

    /**
     * Test update method
     */
    public function testUpdate(): void
    {
        // First insert a test record
        $this->noneDB->insert($this->testDbName, [
            'name' => 'UpdateTest',
            'status' => 'pending',
            'age' => 25
        ]);

        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'UpdateTest'])
            ->update(['status' => 'active']);

        $this->assertGreaterThan(0, $result['n']);

        // Verify
        $updated = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'UpdateTest'])
            ->first();

        $this->assertEquals('active', $updated['status']);
    }

    /**
     * Test delete method
     */
    public function testDelete(): void
    {
        // First insert a test record
        $this->noneDB->insert($this->testDbName, [
            'name' => 'DeleteTest',
            'status' => 'temp'
        ]);

        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'DeleteTest'])
            ->delete();

        $this->assertGreaterThan(0, $result['n']);

        // Verify deleted
        $deleted = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'DeleteTest'])
            ->exists();

        $this->assertFalse($deleted);
    }

    // ========== FIELD SELECTION ==========

    /**
     * Test select method
     */
    public function testSelect(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name', 'email'])
            ->get();

        foreach ($results as $user) {
            $this->assertArrayHasKey('name', $user);
            $this->assertArrayHasKey('email', $user);
            $this->assertArrayHasKey('key', $user); // key is always included
        }
    }

    /**
     * Test except method
     */
    public function testExcept(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['salary', 'age'])
            ->get();

        foreach ($results as $user) {
            $this->assertArrayNotHasKey('salary', $user);
            $this->assertArrayNotHasKey('age', $user);
            $this->assertArrayHasKey('name', $user);
        }
    }

    // ========== COMPLEX QUERIES ==========

    /**
     * Test complex query with multiple filters
     */
    public function testComplexQueryMultipleFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['status' => 'active'])
            ->whereIn('role', ['admin', 'moderator'])
            ->between('age', 25, 40)
            ->sort('salary', 'desc')
            ->limit(5)
            ->get();

        foreach ($results as $user) {
            $this->assertEquals('active', $user['status']);
            $this->assertContains($user['role'], ['admin', 'moderator']);
            $this->assertGreaterThanOrEqual(25, $user['age']);
            $this->assertLessThanOrEqual(40, $user['age']);
        }
    }

    /**
     * Test operators with sorting and limiting
     */
    public function testOperatorsWithSortLimit(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where([
                'salary' => ['$gte' => 50000, '$lte' => 80000],
                'status' => 'active'
            ])
            ->sort('salary', 'desc')
            ->limit(3)
            ->get();

        $this->assertLessThanOrEqual(3, count($results));

        $prev = PHP_INT_MAX;
        foreach ($results as $user) {
            $this->assertGreaterThanOrEqual(50000, $user['salary']);
            $this->assertLessThanOrEqual(80000, $user['salary']);
            $this->assertEquals('active', $user['status']);
            $this->assertLessThanOrEqual($prev, $user['salary']);
            $prev = $user['salary'];
        }
    }

    /**
     * Test chainable method compatibility
     */
    public function testChainableCompatibility(): void
    {
        // Operators + whereIn + between
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gt' => 40000]])
            ->whereIn('department', ['Engineering', 'Design', 'Marketing'])
            ->between('age', 20, 40)
            ->get();

        foreach ($results as $user) {
            $this->assertGreaterThan(40000, $user['salary']);
            $this->assertContains($user['department'], ['Engineering', 'Design', 'Marketing']);
            $this->assertGreaterThanOrEqual(20, $user['age']);
            $this->assertLessThanOrEqual(40, $user['age']);
        }
    }

    // ========== REAL-WORLD EXAMPLES FROM DOCUMENTATION ==========

    /**
     * Test E-commerce Product Search example pattern
     */
    public function testEcommerceSearchPattern(): void
    {
        // Setup product data
        $productDb = $this->testDbName . '_products';
        $this->noneDB->insert($productDb, [
            ['name' => 'Laptop', 'category' => 'electronics', 'price' => 999, 'stock' => 10, 'rating' => 4.5, 'status' => 'active'],
            ['name' => 'Phone', 'category' => 'electronics', 'price' => 599, 'stock' => 25, 'rating' => 4.2, 'status' => 'active'],
            ['name' => 'Headphones', 'category' => 'electronics', 'price' => 199, 'stock' => 0, 'rating' => 4.8, 'status' => 'discontinued'],
            ['name' => 'Monitor', 'category' => 'computers', 'price' => 350, 'stock' => 5, 'rating' => 4.0, 'status' => 'active'],
        ]);

        $products = $this->noneDB->query($productDb)
            ->where([
                'category' => ['$in' => ['electronics', 'computers']],
                'price' => ['$gte' => 100, '$lte' => 1000],
                'stock' => ['$gt' => 0],
                'rating' => ['$gte' => 4.0]
            ])
            ->whereNot(['status' => 'discontinued'])
            ->sort('rating', 'desc')
            ->limit(10)
            ->get();

        foreach ($products as $product) {
            $this->assertContains($product['category'], ['electronics', 'computers']);
            $this->assertGreaterThanOrEqual(100, $product['price']);
            $this->assertLessThanOrEqual(1000, $product['price']);
            $this->assertGreaterThan(0, $product['stock']);
            $this->assertGreaterThanOrEqual(4.0, $product['rating']);
            $this->assertNotEquals('discontinued', $product['status']);
        }

        // Cleanup
        $this->noneDB->delete($productDb, []);
    }

    /**
     * Test User Authentication pattern
     */
    public function testUserAuthenticationPattern(): void
    {
        // Setup
        $authDb = $this->testDbName . '_auth';
        $passwordHash = password_hash('secret123', PASSWORD_DEFAULT);

        $this->noneDB->insert($authDb, [
            ['email' => 'valid@test.com', 'password_hash' => $passwordHash, 'status' => 'active', 'email_verified' => true],
            ['email' => 'unverified@test.com', 'password_hash' => $passwordHash, 'status' => 'active', 'email_verified' => false],
            ['email' => 'banned@test.com', 'password_hash' => $passwordHash, 'status' => 'banned', 'email_verified' => true],
        ]);

        // Find valid user
        $user = $this->noneDB->query($authDb)
            ->where([
                'email' => 'valid@test.com',
                'status' => 'active',
                'email_verified' => true
            ])
            ->first();

        $this->assertNotNull($user);
        $this->assertEquals('valid@test.com', $user['email']);

        // Unverified user should not be found with email_verified filter
        $unverified = $this->noneDB->query($authDb)
            ->where([
                'email' => 'unverified@test.com',
                'status' => 'active',
                'email_verified' => true
            ])
            ->first();

        $this->assertNull($unverified);

        // Cleanup
        $this->noneDB->delete($authDb, []);
    }

    /**
     * Test Content Management pattern
     */
    public function testContentManagementPattern(): void
    {
        $articleDb = $this->testDbName . '_articles';

        $this->noneDB->insert($articleDb, [
            ['title' => 'PHP Tutorial', 'status' => 'published', 'published_at' => time() - 3600, 'tags' => ['php', 'tutorial', 'featured'], 'category' => 'tech'],
            ['title' => 'JavaScript Guide', 'status' => 'draft', 'published_at' => null, 'tags' => ['javascript'], 'category' => 'tech'],
            ['title' => 'Science News', 'status' => 'published', 'published_at' => time() - 7200, 'tags' => ['science', 'featured'], 'category' => 'science'],
        ]);

        // Find published featured articles
        $articles = $this->noneDB->query($articleDb)
            ->where([
                'status' => 'published',
                'published_at' => ['$lte' => time()],
                'tags' => ['$contains' => 'featured']
            ])
            ->sort('published_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($articles as $article) {
            $this->assertEquals('published', $article['status']);
            $this->assertLessThanOrEqual(time(), $article['published_at']);
            $this->assertContains('featured', $article['tags']);
        }

        // Cleanup
        $this->noneDB->delete($articleDb, []);
    }

    // ========== EDGE CASES ==========

    /**
     * Test empty result handling
     */
    public function testEmptyResults(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonexistentPerson'])
            ->get();

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    /**
     * Test null value handling
     */
    public function testNullValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => 'NullTest',
            'email' => null,
            'age' => 30
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['email' => null])
            ->get();

        $found = false;
        foreach ($results as $user) {
            if ($user['name'] === 'NullTest') {
                $found = true;
                $this->assertNull($user['email']);
            }
        }
        $this->assertTrue($found);
    }

    /**
     * Test special characters in search
     */
    public function testSpecialCharactersInSearch(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => "O'Brien",
            'email' => 'obrien@test.com',
            'age' => 40
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => "O'Brien"])
            ->get();

        $this->assertGreaterThan(0, count($results));
        $this->assertEquals("O'Brien", $results[0]['name']);
    }

    /**
     * Test numeric string comparison
     */
    public function testNumericStringComparison(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => 'NumericTest',
            'code' => '100',
            'value' => 100
        ]);

        // Numeric comparison on integer field
        $results = $this->noneDB->query($this->testDbName)
            ->where(['value' => ['$gt' => 50]])
            ->get();

        $found = array_filter($results, fn($r) => $r['name'] === 'NumericTest');
        $this->assertNotEmpty($found);
    }
}
