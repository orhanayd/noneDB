# noneDB Changelog

## v2.1.0 (2025-12-27)

### New Features

#### Advanced Filter Methods

New chainable filter methods added:

```php
// OR condition
$db->query("users")
    ->where(["department" => "IT"])
    ->orWhere(["department" => "Engineering"])
    ->orWhere(["role" => "admin"])
    ->get();

// Array membership
$db->query("users")
    ->whereIn("status", ["active", "pending"])
    ->whereNotIn("role", ["banned", "suspended"])
    ->get();

// Negation filters
$db->query("users")
    ->whereNot(["deleted" => true])
    ->notLike("email", "test")
    ->notBetween("age", 0, 18)
    ->get();
```

**New Methods:**
- `orWhere($filters)` - OR condition filtering
- `whereIn($field, $values)` - Value in array
- `whereNotIn($field, $values)` - Value not in array
- `whereNot($filters)` - Not equal filter
- `notLike($field, $pattern)` - Pattern should not match
- `notBetween($field, $min, $max)` - Value outside range

#### Full-Text Search

Multi-field search support:

```php
$results = $db->query("products")
    ->search("wireless keyboard")
    ->get();

// Search in specific fields
$results = $db->query("users")
    ->search("john", ["name", "email", "bio"])
    ->get();
```

#### Database Joins

Cross-database join support:

```php
$orders = $db->query("orders")
    ->where(["status" => "completed"])
    ->join("users", "user_id", "id")
    ->get();
// Each order now includes related user data
```

#### Grouping & Aggregation

GROUP BY and HAVING support:

```php
$categories = $db->query("products")
    ->groupBy("category")
    ->having("count", ">", 10)
    ->having("avg:price", ">", 100)
    ->get();
```

**Having aggregate types:**
- `count` - Group count
- `sum:field` - Field sum
- `avg:field` - Field average
- `min:field` - Minimum value
- `max:field` - Maximum value

#### Field Projection

Select or exclude fields from results:

```php
// Get only specific fields
$users = $db->query("users")
    ->select(["name", "email", "avatar"])
    ->get();

// Exclude sensitive fields
$users = $db->query("users")
    ->except(["password", "token", "secret_key"])
    ->get();
```

#### removeFields() Terminal Method

Permanently remove fields from matching records:

```php
$result = $db->query("users")
    ->where(["status" => "deleted"])
    ->removeFields(["personal_data", "payment_info"]);
// ["n" => 5, "fields_removed" => ["personal_data", "payment_info"]]
```

**Features:**
- Works with sharded databases
- `key` field is protected (cannot be removed)
- Can be combined with all filter methods

#### Method Aliases

Convenience aliases:

```php
->skip(20)      // Same as offset(20)
->orderBy("name", "asc")  // Same as sort("name", "asc")
```

### Bug Fixes

- **whereIn/whereNotIn null handling**: Fixed null value filtering by using `array_key_exists()` instead of `isset()`
- **join() array/object key handling**: Fixed crash when local or foreign key values are arrays or objects - now gracefully returns null for non-scalar keys

### Improvements

#### Test Suite
- **723 tests, 1916 assertions** (v2.0: 448 tests, 1005 assertions)
- +275 new tests added
- `NewChainingMethodsTest.php` - 100 tests (new methods)
- `NewMethodsEdgeCasesTest.php` - 50 tests (edge cases)
- `NewMethodsShardedTest.php` - 18 tests (sharded DB support)
- `RemoveFieldsTest.php` - 29 tests (removeFields)
- `TypeEdgeCasesTest.php` - 79 tests (type validation & edge cases)

#### Documentation
- `README.md` - All new methods documented
- `CLAUDE.md` - Developer guide updated
- Method Chaining section organized into 5 categories
- 15+ new code examples added

### Breaking Changes

None. All v2.0.x APIs remain backward compatible.

### Migration Guide

> **CRITICAL:** Before updating, backup your `$secretKey` from the old `noneDB.php`. Restore it after update or you'll lose access to your data!

To upgrade from v2.0.x to v2.1.0:
1. **Backup** your `$secretKey` from current `noneDB.php`
2. Update `noneDB.php` file
3. **Restore** your `$secretKey` in the new file
4. Start using new features (optional)

---

## v2.0.0 (2025-12-27)

### New Features

#### Method Chaining (Fluent Interface)
Query builder pattern with method chaining support.

```php
// Old API (still works)
$results = $db->find("users", ["active" => true]);
$sorted = $db->sort($results, "score", "desc");
$limited = $db->limit($sorted, 10);

// New Fluent API
$results = $db->query("users")
    ->where(["active" => true])
    ->sort("score", "desc")
    ->limit(10)
    ->get();
```

**New noneDBQuery Class:**
- `query($dbname)` - Starts query builder
- Chainable methods: `where()`, `like()`, `between()`, `sort()`, `limit()`, `offset()`
- Terminal methods: `get()`, `first()`, `last()`, `count()`, `exists()`
- Aggregation: `sum()`, `avg()`, `min()`, `max()`, `distinct()`
- Write: `update()`, `delete()`

#### Auto-Sharding
Automatic sharding support for large databases.

```php
// Sharding is auto-enabled (after 10K records)
$db->isShardingEnabled();  // true
$db->getShardSize();       // 10000

// Shard info
$info = $db->getShardInfo("users");
// ["sharded" => true, "shards" => 5, "totalRecords" => 50000]

// Manual operations
$db->migrate("users");     // Migrate to sharded format
$db->compact("users");     // Clean up deleted records
```

### Bug Fixes

- **like() array handling**: `like()` function no longer crashes on array/object field values, safely skips them

### Improvements

#### Test Suite
- **448 tests, 1005 assertions** (v1.4: 0 tests)
- Unit, Feature, Integration test suites
- 67 edge case tests
- 40 chaining tests
- 28 sharding tests

#### Examples
11 example files rewritten/added:
- `basic-usage.php` - Basic CRUD operations
- `filtering.php` - Filtering and search
- `chaining.php` - Method chaining examples
- `aggregation.php` - Aggregation functions
- `query-methods.php` - Query methods
- `utility-methods.php` - Utility functions
- `sharding.php` - Sharding examples
- `database-management.php` - DB management
- `data-types.php` - Data types
- `real-world.php` - Real world scenarios
- `performance.php` - Performance tests

#### Documentation
- `README.md` - Comprehensive documentation

### Breaking Changes

None. All v1.x APIs remain backward compatible.

### Migration Guide

> **CRITICAL:** Before updating, backup your `$secretKey` from the old `noneDB.php`. Restore it after update or you'll lose access to your data!

To upgrade from v1.x to v2.0:
1. **Backup** your `$secretKey` from current `noneDB.php`
2. Update `noneDB.php` file
3. **Restore** your `$secretKey` in the new file
4. Start using new features (optional)

---

## v1.4.0 (2025-12-27)

- Initial release with core CRUD operations
- JSON-based file storage
- PBKDF2-hashed filenames
- Basic query methods (find, like, between, distinct)
- Aggregation functions (sum, avg, min, max, count)
- Utility methods (first, last, exists, sort, limit)

## v1.3.0

- Database management improvements

## v1.2.0

- Performance optimizations

## v1.1.0

- Bug fixes

## v1.0.0

- Initial release
