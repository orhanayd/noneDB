<?php
/**
 * Query Documentation Tests
 *
 * Every example and every rule stated in docs/QUERY.md behaves as the documentation says.
 * Expected values are derived by hand from the fixture below (or from the documented rule), never
 * copied from the output of the implementation. Results without sort() come in key order.
 * Spatial examples are covered by the spatial test files.
 */

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

class QueryDocumentationTest extends noneDBTestCase
{
    /** @var array[] fixture records, index = record key */
    private $people = [];

    /** @var int fixture reference time */
    private $now;

    protected function setUp(): void
    {
        parent::setUp();

        $now = $this->now = time();
        $day = 86400;
        $this->people = [
            ['name' => 'John', 'email' => 'john@gmail.com', 'age' => 25, 'role' => 'admin', 'status' => 'active', 'salary' => 75000, 'department' => 'Engineering', 'created_at' => $now - 10 * $day, 'tags' => ['developer', 'senior']],
            ['name' => 'Jane', 'email' => 'jane@yahoo.com', 'age' => 30, 'role' => 'moderator', 'status' => 'active', 'salary' => 65000, 'department' => 'Design', 'created_at' => $now - 5 * $day, 'tags' => ['designer']],
            ['name' => 'Bob', 'email' => 'bob@gmail.com', 'age' => 35, 'role' => 'user', 'status' => 'inactive', 'salary' => 55000, 'department' => 'Engineering', 'created_at' => $now - 2 * $day],
            ['name' => 'Alice', 'email' => 'alice@company.com', 'age' => 28, 'role' => 'editor', 'status' => 'active', 'salary' => 60000, 'department' => 'Marketing', 'created_at' => $now - $day, 'tags' => ['content', 'marketing']],
            ['name' => 'Charlie', 'email' => 'charlie@gmail.com', 'age' => 45, 'role' => 'guest', 'status' => 'pending', 'salary' => 0, 'department' => 'Guest', 'created_at' => $now],
            ['name' => 'Diana', 'email' => 'diana@test.com', 'age' => 22, 'role' => 'user', 'status' => 'active', 'salary' => 45000, 'department' => 'Support', 'created_at' => $now - 15 * $day, 'tags' => ['support']],
            ['name' => 'Eve', 'email' => 'eve@gmail.com', 'age' => 33, 'role' => 'admin', 'status' => 'active', 'salary' => 80000, 'department' => 'Engineering', 'created_at' => $now - 20 * $day, 'tags' => ['developer', 'lead']],
        ];
        $this->assertSame(['n' => 7], $this->noneDB->insert($this->testDbName, $this->people));
    }

    // ========== HELPERS ==========

    private function q(?string $db = null): \noneDBQuery
    {
        return $this->noneDB->query($db ?? $this->testDbName);
    }

    /** Names of the result rows, in result order */
    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    /** A fixture record as find() returns it (with its key) */
    private function person(int $key): array
    {
        return $this->people[$key] + ['key' => $key];
    }

    /** Records compared field by field (the position of "key" inside a record is not documented) */
    private function assertSameRecords(array $expected, array $actual, string $message = ''): void
    {
        $normalize = function (array $rows) {
            return array_map(function ($row) { ksort($row); return $row; }, array_values($rows));
        };
        $this->assertSame($normalize($expected), $normalize($actual), $message);
    }

    // ========== QUICK START ==========

    /**
     * Quick Start: where(active) + sort(created_at desc) + limit(10): the active users, newest first
     */
    public function testQuickStartSimpleQuery(): void
    {
        $users = $this->q()
            ->where(['status' => 'active'])
            ->sort('created_at', 'desc')
            ->limit(10)
            ->get();

        // active: John(-10d), Jane(-5d), Alice(-1d), Diana(-15d), Eve(-20d)
        $this->assertSame(['Alice', 'Jane', 'John', 'Diana', 'Eve'], $this->names($users));
        $this->assertSameRecords([$this->person(3), $this->person(1), $this->person(0), $this->person(5), $this->person(6)], $users);
    }

    /**
     * Quick Start: price range + $in + stock > 0, sorted by rating desc
     */
    public function testQuickStartAdvancedOperatorQuery(): void
    {
        $this->noneDB->insert('products', [
            ['name' => 'Laptop', 'price' => 999, 'category' => 'electronics', 'stock' => 5, 'rating' => 4.5], // price > 500
            ['name' => 'Mouse', 'price' => 100, 'category' => 'gadgets', 'stock' => 3, 'rating' => 4.1],      // boundary 100
            ['name' => 'Cable', 'price' => 500, 'category' => 'electronics', 'stock' => 0, 'rating' => 4.9],  // no stock
            ['name' => 'Watch', 'price' => 500, 'category' => 'gadgets', 'stock' => 2, 'rating' => 4.7],      // boundary 500
            ['name' => 'Lamp', 'price' => 200, 'category' => 'home', 'stock' => 9, 'rating' => 5.0],          // category
            ['name' => 'Phone', 'price' => '300', 'category' => 'electronics', 'stock' => 1, 'rating' => 3.9], // numeric string price
        ]);

        $products = $this->q('products')
            ->where([
                'price' => ['$gte' => 100, '$lte' => 500],
                'category' => ['$in' => ['electronics', 'gadgets']],
                'stock' => ['$gt' => 0]
            ])
            ->sort('rating', 'desc')
            ->get();

        $this->assertSame(['Watch', 'Mouse', 'Phone'], $this->names($products));
    }

    // ========== COMPARISON OPERATORS ==========

    /**
     * $gte 18 ("Find adults") includes 18, $gt 18 excludes it
     */
    public function testGreaterThanOperator(): void
    {
        $this->noneDB->insert($this->testDbName, [['name' => 'Kid', 'age' => 17], ['name' => 'Teen', 'age' => 18]]);

        $adults = $this->q()->where(['age' => ['$gte' => 18]])->get();
        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve', 'Teen'], $this->names($adults));

        $over18 = $this->q()->where(['age' => ['$gt' => 18]])->get();
        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve'], $this->names($over18));
    }

    /**
     * $lt ("Find products under $100"): strictly less
     */
    public function testLessThanOperator(): void
    {
        $this->noneDB->insert('products', [
            ['name' => 'A', 'price' => 99.99],
            ['name' => 'B', 'price' => 100],
            ['name' => 'C', 'price' => 150],
            ['name' => 'D', 'price' => 5],
        ]);

        $affordable = $this->q('products')->where(['price' => ['$lt' => 100]])->get();
        $this->assertSame(['A', 'D'], $this->names($affordable));
    }

    /**
     * Range query: both operators apply, boundaries inclusive, numeric strings compare numerically
     */
    public function testRangeQuery(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Kid', 'age' => 17],
            ['name' => 'Teen', 'age' => 18],
            ['name' => 'Senior', 'age' => 65],
            ['name' => 'Retired', 'age' => 66],
            ['name' => 'StrAge', 'age' => '40'],
        ]);

        $workingAge = $this->q()->where(['age' => ['$gte' => 18, '$lte' => 65]])->get();

        $this->assertSame(
            ['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve', 'Teen', 'Senior', 'StrAge'],
            $this->names($workingAge)
        );
    }

    /**
     * where() called twice on the same field: both conditions apply (QUERY.md where())
     */
    public function testSeveralWhereCallsOnTheSameFieldAreAnded(): void
    {
        $this->noneDB->insert($this->testDbName, [['name' => 'Kid', 'age' => 17], ['name' => 'Retired', 'age' => 65]]);

        $results = $this->q()->where(['age' => ['$gte' => 18]])->where(['age' => ['$lt' => 65]])->get();

        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve'], $this->names($results));
    }

    /**
     * $ne: everything but the value; a record without the field also matches
     */
    public function testNotEqualOperator(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoRole']);

        $nonAdmins = $this->q()->where(['role' => ['$ne' => 'admin']])->get();

        $this->assertSame(['Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'NoRole'], $this->names($nonAdmins));
    }

    /**
     * $eq: "Explicit equality (same as simple value)"
     */
    public function testExplicitEqualityMatchesSimpleValue(): void
    {
        $explicit = $this->q()->where(['status' => ['$eq' => 'active']])->get();
        $simple = $this->q()->where(['status' => 'active'])->get();

        $this->assertSame(['John', 'Jane', 'Alice', 'Diana', 'Eve'], $this->names($explicit));
        $this->assertSame($simple, $explicit);
    }

    /**
     * $in: the roles in the list, in key order
     */
    public function testInOperator(): void
    {
        $staff = $this->q()->where(['role' => ['$in' => ['admin', 'moderator', 'editor']]])->get();

        $this->assertSame(['John', 'Jane', 'Alice', 'Eve'], $this->names($staff));
    }

    /**
     * $nin: the statuses not in the list
     */
    public function testNotInOperator(): void
    {
        $results = $this->q()->where(['status' => ['$nin' => ['inactive', 'pending']]])->get();

        $this->assertSame(['John', 'Jane', 'Alice', 'Diana', 'Eve'], $this->names($results));
    }

    /**
     * $exists true / false split the records by whether the field is present
     */
    public function testExistsOperator(): void
    {
        $withTags = $this->q()->where(['tags' => ['$exists' => true]])->get();
        $this->assertSame(['John', 'Jane', 'Alice', 'Diana', 'Eve'], $this->names($withTags));

        $noTags = $this->q()->where(['tags' => ['$exists' => false]])->get();
        $this->assertSame(['Bob', 'Charlie'], $this->names($noTags));

        // QUERY.md: "Find records without phone field" - no fixture record has one
        $this->assertCount(7, $this->q()->where(['phone' => ['$exists' => false]])->get());
        $this->assertSame([], $this->q()->where(['phone' => ['$exists' => true]])->get());
    }

    /**
     * $like 'john': case-insensitive contains
     */
    public function testLikeContains(): void
    {
        $this->noneDB->insert($this->testDbName, [['name' => 'JOHNNY'], ['name' => 'Jon']]);

        $johns = $this->q()->where(['name' => ['$like' => 'john']])->get();

        $this->assertSame(['John', 'JOHNNY'], $this->names($johns));
    }

    /**
     * $like '^M' / '^J': starts with (case-insensitive), not "contains"
     */
    public function testLikeStartsWith(): void
    {
        $this->noneDB->insert($this->testDbName, [['name' => 'Mary'], ['name' => 'Maj'], ['name' => 'Tom']]);

        $this->assertSame(['Mary', 'Maj'], $this->names($this->q()->where(['name' => ['$like' => '^M']])->get()));
        $this->assertSame(['John', 'Jane'], $this->names($this->q()->where(['name' => ['$like' => '^J']])->get()));
        $this->assertSame(['John', 'Jane'], $this->names($this->q()->where(['name' => ['$like' => '^j']])->get()));
    }

    /**
     * $like 'gmail.com$': ends with; "." is literal
     */
    public function testLikeEndsWith(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Dotless', 'email' => 'x@gmailxcom'],
            ['name' => 'Turkey', 'email' => 'x@gmail.com.tr'],
            ['name' => 'Upper', 'email' => 'X@GMAIL.COM'],
        ]);

        $gmails = $this->q()->where(['email' => ['$like' => 'gmail.com$']])->get();

        $this->assertSame(['John', 'Bob', 'Charlie', 'Eve', 'Upper'], $this->names($gmails));
    }

    /**
     * Unicode case folding of $like / like() / notLike(): straße = STRASSE, İstanbul = istanbul,
     * Turkish dotless ı and I don't match
     */
    public function testLikeUnicodeCaseFolding(): void
    {
        if (!function_exists('mb_convert_case')) {
            $this->markTestSkipped('needs the mbstring extension (without it the PCRE /iu rules apply)');
        }
        $this->noneDB->insert('cities', [
            ['name' => 'straße'],
            ['name' => 'İstanbul'],
            ['name' => 'ılık'],
            ['name' => 'Ilık'],
        ]);

        $this->assertSame(['straße'], $this->names($this->q('cities')->where(['name' => ['$like' => 'STRASSE']])->get()));
        $this->assertSame(['straße'], $this->names($this->q('cities')->like('name', 'strasse')->get()));
        $this->assertSame(['İstanbul'], $this->names($this->q('cities')->like('name', 'istanbul')->get()));
        // ı (dotless) and I don't fold to each other: "ılık" and "Ilık" are different words here
        $this->assertSame(['ılık'], $this->names($this->q('cities')->like('name', '^ı')->get()));
        // İ folds to i (+ a dropped combining dot), I folds to i: both start with "i"
        $this->assertSame(['İstanbul', 'Ilık'], $this->names($this->q('cities')->like('name', '^i')->get()));
        $this->assertSame(['straße', 'İstanbul', 'Ilık'], $this->names($this->q('cities')->notLike('name', '^ı')->get()));
    }

    /**
     * $regex '^[A-D]': case-insensitive regular expression without delimiters
     */
    public function testRegexOperator(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'bart']);

        $results = $this->q()->where(['name' => ['$regex' => '^[A-D]']])->get();

        $this->assertSame(['Bob', 'Alice', 'Charlie', 'Diana', 'bart'], $this->names($results));
    }

    /**
     * The e-mail validation example of QUERY.md
     */
    public function testRegexEmailValidationExample(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Bad1', 'email' => 'not-an-email'],
            ['name' => 'Bad2', 'email' => 'a@b'],
            ['name' => 'Good', 'email' => 'first.last+tag@sub.example.org'],
        ]);

        $valid = $this->q()->where(['email' => ['$regex' => '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$']])->get();

        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve', 'Good'], $this->names($valid));
    }

    /**
     * $contains on an array field: element match
     */
    public function testContainsArray(): void
    {
        $developers = $this->q()->where(['tags' => ['$contains' => 'developer']])->get();

        $this->assertSame(['John', 'Eve'], $this->names($developers));
    }

    /**
     * $contains on a string field: substring, case-sensitive ("use $like for case-insensitive matching")
     */
    public function testContainsStringIsCaseSensitive(): void
    {
        $this->noneDB->insert('articles', [
            ['title' => 'New Technology Trends'],
            ['title' => 'technology today'],
            ['title' => 'TECHNOLOGY'],
            ['title' => 'Cooking'],
        ]);

        $tech = $this->q('articles')->where(['title' => ['$contains' => 'Technology']])->get();
        $this->assertSame(['New Technology Trends'], array_column($tech, 'title'));

        $insensitive = $this->q('articles')->where(['title' => ['$like' => 'Technology']])->get();
        $this->assertSame(['New Technology Trends', 'technology today', 'TECHNOLOGY'], array_column($insensitive, 'title'));
    }

    /**
     * Operators and plain values in one where(): all conditions apply
     */
    public function testMixedOperatorsWithEquality(): void
    {
        $activeAdmins = $this->q()
            ->where(['role' => 'admin', 'status' => 'active', 'salary' => ['$gt' => 50000]])
            ->get();
        $this->assertSame(['John', 'Eve'], $this->names($activeAdmins));

        // The QUERY.md example with login_count / last_login
        $this->noneDB->insert('accounts', [
            ['name' => 'a', 'role' => 'admin', 'status' => 'active', 'login_count' => 150, 'last_login' => 1],
            ['name' => 'b', 'role' => 'admin', 'status' => 'active', 'login_count' => 100, 'last_login' => 1], // not > 100
            ['name' => 'c', 'role' => 'admin', 'status' => 'active', 'login_count' => 500],                    // no last_login
            ['name' => 'd', 'role' => 'admin', 'status' => 'banned', 'login_count' => 500, 'last_login' => 1],
            ['name' => 'e', 'role' => 'user', 'status' => 'active', 'login_count' => 500, 'last_login' => null], // role
            ['name' => 'f', 'role' => 'admin', 'status' => 'active', 'login_count' => 101, 'last_login' => null], // null exists
        ]);
        $result = $this->q('accounts')->where([
            'role' => 'admin',
            'status' => 'active',
            'login_count' => ['$gt' => 100],
            'last_login' => ['$exists' => true]
        ])->get();
        $this->assertSame(['a', 'f'], $this->names($result));
    }

    /**
     * "How values compare" in QUERY.md
     */
    public function testHowValuesCompare(): void
    {
        $this->noneDB->insert('values', [
            ['name' => 'int', 'v' => 30],
            ['name' => 'numstr', 'v' => '30'],
            ['name' => 'float', 'v' => 18.5],
            ['name' => 'text', 'v' => 'abc'],
            ['name' => 'null', 'v' => null],
            ['name' => 'true', 'v' => true],
            ['name' => 'false', 'v' => false],
            ['name' => 'array', 'v' => [100]],
            ['name' => 'missing'],
        ]);

        // numbers and numeric strings numerically ("30" > 18); text vs number, null, booleans, arrays, missing: never
        $this->assertSame(['int', 'numstr', 'float'], $this->names($this->q('values')->where(['v' => ['$gt' => 18]])->get()));
        $this->assertSame(['float'], $this->names($this->q('values')->where(['v' => ['$lt' => 20]])->get()));
        // other strings lexically ('2024-02-01' > '2024-01-15'); numbers never compare with a non-numeric string
        $this->noneDB->insert('dates', [
            ['name' => 'feb', 'v' => '2024-02-01'], ['name' => 'jan10', 'v' => '2024-01-10'], ['name' => 'num', 'v' => 20240201],
            ['name' => 'jan15', 'v' => '2024-01-15'], ['name' => 'null', 'v' => null],
        ]);
        $this->assertSame(['feb'], $this->names($this->q('dates')->where(['v' => ['$gt' => '2024-01-15']])->get()));
        $this->assertSame(['feb', 'jan15'], $this->names($this->q('dates')->where(['v' => ['$gte' => '2024-01-15']])->get()));
        $this->assertSame(['jan10'], $this->names($this->q('dates')->where(['v' => ['$lt' => '2024-01-15']])->get()));
        $this->assertSame(['jan10', 'jan15'], $this->names($this->q('dates')->between('v', '2024-01-01', '2024-01-31')->get()));
        // A NAN operand never matches
        foreach (['$gt', '$gte', '$lt', '$lte'] as $op) {
            $this->assertSame([], $this->q('values')->where(['v' => [$op => NAN]])->get(), $op . ' NAN');
        }
        $this->assertSame([], $this->q('values')->between('v', NAN, 100)->get());
        // notBetween() is the complement: every record
        $this->assertCount(9, $this->q('values')->notBetween('v', NAN, 100)->get());

        // $eq, $ne, $in, $nin, whereIn(), whereNot() and plain values are strict: '30' is not 30
        $this->assertSame(['int'], $this->names($this->q('values')->where(['v' => 30])->get()));
        $this->assertSame(['numstr'], $this->names($this->q('values')->where(['v' => '30'])->get()));
        $this->assertSame(['int'], $this->names($this->q('values')->where(['v' => ['$eq' => 30]])->get()));
        $this->assertSame([], $this->q('values')->where(['v' => ['$eq' => 30.0]])->get());
        $this->assertSame(['numstr'], $this->names($this->q('values')->where(['v' => ['$in' => ['30']]])->get()));
        $this->assertSame(['int'], $this->names($this->q('values')->whereIn('v', [30])->get()));
        $this->assertCount(8, $this->q('values')->where(['v' => ['$ne' => 30]])->get());
        $this->assertCount(8, $this->q('values')->where(['v' => ['$nin' => ['30']]])->get());
        $this->assertCount(8, $this->q('values')->whereNot(['v' => 30])->get());
        $this->assertSame(['null'], $this->names($this->q('values')->where(['v' => null])->get()));
        $this->assertSame(['true'], $this->names($this->q('values')->where(['v' => true])->get()));
    }

    /**
     * An array that is not made of operators is compared as a whole
     */
    public function testNonOperatorArrayIsComparedAsAWhole(): void
    {
        $this->noneDB->insert('addresses', [
            ['name' => 'exact', 'addr' => ['city' => 'Ist']],
            ['name' => 'more', 'addr' => ['city' => 'Ist', 'zip' => 34000]],
            ['name' => 'other', 'addr' => ['city' => 'Ank']],
            ['name' => 'string', 'addr' => 'Ist'],
        ]);

        $this->assertSame(['exact'], $this->names($this->q('addresses')->where(['addr' => ['city' => 'Ist']])->get()));
        $this->assertSame(['John'], $this->names($this->q()->where(['tags' => ['developer', 'senior']])->get()));
        $this->assertSame([], $this->q()->where(['tags' => ['senior', 'developer']])->get());
    }

    /**
     * QUERY.md exceptions: a mix throws at where()/orWhere(); unknown operator, $in/$nin without an array and an
     * invalid $regex throw when the query runs, also on an empty database
     */
    public function testInvalidFiltersThrowInvalidArgumentException(): void
    {
        foreach (['where', 'orWhere'] as $method) {
            try {
                $this->q()->$method(['age' => ['$gt' => 1, 'plain' => 2]]);
                $this->fail("$method() with a mix of operators and plain keys must throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('cannot mix operators', $e->getMessage());
            }
        }

        $this->noneDB->createDB('emptydb');
        $cases = [
            [['age' => ['$gtee' => 18]], "unknown operator '\$gtee'"],
            [['age' => ['$in' => 18]], '$in needs an array'],
            [['age' => ['$nin' => 'x']], '$nin needs an array'],
            [['name' => ['$regex' => '[unclosed']], 'invalid $regex pattern'],
        ];
        foreach ([$this->testDbName, 'emptydb'] as $db) {
            foreach ($cases as [$filter, $message]) {
                $query = $this->q($db)->where($filter); // checked when the query runs, not here
                try {
                    $query->get();
                    $this->fail("$message: get() on $db must throw");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($message, $e->getMessage());
                }
            }
        }
    }

    /**
     * A key filter means the same in every clause as in find(): integers and canonical digit strings only
     */
    public function testKeyFilterMeansTheSameInEveryClause(): void
    {
        $this->assertSame(['Diana'], $this->names($this->q()->where(['key' => 5])->get()));
        $this->assertSame(['Diana'], $this->names($this->q()->where(['key' => '5'])->get()));
        $this->assertSame(['Jane', 'Bob'], $this->names($this->q()->where(['key' => [1, 2]])->get()));
        foreach (["5\n", ' 5', '05', 5.0, '5.0'] as $notKey) {
            $this->assertSame([], $this->q()->where(['key' => $notKey])->get(), var_export($notKey, true));
            $this->assertSame([], $this->q()->where(['role' => 'nobody'])->orWhere(['key' => $notKey])->get(), var_export($notKey, true));
        }
        // orWhere and several where() calls
        $this->assertSame(['John', 'Diana', 'Eve'], $this->names($this->q()->where(['role' => 'admin'])->orWhere(['key' => '5'])->get()));
        $this->assertSame(['Jane', 'Diana'], $this->names($this->q()->where(['key' => [1, 2, 5]])->where(['status' => 'active'])->get()));
        $this->assertSame(['Jane'], $this->names($this->q()->where(['status' => 'active'])->where(['key' => '1'])->get()));
        // as find()
        $this->assertSame(['Diana'], $this->names($this->noneDB->find($this->testDbName, ['key' => '5'])));
    }

    // ========== FILTER METHODS ==========

    /**
     * orWhere(): all where() groups OR any orWhere() group
     */
    public function testOrWhere(): void
    {
        // Admins (John, Eve) or salary >= 70000 (John, Eve)
        $this->assertSame(['John', 'Eve'], $this->names(
            $this->q()->where(['role' => 'admin'])->orWhere(['salary' => ['$gte' => 70000]])->get()
        ));
        // Users (Bob, Diana) or salary >= 70000 (John, Eve): key order
        $this->assertSame(['John', 'Bob', 'Diana', 'Eve'], $this->names(
            $this->q()->where(['role' => 'user'])->orWhere(['salary' => ['$gte' => 70000]])->get()
        ));
        // Without where(), only the orWhere() groups decide
        $this->assertSame(['Jane', 'Charlie'], $this->names(
            $this->q()->orWhere(['role' => 'moderator'])->orWhere(['status' => 'pending'])->get()
        ));
        // where() groups must all match before the OR
        $this->assertSame(['John', 'Charlie', 'Eve'], $this->names(
            $this->q()->where(['department' => 'Engineering'])->where(['role' => 'admin'])->orWhere(['role' => 'guest'])->get()
        ));
        // An empty group matches every record (intended)
        $this->assertCount(7, $this->q()->where(['role' => 'nobody'])->orWhere([])->get());
    }

    /**
     * The "Multiple OR conditions" example
     */
    public function testOrWhereMultipleConditionsExample(): void
    {
        $this->noneDB->insert('products', [
            ['name' => 'tv', 'category' => 'electronics', 'featured' => false, 'discount' => 0],
            ['name' => 'sofa', 'category' => 'home', 'featured' => true, 'discount' => 10],
            ['name' => 'lamp', 'category' => 'home', 'featured' => false, 'discount' => 60],
            ['name' => 'rug', 'category' => 'home', 'featured' => false, 'discount' => 50], // not > 50
            ['name' => 'pan', 'category' => 'kitchen', 'featured' => 1, 'discount' => 0],   // 1 is not true
        ]);

        $result = $this->q('products')
            ->where(['category' => 'electronics'])
            ->orWhere(['featured' => true])
            ->orWhere(['discount' => ['$gt' => 50]])
            ->get();

        $this->assertSame(['tv', 'sofa', 'lamp'], $this->names($result));
    }

    /**
     * whereIn(): membership
     */
    public function testWhereIn(): void
    {
        $results = $this->q()->whereIn('department', ['Engineering', 'Design'])->get();
        $this->assertSame(['John', 'Jane', 'Bob', 'Eve'], $this->names($results));

        $this->assertSame([], $this->q()->whereIn('department', [])->get());
    }

    /**
     * whereNotIn(): not a member (records without the field are kept)
     */
    public function testWhereNotIn(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoRole']);

        $results = $this->q()->whereNotIn('role', ['guest', 'user'])->get();

        $this->assertSame(['John', 'Jane', 'Alice', 'Eve', 'NoRole'], $this->names($results));
    }

    /**
     * whereNot(['role' => 'guest']): all users except guests
     */
    public function testWhereNot(): void
    {
        $results = $this->q()->whereNot(['role' => 'guest'])->get();

        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Diana', 'Eve'], $this->names($results));
    }

    /**
     * whereNot() rules: any listed field excludes; operators per field AND-ed; $ne/$nin/$exists false match a
     * missing field; a plain value never matches a missing field; several calls all apply
     */
    public function testWhereNotRules(): void
    {
        // "drops guests and everyone in HR" (here: Engineering)
        $this->assertSame(['Jane', 'Alice', 'Diana'], $this->names(
            $this->q()->whereNot(['role' => 'guest', 'department' => 'Engineering'])->get()
        ));

        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Kid', 'age' => 17],
            ['name' => 'Retired', 'age' => 70],
        ]);
        // "Everyone except working-age users"
        $this->assertSame(['Kid', 'Retired'], $this->names(
            $this->q()->whereNot(['age' => ['$gte' => 18, '$lt' => 65]])->get()
        ));
        // $ne matches a missing field: Kid and Retired (no role) are dropped too
        $this->assertSame(['John', 'Eve'], $this->names($this->q()->whereNot(['role' => ['$ne' => 'admin']])->get()));
        // $nin and $exists => false as well
        $this->assertSame(['John', 'Jane', 'Eve'], $this->names(
            $this->q()->whereNot(['role' => ['$nin' => ['admin', 'moderator']]])->get()
        ));
        $this->assertSame(['John', 'Jane', 'Alice', 'Diana', 'Eve'], $this->names(
            $this->q()->whereNot(['tags' => ['$exists' => false]])->get()
        ));
        // A plain value (even null) doesn't match a missing field: nothing is excluded
        $this->assertCount(9, $this->q()->whereNot(['phone' => null])->get());
        // Most operators don't match a missing field: Kid/Retired kept
        $this->assertSame(['Bob', 'Charlie', 'Kid', 'Retired'], $this->names(
            $this->q()->whereNot(['status' => ['$in' => ['active']]])->get()
        ));
        // Several calls all apply
        $this->assertSame(['John', 'Jane', 'Alice', 'Diana', 'Eve'], $this->names(
            $this->q()->whereNot(['role' => 'guest'])->whereNot(['status' => 'inactive'])->whereNot(['age' => ['$lt' => 18]])->whereNot(['age' => ['$gt' => 65]])->get()
        ));
    }

    /**
     * whereNot() checks its operators when it is called (where() checks them when the query runs)
     */
    public function testWhereNotValidatesOperatorsAtCall(): void
    {
        $cases = [
            [['age' => ['$gtee' => 1]], "unknown operator '\$gtee'"],
            [['age' => ['$in' => 1]], '$in needs an array'],
            [['age' => ['$nin' => 1]], '$nin needs an array'],
            [['name' => ['$regex' => '(']], 'invalid $regex pattern'],
            [['age' => ['$gt' => 1, 'x' => 2]], 'cannot mix operators'],
        ];
        foreach ($cases as [$filter, $message]) {
            try {
                $this->q()->whereNot($filter); // no get()
                $this->fail("whereNot() must throw at once: $message");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * like('email', 'gmail'): contains
     */
    public function testLikeMethod(): void
    {
        $results = $this->q()->like('email', 'gmail')->get();

        $this->assertSame(['John', 'Bob', 'Charlie', 'Eve'], $this->names($results));
        $this->assertSame(['Jane', 'Alice', 'Diana'], $this->names($this->q()->notLike('email', 'GMAIL')->get()));
    }

    /**
     * The like()/notLike() examples of QUERY.md; booleans, arrays and missing fields never match, numbers do
     */
    public function testLikeMethodDocExamples(): void
    {
        $this->noneDB->insert('people', [
            ['name' => 'Anna'], ['name' => 'Jackson'], ['name' => 'john doe'], ['name' => 'Maria'], ['name' => 'Hanna'],
        ]);
        $this->assertSame(['Anna'], $this->names($this->q('people')->like('name', '^A')->get()));
        $this->assertSame(['Jackson'], $this->names($this->q('people')->like('name', 'son$')->get()));
        $this->assertSame(['john doe'], $this->names($this->q('people')->like('name', 'john')->get()));
        $this->assertSame(['Anna', 'Hanna'], $this->names($this->q('people')->like('name', 'nna$')->get()));

        $this->noneDB->insert('links', [
            ['url' => 'http://a.com'], ['url' => 'https://b.com'], ['url' => 'xhttp://c.com'], ['url' => 'HTTP://D.COM'],
        ]);
        $this->assertSame(['http://a.com', 'HTTP://D.COM'], array_column($this->q('links')->like('url', '^http://')->get(), 'url'));

        $this->noneDB->insert('posts', [
            ['title' => 'SPAM offer'], ['title' => 'Hello'], ['title' => 'win: spam?'], ['title' => 'Spammy'], ['body' => 'no title'],
        ]);
        // records without the field never match like(), so notLike() keeps them
        $kept = $this->q('posts')->notLike('title', 'SPAM')->get();
        $this->assertSame([1, 4], array_column($kept, 'key'));

        $this->noneDB->insert('codes', [
            ['code' => 12345], ['code' => '0234'], ['code' => true], ['code' => [234]], ['code' => 1234.5], ['other' => 1],
        ]);
        $this->assertSame([0, 1, 4], array_column($this->q('codes')->like('code', '234')->get(), 'key'));
        $this->assertSame([2, 3, 5], array_column($this->q('codes')->notLike('code', '234')->get(), 'key'));
        $this->assertSame([], $this->q('codes')->like('code', '1')->where(['code' => true])->get());
    }

    /**
     * between(): inclusive range
     */
    public function testBetweenMethod(): void
    {
        $this->assertSame(['Jane', 'Bob', 'Alice'], $this->names($this->q()->between('salary', 50000, 70000)->get()));
        // inclusive at both ends: John 25, Bob 35
        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Eve'], $this->names($this->q()->between('age', 25, 35)->get()));
        $this->assertSame([], $this->q()->between('age', 36, 44)->get());
    }

    /**
     * notBetween(): the complement, including records without the field or with a value that can't be compared
     */
    public function testNotBetweenMethod(): void
    {
        $this->assertSame(['Charlie', 'Diana'], $this->names($this->q()->notBetween('age', 25, 35)->get()));

        $this->noneDB->insert($this->testDbName, [
            ['name' => 'NoAge'],
            ['name' => 'NullAge', 'age' => null],
            ['name' => 'BoolAge', 'age' => true],
            ['name' => 'TextAge', 'age' => 'old'],
            ['name' => 'ArrAge', 'age' => [30]],
            ['name' => 'NumStr', 'age' => '30'],
        ]);
        $this->assertSame(
            ['Charlie', 'Diana', 'NoAge', 'NullAge', 'BoolAge', 'TextAge', 'ArrAge'],
            $this->names($this->q()->notBetween('age', 25, 35)->get())
        );
        $this->assertSame(
            ['John', 'Jane', 'Bob', 'Alice', 'Eve', 'NumStr'],
            $this->names($this->q()->between('age', 25, 35)->get())
        );
    }

    /**
     * search('gmail', ['email'])
     */
    public function testSearchMethod(): void
    {
        $results = $this->q()->search('gmail', ['email'])->get();

        $this->assertSame(['John', 'Bob', 'Charlie', 'Eve'], $this->names($results));
        $this->assertSame([], $this->q()->search('gmail', ['name'])->get());
    }

    /**
     * search(): whole term as a substring of one scalar field, case-insensitive; no word splitting; without a field
     * list every field except key
     */
    public function testSearchDocRules(): void
    {
        $this->noneDB->insert('articles', [
            ['title' => 'PHP Tutorial', 'content' => 'Learn step by step', 'tags' => ['php']],
            ['title' => 'Tutorial on php', 'content' => 'basics'],
            ['title' => 'Advanced', 'content' => 'A PHP tutorial for experts'],
            ['title' => 'Cooking', 'content' => 'no code', 'tags' => ['php tutorial']],
        ]);

        $this->assertSame([0, 1, 2], array_column($this->q('articles')->search('php')->get(), 'key'));
        $this->assertSame([0, 2], array_column($this->q('articles')->search('php tutorial', ['title', 'content', 'tags'])->get(), 'key'));
        // only the given fields
        $this->assertSame([0], array_column($this->q('articles')->search('php tutorial', ['title'])->get(), 'key'));
        // key is not searched: "3" appears in no value
        $this->assertSame([], $this->q('articles')->search('3')->get());
    }

    /**
     * search() folds Unicode case like like(): İzmir, IZMIR and izmir; straße and STRASSE
     */
    public function testSearchUnicodeFolding(): void
    {
        if (!function_exists('mb_convert_case')) {
            $this->markTestSkipped('needs the mbstring extension (without it only ASCII letters are folded)');
        }
        $this->noneDB->insert('cities', [['city' => 'İzmir'], ['city' => 'IZMIR'], ['city' => 'izmir'], ['city' => 'straße'], ['city' => 'Ankara']]);

        $this->assertSame([0, 1, 2], array_column($this->q('cities')->search('izmir')->get(), 'key'));
        $this->assertSame([0, 1, 2], array_column($this->q('cities')->search('İZMİR')->get(), 'key'));
        $this->assertSame([3], array_column($this->q('cities')->search('STRASSE')->get(), 'key'));
    }

    // ========== SORTING & PAGINATION ==========

    public function testSortAsc(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Jane', 'John'], $this->names($this->q()->sort('name', 'asc')->get()));
    }

    public function testSortDesc(): void
    {
        $this->assertSame(['Eve', 'John', 'Jane', 'Alice', 'Bob', 'Diana', 'Charlie'], $this->names($this->q()->sort('salary', 'desc')->get()));
    }

    /**
     * sort('name') defaults to ascending; orderBy() is an alias
     */
    public function testSortDefaultOrderAndOrderByAlias(): void
    {
        $expected = ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Jane', 'John'];
        $this->assertSame($expected, $this->names($this->q()->sort('name')->get()));
        $this->assertSame($expected, $this->names($this->q()->orderBy('name')->get()));
        $this->assertSame(array_reverse($expected), $this->names($this->q()->orderBy('name', 'desc')->get()));
    }

    /**
     * "Several keys: category first, then price"
     */
    public function testSortSeveralKeys(): void
    {
        $result = $this->q()->sort('department')->sort('salary', 'desc')->get();

        // Design: Jane; Engineering: Eve 80000, John 75000, Bob 55000; Guest; Marketing; Support
        $this->assertSame(['Jane', 'Eve', 'John', 'Bob', 'Charlie', 'Alice', 'Diana'], $this->names($result));
    }

    /**
     * The total sort order: missing/null < booleans < numbers < numeric strings (by number, trailing whitespace allowed)
     * < other strings (byte order) < arrays; stable (ties keep key order, also for desc); min()/max() use it too
     */
    public function testSortTotalOrder(): void
    {
        $values = [
            ['missing', null], ['a', 'a'], ['null', null], ['3', 3], ['"10"', '10'], ['true', true], ['[1]', [1]],
            ['"9"', '9'], ['false', false], ['2.5', 2.5], ['Z', 'Z'], ['"1e3"', '1e3'], ['"1a"', '1a'], ['-1', -1],
            ['"7 "', '7 '], ['3.0', 3.0],
        ];
        $records = [];
        foreach ($values as [$label, $value]) {
            $records[] = $label === 'missing' ? ['name' => $label] : ['name' => $label, 'v' => $value];
        }
        $this->noneDB->insert('mixed', $records);

        $asc = ['missing', 'null', 'false', 'true', '-1', '2.5', '3', '3.0', '"7 "', '"9"', '"10"', '"1e3"', '"1a"', 'Z', 'a', '[1]'];
        $desc = ['[1]', 'a', 'Z', '"1a"', '"1e3"', '"10"', '"9"', '"7 "', '3', '3.0', '2.5', '-1', 'true', 'false', 'missing', 'null'];
        $this->assertSame($asc, $this->names($this->q('mixed')->sort('v')->get()));
        $this->assertSame($desc, $this->names($this->q('mixed')->sort('v', 'desc')->get()));
        // top-k (offset + limit <= 1000) gives the same rows as the full sort
        $this->assertSame(array_slice($asc, 3, 5), $this->names($this->q('mixed')->sort('v')->offset(3)->limit(5)->get()));
        $this->assertSame(array_slice($desc, 0, 4), $this->names($this->q('mixed')->sort('v', 'desc')->limit(4)->get()));
        // noneDB::sort() on a result uses the same order
        $this->assertSame($asc, $this->names($this->noneDB->sort($this->noneDB->find('mixed'), 'v')));

        // min()/max() use the order above (null/missing are not values)
        $this->assertSame(false, $this->q('mixed')->min('v'));
        $this->assertSame([1], $this->q('mixed')->max('v'));
        $this->noneDB->insert('mm', [['p' => 50], ['p' => '10']]);
        $this->assertSame('10', $this->q('mm')->max('p'));
        $this->assertSame(50, $this->q('mm')->min('p'));
        $this->assertSame('10', $this->noneDB->max('mm', 'p'));
        $this->assertSame(50, $this->noneDB->min('mm', 'p'));
    }

    public function testLimit(): void
    {
        $this->assertSame(['John', 'Jane', 'Bob'], $this->names($this->q()->limit(3)->get()));
    }

    public function testOffset(): void
    {
        $this->assertSame(['Bob', 'Alice', 'Charlie', 'Diana', 'Eve'], $this->names($this->q()->offset(2)->get()));
        $this->assertSame(['Bob', 'Alice', 'Charlie', 'Diana', 'Eve'], $this->names($this->q()->skip(2)->get()));
        $this->assertSame([], $this->q()->offset(7)->get());
    }

    /**
     * limit + offset, and the paginate() helper of QUERY.md
     */
    public function testPagination(): void
    {
        $this->assertSame(['Bob', 'Alice'], $this->names($this->q()->limit(2)->offset(2)->get()));

        $paginate = function ($db, $dbname, $page, $perPage = 20) {
            return $db->query($dbname)->limit($perPage)->offset(($page - 1) * $perPage)->get();
        };
        $this->assertSame(['John', 'Jane', 'Bob'], $this->names($paginate($this->noneDB, $this->testDbName, 1, 3)));
        $this->assertSame(['Alice', 'Charlie', 'Diana'], $this->names($paginate($this->noneDB, $this->testDbName, 2, 3)));
        $this->assertSame(['Eve'], $this->names($paginate($this->noneDB, $this->testDbName, 3, 3)));
        $this->assertSame([], $paginate($this->noneDB, $this->testDbName, 4, 3));
        $this->assertCount(7, $paginate($this->noneDB, $this->testDbName, 1));
    }

    /**
     * limit(0) returns nothing: first() is null, exists() is false
     */
    public function testLimitZero(): void
    {
        $this->assertSame([], $this->q()->limit(0)->get());
        $this->assertNull($this->q()->limit(0)->first());
        $this->assertFalse($this->q()->limit(0)->exists());
        $this->assertNull($this->q()->limit(0)->last());
        $this->assertSame(0, $this->q()->limit(0)->count());
    }

    /**
     * select(['name', 'email']): only those fields and key
     */
    public function testSelect(): void
    {
        $results = $this->q()->select(['name', 'email'])->get();

        $expected = [];
        foreach ($this->people as $key => $p) {
            $expected[] = ['name' => $p['name'], 'email' => $p['email'], 'key' => $key];
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * except(['salary', 'age']): every other field
     */
    public function testExcept(): void
    {
        $results = $this->q()->except(['salary', 'age'])->get();

        $expected = [];
        foreach ($this->people as $key => $p) {
            unset($p['salary'], $p['age']);
            $expected[] = $p + ['key' => $key];
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * select()/except() only shape the result: filters and sort see all fields; key is always kept
     */
    public function testSelectExceptOnlyShapeTheResult(): void
    {
        $rows = $this->q()->select(['name'])->where(['salary' => ['$gt' => 60000]])->sort('salary', 'desc')->get();
        $this->assertSame([['name' => 'Eve', 'key' => 6], ['name' => 'John', 'key' => 0], ['name' => 'Jane', 'key' => 1]], $rows);

        // "key is always kept", also when select() names only fields the records don't have
        $this->assertSame([['key' => 0], ['key' => 1]], $this->q()->select(['nonexistent'])->limit(2)->get());
    }

    /**
     * QUERY.md select()/except(): "`key` is always kept" - also when except() names it
     */
    public function testExceptRemovesKeyOnlyWhenListed(): void
    {
        // QUERY.md select() / except(): select() always keeps key, except() removes it only when listed
        $rows = $this->q()->except(['key', 'name'])->limit(2)->get();
        $expected = [];
        foreach ([0, 1] as $key) {
            $p = $this->people[$key];
            unset($p['name']);
            $expected[] = $p;
        }
        $this->assertSameRecords($expected, $rows);

        $rows = $this->q()->except(['name'])->limit(2)->get();
        foreach ($expected as $i => $p) {
            $expected[$i] = $p + ['key' => $i];
        }
        $this->assertSameRecords($expected, $rows, 'key stays when it is not listed');
    }

    // ========== AGGREGATION ==========

    /**
     * count(): limit()/offset() apply; with groupBy() it counts the groups
     */
    public function testCount(): void
    {
        $this->assertSame(7, $this->q()->count());
        $this->assertSame(5, $this->q()->where(['status' => 'active'])->count());

        // "offset(2)->count() on 6 matches is 4"
        $this->assertSame(6, $this->q()->whereNot(['role' => 'guest'])->count());
        $this->assertSame(4, $this->q()->whereNot(['role' => 'guest'])->offset(2)->count());
        $this->assertSame(3, $this->q()->whereNot(['role' => 'guest'])->limit(3)->count());
        $this->assertSame(2, $this->q()->whereNot(['role' => 'guest'])->offset(4)->limit(10)->count());
        $this->assertSame(5, $this->q()->offset(2)->count());
        // groups: Engineering, Design, Marketing, Guest, Support
        $this->assertSame(5, $this->q()->groupBy('department')->count());
        $this->assertSame(1, $this->q()->groupBy('department')->having('count', '>', 1)->count());
    }

    public function testSum(): void
    {
        // 75000 + 65000 + 55000 + 60000 + 0 + 45000 + 80000
        $this->assertSame(380000.0, $this->q()->sum('salary'));
        $this->assertSame(280000.0, $this->q()->where(['status' => 'active'])->where(['department' => ['$ne' => 'Support']])->sum('salary'));
        $this->assertSame(0.0, $this->q()->where(['role' => 'nobody'])->sum('salary'));
    }

    /**
     * The sum()/avg()/min()/max() examples of QUERY.md
     */
    public function testAggregationDocExamples(): void
    {
        $this->noneDB->insert('orders', [
            ['status' => 'completed', 'total' => 100],
            ['status' => 'pending', 'total' => 40],
            ['status' => 'completed', 'total' => 20.5],
        ]);
        $this->assertSame(120.5, $this->q('orders')->where(['status' => 'completed'])->sum('total'));

        $this->noneDB->insert('products', [
            ['category' => 'electronics', 'rating' => 4, 'price' => 300],
            ['category' => 'books', 'rating' => 2, 'price' => 15],
            ['category' => 'electronics', 'rating' => 5, 'price' => 1200],
        ]);
        $this->assertSame(4.5, $this->q('products')->where(['category' => 'electronics'])->avg('rating'));
        $this->assertSame(15, $this->q('products')->min('price'));
        $this->assertSame(1200, $this->q('products')->max('price'));
    }

    public function testAvg(): void
    {
        // (25 + 30 + 35 + 28 + 45 + 22 + 33) / 7
        $this->assertSame(218 / 7, $this->q()->avg('age'));
        $this->assertSame(0.0, $this->q()->where(['role' => 'nobody'])->avg('age'));
    }

    public function testMin(): void
    {
        $this->assertSame(22, $this->q()->min('age'));
        $this->assertNull($this->q()->where(['role' => 'nobody'])->min('age'));
    }

    public function testMax(): void
    {
        $this->assertSame(80000, $this->q()->max('salary'));
        $this->assertNull($this->q()->max('nonexistent'));
    }

    /**
     * distinct(): order of first appearance, null/missing left out, typed identity (-0.0 and 0.0 are one)
     */
    public function testDistinct(): void
    {
        $this->noneDB->insert('users', [
            ['city' => 'Istanbul', 'active' => true],
            ['city' => 'Ankara', 'active' => true],
            ['city' => 'Istanbul', 'active' => true],
            ['city' => 'Izmir', 'active' => false],
            ['active' => true],
            ['city' => null, 'active' => true],
            ['city' => 'Bursa', 'active' => true],
        ]);
        $this->assertSame(['Istanbul', 'Ankara', 'Bursa'], $this->q('users')->where(['active' => true])->distinct('city'));
        $this->assertSame(['Istanbul', 'Ankara', 'Izmir', 'Bursa'], $this->noneDB->distinct('users', 'city'));

        $this->noneDB->insert('typed', [['v' => 1], ['v' => 1.0], ['v' => '1'], ['v' => 1], ['v' => -0.0], ['v' => 0.0]]);
        $distinct = $this->q('typed')->distinct('v');
        $this->assertCount(4, $distinct);
        $this->assertSame(1, $distinct[0]);
        $this->assertSame(1.0, $distinct[1]);
        $this->assertSame('1', $distinct[2]);
        $this->assertSame(0.0, $distinct[3]);
        $this->assertSame([], $this->q('typed')->where(['v' => 'none'])->distinct('v'));
    }

    /**
     * Aggregates work on the matching records before select()/except()
     */
    public function testAggregatesIgnoreSelectAndExcept(): void
    {
        $this->assertSame(380000.0, $this->q()->select(['name'])->sum('salary'));
        $this->assertSame(218 / 7, $this->q()->except(['age'])->avg('age'));
        $this->assertSame(22, $this->q()->select(['name'])->min('age'));
        $this->assertSame(80000, $this->q()->except(['salary'])->max('salary'));
        $this->assertSame(['Engineering', 'Design', 'Marketing', 'Guest', 'Support'], $this->q()->select(['name'])->distinct('department'));
        $this->assertSame(7, $this->q()->select(['name'])->count());
    }

    // ========== JOINS ==========

    /**
     * join(): the matching record under the foreign database's name (or the alias), null if none; the attached
     * record replaces a field of the same name in the result only
     */
    public function testJoinDocExamples(): void
    {
        $this->noneDB->insert('users', [['name' => 'Ann'], ['name' => 'Ben'], ['name' => 'Cid']]);
        $this->noneDB->insert('orders', [
            ['item' => 'a', 'user_id' => 0],
            ['item' => 'b', 'user_id' => 2, 'users' => 'stored value'],
            ['item' => 'c', 'user_id' => 9],
        ]);

        $orders = $this->q('orders')->join('users', 'user_id', 'key')->get();
        $this->assertCount(3, $orders);
        $this->assertSame(['name' => 'Ann', 'key' => 0], $orders[0]['users']);
        $this->assertSame(['name' => 'Cid', 'key' => 2], $orders[1]['users']);
        $this->assertNull($orders[2]['users']);
        $this->assertSame(['Ann', 'Cid', '-'], array_map(function ($o) { return $o['users']['name'] ?? '-'; }, $orders));
        // stored records are never changed
        $this->assertSame('stored value', $this->noneDB->find('orders', ['key' => 1])[0]['users']);
        $this->assertArrayNotHasKey('users', $this->noneDB->find('orders', ['key' => 0])[0]);

        // alias
        $buyers = $this->q('orders')->join('users', 'user_id', 'key', 'buyer')->get();
        $this->assertSame(['Ann', 'Cid', null], array_map(function ($o) { return $o['buyer']['name'] ?? null; }, $buyers));
        $this->assertArrayNotHasKey('users', $buyers[0]);
        $this->assertSame('stored value', $buyers[1]['users']);
    }

    /**
     * join() matching rules of QUERY.md
     */
    public function testJoinMatchingRules(): void
    {
        $this->noneDB->insert('users', [['name' => 'u0'], ['name' => 'u1'], ['name' => 'u2'], ['name' => 'u3'], ['name' => 'u4'], ['name' => 'u5'], ['name' => 'u6'], ['name' => 'u7']]);
        $this->noneDB->insert('orders', [
            ['o' => 'int', 'user_id' => 5],
            ['o' => 'string', 'user_id' => '5'],
            ['o' => 'float', 'user_id' => 5.0],
            ['o' => 'half', 'user_id' => 1.5],
            ['o' => 'zeros', 'user_id' => '007'],
            ['o' => 'true', 'user_id' => true],
            ['o' => 'null', 'user_id' => null],
            ['o' => 'array', 'user_id' => [5]],
            ['o' => 'missing'],
        ]);
        $joined = [];
        foreach ($this->q('orders')->join('users', 'user_id', 'key')->get() as $row) {
            $joined[$row['o']] = $row['users']['name'] ?? null;
        }
        $this->assertSame([
            'int' => 'u5', 'string' => 'u5', 'float' => 'u5', // 5, "5" and 5.0 match each other
            'half' => null,  // other floats only the same float
            'zeros' => null, // "007" doesn't match 7
            'true' => null,  // true matches only true
            'null' => null, 'array' => null, 'missing' => null,
        ], $joined);

        // On another field: floats, booleans, strings exactly; last duplicate wins
        $this->noneDB->insert('codes', [
            ['code' => 1, 'label' => 'one'],
            ['code' => 1.5, 'label' => 'one and a half'],
            ['code' => true, 'label' => 'yes'],
            ['code' => false, 'label' => 'no'],
            ['code' => '007', 'label' => 'agent'],
            ['code' => 7, 'label' => 'seven'],
            ['code' => 'x', 'label' => 'first x'],
            ['code' => 'x', 'label' => 'last x'],
            ['code' => null, 'label' => 'null'],
        ]);
        $this->noneDB->insert('items', [
            ['c' => 1.0], ['c' => '1'], ['c' => 1.5], ['c' => true], ['c' => false], ['c' => '007'], ['c' => 7.0], ['c' => 'x'], ['c' => null], ['c' => 2],
        ]);
        $labels = array_map(function ($r) { return $r['codes']['label'] ?? null; }, $this->q('items')->join('codes', 'c', 'code')->get());
        $this->assertSame(['one', 'one', 'one and a half', 'yes', 'no', 'agent', 'seven', 'last x', null, null], $labels);
    }

    /**
     * Joining a database that doesn't exist attaches null and creates nothing
     */
    public function testJoinMissingDatabaseAttachesNullAndCreatesNothing(): void
    {
        $byKey = $this->q()->join('nope', 'age', 'key')->get();
        $byField = $this->q()->join('nope2', 'name', 'owner')->get();

        $this->assertCount(7, $byKey);
        $this->assertSame(array_fill(0, 7, null), array_column($byKey, 'nope'));
        $this->assertSame(array_fill(0, 7, null), array_map(function ($r) { return $r['nope2']; }, $byField));
        $this->assertFalse($this->noneDB->getDBs('nope'));
        $this->assertFalse($this->noneDB->getDBs('nope2'));
        $this->assertNotContains('nope', $this->noneDB->getDBs(false));
    }

    /**
     * "Multiple Joins" example
     */
    public function testMultipleJoinsExample(): void
    {
        $this->noneDB->insert('orders', [['no' => 'A-1'], ['no' => 'A-2']]);
        $this->noneDB->insert('products', [['title' => 'Pen'], ['title' => 'Ink']]);
        $this->noneDB->insert('order items', [
            ['order_id' => 1, 'product_id' => 0, 'qty' => 2],
            ['order_id' => 0, 'product_id' => 1, 'qty' => 1],
            ['order_id' => 5, 'product_id' => 1, 'qty' => 3],
        ]);

        $data = $this->q('order items')
            ->join('orders', 'order_id', 'key')
            ->join('products', 'product_id', 'key')
            ->get();

        $this->assertSame(['A-2', 'A-1', null], array_map(function ($i) { return $i['orders']['no'] ?? null; }, $data));
        $this->assertSame(['Pen', 'Ink', 'Ink'], array_map(function ($i) { return $i['products']['title'] ?? null; }, $data));
    }

    // ========== GROUPING & HAVING ==========

    /**
     * groupBy('status'): ['_group', '_items', '_count'] per value
     */
    public function testGroupByDocExample(): void
    {
        $groups = $this->q()->groupBy('status')->get();

        $byValue = [];
        foreach ($groups as $group) {
            $this->assertSame(['_count', '_group', '_items'], (function ($g) { $k = array_keys($g); sort($k); return $k; })($group));
            $byValue[$group['_group']] = [$group['_count'], array_column($group['_items'], 'name')];
        }
        ksort($byValue);
        $this->assertSame([
            'active' => [5, ['John', 'Jane', 'Alice', 'Diana', 'Eve']],
            'inactive' => [1, ['Bob']],
            'pending' => [1, ['Charlie']],
        ], $byValue);
        // items are the full records
        foreach ($groups as $group) {
            if ($group['_group'] === 'inactive') {
                $this->assertSameRecords([$this->person(2)], $group['_items']);
            }
        }
    }

    /**
     * Group values keep their type; missing fields group under null; -0.0 and 0.0 are one group
     */
    public function testGroupByTyping(): void
    {
        $this->noneDB->insert('typed', [
            ['v' => 1], ['v' => '1'], ['v' => 1.5], ['v' => 1], ['x' => 'no v'], ['v' => null], ['v' => -0.0], ['v' => 0.0],
        ]);

        $counts = [];
        foreach ($this->q('typed')->groupBy('v')->get() as $group) {
            $counts[] = [gettype($group['_group']) . ':' . var_export($group['_group'], true), $group['_count'], array_column($group['_items'], 'key')];
        }
        usort($counts, function ($a, $b) { return strcmp($a[0], $b[0]); });
        $this->assertSame([
            ['NULL:NULL', 2, [4, 5]],
            ['double:-0.0', 2, [6, 7]],
            ['double:1.5', 1, [2]],
            ['integer:1', 2, [0, 3]],
            ["string:'1'", 1, [1]],
        ], $counts);
    }

    /**
     * groupBy()->select(['_group', 'total']): the QUERY.md example (_count dropped, items projected)
     */
    public function testGroupBySelectDocExample(): void
    {
        $this->noneDB->insert('orders', [
            ['status' => 'pending', 'total' => 12.5, 'customer' => 'a'],
            ['status' => 'completed', 'total' => 20, 'customer' => 'b'],
            ['status' => 'pending', 'total' => 7.25, 'customer' => 'c'],
        ]);

        $rows = $this->q('orders')->groupBy('status')->select(['_group', 'total'])->get();
        usort($rows, function ($a, $b) { return strcmp($a['_group'], $b['_group']); });

        $this->assertSame([
            ['_group' => 'completed', '_items' => [['total' => 20, 'key' => 1]]],
            ['_group' => 'pending', '_items' => [['total' => 12.5, 'key' => 0], ['total' => 7.25, 'key' => 2]]],
        ], array_map(function ($row) { ksort($row); return $row; }, $rows));

        // _group/_count/_items select or drop parts of each row; other fields apply to the items
        $counts = $this->q('orders')->groupBy('status')->select(['_group', '_count'])->get();
        foreach ($counts as $row) {
            $this->assertSame(['_count', '_group'], (function ($r) { $k = array_keys($r); sort($k); return $k; })($row));
        }
        $noItems = $this->q('orders')->groupBy('status')->except(['_items'])->get();
        foreach ($noItems as $row) {
            $this->assertArrayNotHasKey('_items', $row);
            $this->assertArrayHasKey('_group', $row);
            $this->assertArrayHasKey('_count', $row);
        }
        $itemsExcept = $this->q('orders')->groupBy('status')->except(['customer', 'status'])->get();
        foreach ($itemsExcept as $row) {
            $this->assertArrayHasKey('_count', $row);
            foreach ($row['_items'] as $item) {
                $this->assertSame(['key', 'total'], (function ($r) { $k = array_keys($r); sort($k); return $k; })($item));
            }
        }
    }

    /**
     * having(): the QUERY.md examples, every aggregate and operator; ignored without groupBy() or when unknown
     */
    public function testHavingDocExamples(): void
    {
        $orders = [];
        foreach (['c1' => 6, 'c2' => 5, 'c3' => 1] as $customer => $n) {
            for ($i = 0; $i < $n; $i++) {
                $orders[] = ['customer_id' => $customer, 'total' => 10];
            }
        }
        $this->noneDB->insert('orders', $orders);
        $frequent = $this->q('orders')->groupBy('customer_id')->having('count', '>', 5)->get();
        $this->assertSame(['c1'], array_column($frequent, '_group'));

        $this->noneDB->insert('products', [
            ['category' => 'A', 'rating' => 4], ['category' => 'A', 'rating' => 5],
            ['category' => 'B', 'rating' => 3], ['category' => 'B', 'rating' => 4],
            ['category' => 'C', 'rating' => 4],
        ]);
        $groups = function ($query) {
            $values = array_column($query->get(), '_group');
            sort($values);
            return $values;
        };
        $p = function () { return $this->q('products')->groupBy('category'); };

        $this->assertSame(['A', 'C'], $groups($p()->having('avg:rating', '>=', 4)));      // A 4.5, B 3.5, C 4
        $this->assertSame(['B'], $groups($p()->having('avg:rating', '<', 4)));
        $this->assertSame(['A', 'B'], $groups($p()->having('sum:rating', '>', 4)));       // 9, 7, 4
        $this->assertSame(['C'], $groups($p()->having('sum:rating', '<=', 4)));
        $this->assertSame(['A', 'C'], $groups($p()->having('min:rating', '=', 4)));       // 4, 3, 4
        $this->assertSame(['B'], $groups($p()->having('min:rating', '!=', 4)));
        $this->assertSame(['A'], $groups($p()->having('max:rating', '>', 4)));            // 5, 4, 4
        $this->assertSame(['A', 'B'], $groups($p()->having('count', '=', 2)));
        $this->assertSame(['A'], $groups($p()->having('count', '>=', 2)->having('max:rating', '>=', 5))); // all apply
        // unknown aggregate or operator: ignored
        $this->assertSame(['A', 'B', 'C'], $groups($p()->having('median:rating', '>', 100)));
        $this->assertSame(['A', 'B', 'C'], $groups($p()->having('count', '~', 100)));
        // without groupBy(): ignored
        $this->assertCount(5, $this->q('products')->having('count', '>', 100)->get());
    }

    // ========== TERMINAL METHODS ==========

    public function testGet(): void
    {
        $results = $this->q()->where(['status' => 'active'])->get();

        $this->assertSameRecords([$this->person(0), $this->person(1), $this->person(3), $this->person(5), $this->person(6)], $results);
        $this->assertSame([0, 1, 2, 3, 4], array_keys($results), 'get() returns a list');
    }

    public function testFirst(): void
    {
        $first = $this->q()->where(['email' => 'john@gmail.com'])->first();
        $this->assertSameRecords([$this->person(0)], [$first]);

        // "Use first() instead of get()[0]": the same record
        $this->assertSame($this->q()->where(['role' => 'user'])->get()[0], $this->q()->where(['role' => 'user'])->first());
        $this->assertSame('Diana', $this->q()->where(['role' => 'user'])->sort('age')->first()['name']);
        $this->assertNull($this->q()->where(['email' => 'nobody@example.com'])->first());
    }

    /**
     * last(): the last record of the result after sort(), offset() and limit()
     */
    public function testLast(): void
    {
        $newest = $this->q()->sort('created_at')->last();
        $this->assertSame('Charlie', $newest['name']);

        $this->assertSame('Eve', $this->q()->last()['name']);
        $this->assertSame('Jane', $this->q()->sort('created_at')->limit(4)->last()['name']);   // Eve, Diana, John, Jane
        $this->assertSame('Bob', $this->q()->sort('created_at')->offset(1)->limit(4)->last()['name']);
        $this->assertNull($this->q()->where(['role' => 'nobody'])->last());
    }

    public function testExists(): void
    {
        $this->assertTrue($this->q()->where(['role' => 'admin'])->exists());
        $this->assertFalse($this->q()->where(['role' => 'superadmin'])->exists());
        $this->assertTrue($this->q()->where(['role' => 'admin'])->offset(1)->exists());
        $this->assertFalse($this->q()->where(['role' => 'admin'])->offset(2)->exists());
    }

    /**
     * update(): ['n' => updated], stored records changed, others untouched
     */
    public function testUpdate(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'UpdateTest', 'status' => 'pending', 'age' => 25]);

        $result = $this->q()->where(['name' => 'UpdateTest'])->update(['status' => 'active']);
        $this->assertSame(['n' => 1], $result);
        $this->assertSame([['name' => 'UpdateTest', 'status' => 'active', 'age' => 25, 'key' => 7]],
            $this->noneDB->find($this->testDbName, ['key' => 7]));

        // QUERY.md example: inactive -> archived
        $this->assertSame(['n' => 1], $this->q()->where(['status' => 'inactive'])->update(['status' => 'archived']));
        $this->assertSame(['Bob'], $this->names($this->noneDB->find($this->testDbName, ['status' => 'archived'])));
        $this->assertSame(['n' => 0], $this->q()->where(['status' => 'inactive'])->update(['status' => 'archived']));
        // the 5 active fixture users and UpdateTest
        $this->assertSame(6, $this->noneDB->count($this->testDbName, ['status' => 'active']));
    }

    /**
     * sort(), offset() and limit() choose which records are written; select(), except() and join() don't matter
     */
    public function testWritesChooseRecordsBySortOffsetLimit(): void
    {
        // "the 10 oldest" (here 2): Eve (-20d), Diana (-15d)
        $this->assertSame(['n' => 2], $this->q()->sort('created_at')->limit(2)->update(['status' => 'archived']));
        $this->assertSame(['Diana', 'Eve'], $this->names($this->noneDB->find($this->testDbName, ['status' => 'archived'])));

        // offset: the 2nd and 3rd highest salaries (John 75000, Jane 65000)
        $this->assertSame(['n' => 2], $this->q()->sort('salary', 'desc')->offset(1)->limit(2)->update(['bonus' => true]));
        $this->assertSame(['John', 'Jane'], $this->names($this->noneDB->find($this->testDbName, ['bonus' => true])));

        // select/except/join change nothing about what is written
        $this->assertSame(['n' => 2], $this->q()->where(['role' => 'admin'])->select(['name'])->except(['email'])->join('nope', 'age', 'key')->update(['flag' => 1]));
        $this->assertSameRecords([
            array_merge($this->person(0), ['bonus' => true, 'flag' => 1]),
            array_merge($this->person(6), ['status' => 'archived', 'flag' => 1]),
        ], $this->noneDB->find($this->testDbName, ['flag' => 1]));

        // delete: the youngest record
        $this->assertSame(['n' => 1], $this->q()->sort('age')->limit(1)->delete());
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['name' => 'Diana']));
        $this->assertSame(6, $this->noneDB->count($this->testDbName));
    }

    /**
     * update(), delete() and removeFields() can't be combined with groupBy(): LogicException, nothing written
     */
    public function testWritesWithGroupByThrowLogicException(): void
    {
        $before = $this->noneDB->find($this->testDbName);
        $writes = [
            'update' => function () { return $this->q()->groupBy('role')->update(['x' => 1]); },
            'delete' => function () { return $this->q()->groupBy('role')->delete(); },
            'removeFields' => function () { return $this->q()->groupBy('role')->removeFields(['age']); },
        ];
        foreach ($writes as $name => $write) {
            try {
                $write();
                $this->fail("$name() with groupBy() must throw");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('cannot be combined with groupBy()', $e->getMessage());
            }
        }
        $this->assertSame($before, $this->noneDB->find($this->testDbName));
    }

    /**
     * delete(): ['n' => deleted]
     */
    public function testDelete(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'DeleteTest', 'status' => 'temp']);

        $this->assertSame(['n' => 1], $this->q()->where(['name' => 'DeleteTest'])->delete());
        $this->assertFalse($this->q()->where(['name' => 'DeleteTest'])->exists());
        $this->assertSame(7, $this->noneDB->count($this->testDbName));

        // QUERY.md example: logs older than 30 days
        $now = $this->now;
        $this->noneDB->insert('logs', [
            ['msg' => 'old', 'created_at' => $now - 40 * 86400],
            ['msg' => 'new', 'created_at' => $now - 86400],
            ['msg' => 'older', 'created_at' => $now - 400 * 86400],
        ]);
        $this->assertSame(['n' => 2], $this->q('logs')->where(['created_at' => ['$lt' => $now - 30 * 86400]])->delete());
        $this->assertSame(['new'], array_column($this->noneDB->find('logs'), 'msg'));
    }

    /**
     * removeFields(): the QUERY.md example: only fields that existed are listed; stored records change
     */
    public function testRemoveFieldsDocExample(): void
    {
        $this->noneDB->insert('users', [
            ['name' => 'a', 'legacy' => true, 'old_password' => 'x'],
            ['name' => 'b', 'legacy' => true, 'old_password' => 'y'],
            ['name' => 'c', 'legacy' => false, 'old_password' => 'z'],
            ['name' => 'd', 'legacy' => true],
        ]);

        $result = $this->q('users')->where(['legacy' => true])->removeFields(['old_password', 'tmp']);

        $this->assertSame(['n' => 2, 'fields_removed' => ['old_password']], $result);
        $this->assertSame([
            ['name' => 'a', 'legacy' => true, 'key' => 0],
            ['name' => 'b', 'legacy' => true, 'key' => 1],
            ['name' => 'c', 'legacy' => false, 'old_password' => 'z', 'key' => 2],
            ['name' => 'd', 'legacy' => true, 'key' => 3],
        ], array_map(function ($r) { $k = $r['key']; unset($r['key']); return $r + ['key' => $k]; }, $this->noneDB->find('users')));

        // never affected by select()/except()/join(): the stored record keeps its other fields
        $this->assertSame(['n' => 1, 'fields_removed' => ['legacy']],
            $this->q('users')->where(['name' => 'a'])->select(['name'])->except(['legacy'])->join('nope', 'x', 'key')->removeFields(['legacy']));
        $this->assertSame([['name' => 'a', 'key' => 0]], $this->noneDB->find('users', ['key' => 0]));
    }

    // ========== QUERY PLANNING & EXPLAIN ==========

    /**
     * The explain() example of QUERY.md: 2000 users in 4 shards of 500, one city per shard, city and age indexed
     */
    public function testExplainDocExample(): void
    {
        $db = new \noneDB([
            'secretKey' => 'test_secret_key_for_unit_tests',
            'dbDir' => $this->testDbDir,
            'shardSize' => 500,
        ]);
        $cities = ['Istanbul', 'Ankara', 'Izmir', 'Bursa'];
        $records = [];
        for ($i = 0; $i < 2000; $i++) {
            // 380 of the 500 users of each city are 30 or older
            $records[] = ['city' => $cities[intdiv($i, 500)], 'age' => ($i % 500) < 380 ? 30 + $i % 40 : 20 + $i % 10];
        }
        $this->assertSame(['n' => 2000], $db->insert('users', $records));
        $this->assertTrue($db->createFieldIndex('users', 'city')['success']);
        $this->assertTrue($db->createFieldIndex('users', 'age')['success']);

        $plan = $db->query('users')->where(['city' => 'Istanbul', 'age' => ['$gte' => 30]])->limit(10)->explain();
        $this->assertSame(1, $plan['explain']);
        $this->assertSame('users', $plan['db']);
        $this->assertTrue($plan['sharded']);
        $this->assertSame('index', $plan['access']);
        $this->assertSame(['total' => 4, 'skipped' => 3, 'indexed' => 1, 'scanned' => 0], $plan['segments']);
        $this->assertSame(['segment' => 0, 'access' => 'index', 'candidates' => 380], $plan['plan'][0]);
        foreach ([1, 2, 3] as $i) {
            $this->assertSame('skip', $plan['plan'][$i]['access']);
            $this->assertSame($i, $plan['plan'][$i]['segment']);
        }
        $this->assertSame(380, $plan['estimatedRead']);
        $this->assertSame('none', $plan['sort']);
        $this->assertTrue($plan['earlyExit']);
        $this->assertSame([], $plan['joins']);
        $this->assertTrue($plan['residual']);
        $this->assertNull($plan['groupBy']);
        $this->assertFalse($plan['planTruncated']);

        $analyzed = $db->query('users')->where(['city' => 'Istanbul'])->explain(true);
        $this->assertSame(500, $analyzed['actual']['recordsRead']);
        $this->assertSame(1, $analyzed['actual']['segmentsRead']);
        $this->assertSame(500, $analyzed['actual']['returned']);
        $this->assertIsFloat($analyzed['actual']['ms']);

        // top-k is shown as sort => top-k; without a limit the sort is full
        $this->assertSame('top-k', $db->query('users')->where(['city' => 'Ankara'])->sort('age')->limit(10)->explain()['sort']);
        $this->assertSame('full', $db->query('users')->where(['city' => 'Ankara'])->sort('age')->explain()['sort']);
    }

    // ========== REAL-WORLD EXAMPLES ==========

    /**
     * E-commerce Product Search example
     */
    public function testEcommerceSearchPattern(): void
    {
        $productDb = 'products';
        $this->noneDB->insert($productDb, [
            ['name' => 'Laptop', 'description' => 'Fast laptop', 'category' => 'electronics', 'price' => 999, 'stock' => 10, 'rating' => 4.5, 'status' => 'active'],
            ['name' => 'Phone', 'description' => 'Fast phone', 'category' => 'electronics', 'price' => 599, 'stock' => 25, 'rating' => 4.2, 'status' => 'active'],
            ['name' => 'Headphones', 'description' => 'Fast sound', 'category' => 'electronics', 'price' => 199, 'stock' => 3, 'rating' => 4.8, 'status' => 'discontinued'],
            ['name' => 'Monitor', 'description' => 'FAST refresh', 'category' => 'computers', 'price' => 350, 'stock' => 5, 'rating' => 4.0, 'status' => 'active'],
            ['name' => 'Cable', 'description' => 'slow', 'category' => 'computers', 'price' => 150, 'stock' => 5, 'rating' => 4.9, 'status' => 'active'],
            ['name' => 'Fast charger', 'description' => 'usb', 'category' => 'computers', 'price' => 99, 'stock' => 5, 'rating' => 5.0, 'status' => 'active'],
        ]);

        $page = 0;
        $products = $this->q($productDb)
            ->where([
                'category' => ['$in' => ['electronics', 'computers']],
                'price' => ['$gte' => 100, '$lte' => 1000],
                'stock' => ['$gt' => 0],
                'rating' => ['$gte' => 4.0]
            ])
            ->whereNot(['status' => 'discontinued'])
            ->search('fast', ['name', 'description'])
            ->sort('rating', 'desc')
            ->limit(20)
            ->offset($page * 20)
            ->get();

        $this->assertSame(['Laptop', 'Phone', 'Monitor'], $this->names($products));
    }

    /**
     * User Authentication example (first() + password_verify)
     */
    public function testUserAuthenticationPattern(): void
    {
        $authDb = 'auth';
        $passwordHash = password_hash('secret123', PASSWORD_DEFAULT);
        $this->noneDB->insert($authDb, [
            ['email' => 'valid@test.com', 'password_hash' => $passwordHash, 'status' => 'active', 'email_verified' => true],
            ['email' => 'unverified@test.com', 'password_hash' => $passwordHash, 'status' => 'active', 'email_verified' => false],
            ['email' => 'banned@test.com', 'password_hash' => $passwordHash, 'status' => 'banned', 'email_verified' => true],
            ['email' => 'string@test.com', 'password_hash' => $passwordHash, 'status' => 'active', 'email_verified' => 'true'],
        ]);
        $login = function ($email, $password) use ($authDb) {
            $user = $this->q($authDb)->where(['email' => $email, 'status' => 'active', 'email_verified' => true])->first();
            return $user !== null && password_verify($password, $user['password_hash']);
        };

        $this->assertTrue($login('valid@test.com', 'secret123'));
        $this->assertFalse($login('valid@test.com', 'wrong'));
        $this->assertFalse($login('unverified@test.com', 'secret123'));
        $this->assertFalse($login('banned@test.com', 'secret123'));
        $this->assertFalse($login('string@test.com', 'secret123'), "'true' is not true");
        $this->assertFalse($login('nobody@test.com', 'secret123'));
        $this->assertSame(0, $this->q($authDb)->where(['email' => 'valid@test.com'])->first()['key']);
    }

    /**
     * Analytics Dashboard example: groupBy over a date range, revenue per category
     */
    public function testAnalyticsDashboardPattern(): void
    {
        $this->noneDB->insert('orders', [
            ['status' => 'completed', 'total' => 100, 'category' => 'books', 'created_at' => 1000],
            ['status' => 'pending', 'total' => 50, 'category' => 'toys', 'created_at' => 2000],
            ['status' => 'completed', 'total' => 30.5, 'category' => 'toys', 'created_at' => 3000],
            ['status' => 'cancelled', 'total' => 70, 'category' => 'books', 'created_at' => 4000],
            ['status' => 'completed', 'total' => 20, 'category' => 'books', 'created_at' => 5000],
            ['status' => 'completed', 'total' => 999, 'category' => 'books', 'created_at' => 9000],
        ]);

        $orderStats = $this->q('orders')
            ->where(['created_at' => ['$gte' => 1500, '$lte' => 5000]])
            ->groupBy('status')
            ->get();
        $stats = [];
        foreach ($orderStats as $group) {
            $stats[$group['_group']] = [$group['_count'], array_column($group['_items'], 'key')];
        }
        ksort($stats);
        $this->assertSame(['cancelled' => [1, [3]], 'completed' => [2, [2, 4]], 'pending' => [1, [1]]], $stats);

        $categoryRevenue = [];
        foreach ($this->q('orders')->where(['status' => 'completed'])->get() as $order) {
            $cat = $order['category'];
            $categoryRevenue[$cat] = ($categoryRevenue[$cat] ?? 0) + $order['total'];
        }
        $this->assertSame(['books' => 1119, 'toys' => 30.5], $categoryRevenue);
    }

    /**
     * Content Management example
     */
    public function testContentManagementPattern(): void
    {
        $articleDb = 'articles';
        $now = $this->now;
        $this->noneDB->insert($articleDb, [
            ['title' => 'PHP Tutorial', 'content' => 'php basics', 'excerpt' => '', 'status' => 'published', 'published_at' => $now - 3600, 'tags' => ['php', 'tutorial', 'featured'], 'category' => 'tech'],
            ['title' => 'JavaScript Guide', 'content' => 'js', 'excerpt' => 'php too', 'status' => 'draft', 'published_at' => null, 'tags' => ['javascript', 'featured'], 'category' => 'tech'],
            ['title' => 'Science News', 'content' => 'atoms', 'excerpt' => 'PHP in labs', 'status' => 'published', 'published_at' => $now - 7200, 'tags' => ['science', 'featured'], 'category' => 'science'],
            ['title' => 'Future', 'content' => 'later', 'excerpt' => '', 'status' => 'published', 'published_at' => $now + 86400, 'tags' => ['featured'], 'category' => 'tech'],
            ['title' => 'Plain', 'content' => 'php', 'excerpt' => '', 'status' => 'published', 'published_at' => $now - 60, 'tags' => ['php'], 'category' => 'misc'],
        ]);

        $articles = $this->q($articleDb)
            ->where([
                'status' => 'published',
                'published_at' => ['$lte' => $now],
                'tags' => ['$contains' => 'featured']
            ])
            ->sort('published_at', 'desc')
            ->limit(10)
            ->get();
        $this->assertSame(['PHP Tutorial', 'Science News'], array_column($articles, 'title'));

        $results = $this->q($articleDb)
            ->where(['category' => ['$in' => ['tech', 'science']]])
            ->search('php', ['title', 'content', 'excerpt'])
            ->sort('published_at', 'desc')
            ->get();
        // null published_at sorts first ascending, last descending
        $this->assertSame(['PHP Tutorial', 'Science News', 'JavaScript Guide'], array_column($results, 'title'));
    }

    /**
     * Performance tips: the order of the calls doesn't matter
     */
    public function testFilterCallOrderDoesNotMatter(): void
    {
        $a = $this->q()->where(['salary' => ['$gt' => 40000]])->whereIn('department', ['Engineering', 'Design', 'Marketing'])->between('age', 20, 40)->get();
        $b = $this->q()->between('age', 20, 40)->whereIn('department', ['Engineering', 'Design', 'Marketing'])->where(['salary' => ['$gt' => 40000]])->get();

        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Eve'], $this->names($a));
        $this->assertSame($a, $b);

        // the same with a field index on department: an index decides what is read, never what matches
        $this->noneDB->createFieldIndex($this->testDbName, 'department');
        $this->assertSame($a, $this->q()->whereIn('department', ['Engineering', 'Design', 'Marketing'])->where(['salary' => ['$gt' => 40000]])->between('age', 20, 40)->get());
    }

    /**
     * Complex query with several filter methods, sorted and limited
     */
    public function testComplexQueryMultipleFilters(): void
    {
        $results = $this->q()
            ->where(['status' => 'active'])
            ->whereIn('role', ['admin', 'moderator'])
            ->between('age', 25, 40)
            ->sort('salary', 'desc')
            ->limit(5)
            ->get();

        $this->assertSame(['Eve', 'John', 'Jane'], $this->names($results));
    }

    /**
     * Operators with sort and limit
     */
    public function testOperatorsWithSortLimit(): void
    {
        $results = $this->q()
            ->where(['salary' => ['$gte' => 50000, '$lte' => 80000], 'status' => 'active'])
            ->sort('salary', 'desc')
            ->limit(3)
            ->get();

        // active in range: Eve 80000, John 75000, Jane 65000, Alice 60000
        $this->assertSame(['Eve', 'John', 'Jane'], $this->names($results));
    }

    /**
     * Operators + whereIn + between
     */
    public function testChainableCompatibility(): void
    {
        $results = $this->q()
            ->where(['salary' => ['$gt' => 40000]])
            ->whereIn('department', ['Engineering', 'Design', 'Marketing'])
            ->between('age', 20, 34)
            ->get();

        $this->assertSame(['John', 'Jane', 'Alice', 'Eve'], $this->names($results));
    }

    // ========== EDGE CASES ==========

    public function testEmptyResults(): void
    {
        $this->assertSame([], $this->q()->where(['name' => 'NonexistentPerson'])->get());
    }

    /**
     * A null filter value matches only a field that exists with null
     */
    public function testNullValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'NullTest', 'email' => null, 'age' => 30],
            ['name' => 'NoEmail', 'age' => 31],
        ]);

        $results = $this->q()->where(['email' => null])->get();

        $this->assertSame([['name' => 'NullTest', 'email' => null, 'age' => 30, 'key' => 7]],
            array_map(function ($r) { $k = $r['key']; unset($r['key']); return $r + ['key' => $k]; }, $results));
    }

    public function testSpecialCharactersInSearch(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => "O'Brien", 'email' => 'obrien@test.com', 'age' => 40]);

        $this->assertSame(["O'Brien"], $this->names($this->q()->where(['name' => "O'Brien"])->get()));
        $this->assertSame(["O'Brien"], $this->names($this->q()->search("o'bri")->get()));
        $this->assertSame(["O'Brien"], $this->names($this->q()->like('name', "^o'")->get()));
    }

    /**
     * Numeric strings compare numerically with operators but plain values are strict
     */
    public function testNumericStringComparison(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'NumericTest', 'code' => '100', 'value' => 100],
            ['name' => 'Small', 'code' => '9', 'value' => 9],
        ]);

        $this->assertSame(['NumericTest'], $this->names($this->q()->where(['value' => ['$gt' => 50]])->get()));
        // "100" > "50" numerically (lexically it would be smaller)
        $this->assertSame(['NumericTest'], $this->names($this->q()->where(['code' => ['$gt' => '50']])->get()));
        $this->assertSame(['NumericTest'], $this->names($this->q()->where(['code' => ['$gt' => 50]])->get()));
        $this->assertSame([], $this->q()->where(['code' => 100])->get());
        $this->assertSame(['NumericTest'], $this->names($this->q()->where(['code' => '100'])->get()));
    }
}
