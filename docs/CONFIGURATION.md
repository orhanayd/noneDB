# noneDB Configuration Reference

Complete configuration options and security best practices.

**Version:** 3.1.0

---

## Table of Contents

1. [Configuration Methods](#configuration-methods)
2. [Configuration Options](#configuration-options)
3. [Security Best Practices](#security-best-practices)
4. [Development Mode](#development-mode)
5. [Sharding Configuration](#sharding-configuration)
6. [Performance Tuning](#performance-tuning)

---

## Configuration Methods

### 1. Config File (Recommended)

Create a `.nonedb` file in your project root:

```json
{
    "secretKey": "YOUR_SECURE_RANDOM_STRING_HERE",
    "dbDir": "./db/",
    "autoCreateDB": true,
    "shardingEnabled": true,
    "shardSize": 10000,
    "autoMigrate": true,
    "autoCompactThreshold": 0.3,
    "lockTimeout": 5,
    "lockRetryDelay": 10000
}
```

**Setup:**

```bash
cp .nonedb.example .nonedb
# Edit .nonedb with your settings
```

**Usage:**

```php
// Automatically reads .nonedb from project root
$db = new noneDB();
```

### 2. Programmatic Configuration

```php
$db = new noneDB([
    'secretKey' => 'your_secure_key',
    'dbDir' => '/path/to/db/',
    'autoCreateDB' => true,
    'shardingEnabled' => true,
    'shardSize' => 10000
]);
```

### 3. Mixed Configuration

Config file values can be overridden:

```php
// Base config from .nonedb, override dbDir
$db = new noneDB([
    'dbDir' => '/custom/path/'
]);
```

---

## Configuration Options

### Core Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `secretKey` | string | *required* | Secret key for hashing database names. **Must be unique and secure.** |
| `dbDir` | string | `./db/` | Directory for database files. Created automatically if doesn't exist. |
| `autoCreateDB` | bool | `true` | Auto-create databases on first use. Set `false` in production. |

### Sharding Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `shardingEnabled` | bool | `true` | Enable auto-sharding for large datasets. |
| `shardSize` | int | `10000` | Records per shard. Recommended: 10,000-100,000. |
| `autoMigrate` | bool | `true` | Auto-migrate to sharded format when threshold reached. |

### Compaction Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `autoCompactThreshold` | float | `0.3` | Trigger compaction when deleted > 30% of records. Range: 0.1-0.9. |

### Lock Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `lockTimeout` | int | `5` | File lock timeout in seconds. |
| `lockRetryDelay` | int | `10000` | Lock retry delay in microseconds (10ms default). |

### Field Indexing (v3.0+)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `fieldIndexEnabled` | bool | `true` | Enable field indexing for faster filtered queries. |

---

## Security Best Practices

### 1. Secret Key Security

```php
// BAD: Hardcoded secret
$db = new noneDB(['secretKey' => 'mysecret']);

// GOOD: Environment variable
$db = new noneDB(['secretKey' => getenv('NONEDB_SECRET')]);

// GOOD: Config file (not in git)
// Use .nonedb file and add to .gitignore
```

**Generate secure key:**

```php
// PHP 7+
$secretKey = bin2hex(random_bytes(32));

// Or use command line
// openssl rand -hex 32
```

### 2. Protect Config File

Add to `.gitignore`:

```gitignore
.nonedb
db/
*.nonedb
*.nonedb.jidx
```

### 3. Protect Database Directory

Create `db/.htaccess`:

```apache
# Deny all access
Deny from all
```

Or for Apache 2.4+:

```apache
Require all denied
```

**Nginx:**

```nginx
location ~ \.nonedb {
    deny all;
}
```

### 4. Disable Auto-Create in Production

```json
{
    "autoCreateDB": false
}
```

This prevents accidental database creation from typos or injection.

### 5. Database Directory Location

```php
// BAD: Inside web root
$db = new noneDB(['dbDir' => './public/db/']);

// GOOD: Outside web root
$db = new noneDB(['dbDir' => '/var/data/nonedb/']);

// GOOD: Protected directory
$db = new noneDB(['dbDir' => './storage/db/']);  // with .htaccess
```

---

## Development Mode

For development without config file:

### Enable Dev Mode

```php
// Option 1: Static method (recommended)
noneDB::setDevMode(true);
$db = new noneDB();

// Option 2: Environment variable
putenv('NONEDB_DEV_MODE=1');
$db = new noneDB();

// Option 3: PHP constant
define('NONEDB_DEV_MODE', true);
$db = new noneDB();
```

### Dev Mode Defaults

When dev mode is enabled without config:

| Option | Dev Mode Default |
|--------|------------------|
| secretKey | `"development_secret_key_change_in_production"` |
| dbDir | `./db/` |
| autoCreateDB | `true` |
| shardingEnabled | `true` |

### Check Dev Mode Status

```php
if (noneDB::isDevMode()) {
    echo "Running in development mode";
}
```

### Warning

Dev mode should **never** be used in production. It uses a known default secret key.

---

## Sharding Configuration

### When to Enable Sharding

| Dataset Size | Recommendation |
|--------------|----------------|
| < 10K records | Disable sharding |
| 10K - 100K | Enable with default shard size (10K) |
| 100K - 500K | Enable with larger shard size (50K-100K) |
| > 500K | Enable with optimized shard size |

### Shard Size Tuning

```php
// Small shards = more files, faster individual shard access
$db = new noneDB(['shardSize' => 5000]);

// Large shards = fewer files, better bulk operations
$db = new noneDB(['shardSize' => 50000]);
```

**Trade-offs:**

| Shard Size | Pros | Cons |
|------------|------|------|
| Small (5K) | Fast shard access | More files to manage |
| Medium (10K) | Balanced | Default recommendation |
| Large (50K+) | Fewer files | Slower shard operations |

### Manual Migration Control

```php
// Disable auto-migration
$db = new noneDB([
    'autoMigrate' => false
]);

// Manually migrate when ready
$result = $db->migrate("users");
```

### Check Sharding Status

```php
$info = $db->getShardInfo("users");
// {
//     "sharded": true,
//     "shards": 5,
//     "totalRecords": 45000,
//     "shardSize": 10000
// }
```

---

## Performance Tuning

### Compaction Threshold

```php
// Aggressive compaction (compact when 10% deleted)
$db = new noneDB(['autoCompactThreshold' => 0.1]);

// Conservative compaction (compact when 50% deleted)
$db = new noneDB(['autoCompactThreshold' => 0.5]);
```

**Recommendations:**

| Use Case | Threshold |
|----------|-----------|
| High delete rate | 0.2 (20%) |
| Normal operations | 0.3 (30%) - default |
| Disk space priority | 0.5 (50%) |
| Minimal disk writes | 0.7 (70%) |

### Lock Settings

For high-concurrency environments:

```php
// Increase timeout for slower systems
$db = new noneDB([
    'lockTimeout' => 10,        // 10 seconds
    'lockRetryDelay' => 5000    // 5ms between retries
]);
```

### Static Cache

```php
// Disable for memory-constrained environments
noneDB::disableStaticCache();

// Re-enable
noneDB::enableStaticCache();

// Clear cache (useful between test runs)
noneDB::clearStaticCache();
```

### Field Index Configuration

```php
// Create indexes for frequently filtered fields
$db->createFieldIndex("users", "email");
$db->createFieldIndex("users", "status");

// Check if index exists
$db->hasFieldIndex("users", "email");

// Drop unused index
$db->dropFieldIndex("users", "old_field");
```

---

## Configuration Helpers

### Check Config

```php
// Check if config file exists
if (noneDB::configExists()) {
    echo "Config file found";
}

// Get config template path
$template = noneDB::getConfigTemplate();
// Returns: "/path/to/.nonedb.example"

// Clear cached config
noneDB::clearConfigCache();
```

### Runtime Configuration Check

```php
$db = new noneDB();

// Check current settings
$db->isShardingEnabled();  // true/false
$db->getShardSize();       // 10000
```

---

## Environment-Specific Configuration

### Development

```json
{
    "secretKey": "dev_secret_for_testing",
    "dbDir": "./db/",
    "autoCreateDB": true,
    "shardingEnabled": false,
    "autoCompactThreshold": 0.5
}
```

### Staging

```json
{
    "secretKey": "staging_unique_secret",
    "dbDir": "/var/data/staging/",
    "autoCreateDB": true,
    "shardingEnabled": true,
    "shardSize": 10000
}
```

### Production

```json
{
    "secretKey": "GENERATE_UNIQUE_64_CHAR_STRING",
    "dbDir": "/var/data/production/",
    "autoCreateDB": false,
    "shardingEnabled": true,
    "shardSize": 50000,
    "autoCompactThreshold": 0.3,
    "lockTimeout": 10
}
```

---

## Troubleshooting

### "Config file not found"

```php
// Check if file exists
var_dump(noneDB::configExists());

// Use dev mode for quick testing
noneDB::setDevMode(true);
```

### "Permission denied"

```bash
# Fix directory permissions
chmod 755 db/
chmod 644 .nonedb
```

### "Lock timeout"

```php
// Increase timeout
$db = new noneDB(['lockTimeout' => 30]);
```

### "Database not accessible after upgrade"

The most common cause is changed secretKey. Ensure you're using the same secretKey as before.

```php
// Database files are named: {hash}-{dbname}.nonedb
// The hash is generated from secretKey + dbname using PBKDF2
// If you changed your secretKey, old database files won't be found
```
