# noneDB Configuration Reference

Complete configuration options and security best practices.

**Version:** 3.2.0

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
    'secretKey' => getenv('NONEDB_SECRET'), // your own random key (example placeholders are rejected)
    'dbDir' => '/path/to/db/',
    'autoCreateDB' => true,
    'shardingEnabled' => true,
    'shardSize' => 10000
]);
```

### 3. Mixed Configuration

A config array is merged over the `.nonedb` file: values in the array win, everything else comes from the
file. When the array contains both `secretKey` and `dbDir`, the file is optional.

```php
// secretKey (and the other settings) from .nonedb, dbDir overridden
$db = new noneDB([
    'dbDir' => '/custom/path/'
]);
```

### Where `.nonedb` is looked for

1. The directory of the running script (`$_SERVER['SCRIPT_FILENAME']`)
2. The directory of `noneDB.php`
3. The Composer project root (when noneDB is installed under `vendor/`)
4. The current working directory

The first file found is used for the whole process. Keep it **outside the web root**: it contains the
secret key.

### Relative `dbDir`

- In `.nonedb`, a relative `dbDir` (`./db/`, `../data/` or `db/`) is relative to **the `.nonedb` file's
  directory**. Web pages (`public/index.php`) and CLI or cron scripts (`bin/job.php`) therefore use the
  same databases.
- In a config array, a relative `dbDir` is relative to the entry script's directory. Absolute paths are
  recommended.
- Before 3.2, `./db/` in the file was relative to the running script and `db/` to the working directory,
  which split data between directories. If such an old directory exists and the new one doesn't, it is
  still used and an `E_USER_DEPRECATED` notice asks you to move it.

---

## Configuration Options

### Core Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `secretKey` | string | *required* | Secret used to derive the database file names. Must be a non-empty string and not an example placeholder, otherwise the constructor throws `InvalidArgumentException`. Use 32+ random characters. |
| `dbDir` | string | *required* (dev mode: `db/` in the project root) | Directory for database files. Created automatically (with an `.htaccess` that denies web access and an empty `index.html`). See *Relative `dbDir`* above. |
| `autoCreateDB` | bool | `true` | Let writes (`insert`, `createFieldIndex`, ...) create missing databases. Reads never create anything. Set `false` in production. |

### Sharding Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `shardingEnabled` | bool | `true` | Enable auto-sharding for large datasets. |
| `shardSize` | int | `10000` | Records per shard. Recommended: 10,000-100,000. |
| `autoMigrate` | bool | `true` | Auto-migrate to sharded format when threshold reached. |

### Compaction Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `autoCompactThreshold` | float | `0.3` | Compact a data file when deleted/superseded records exceed this share of its records. Must be > 0. |

### Lock Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `lockTimeout` | int | `5` | Seconds to wait for a database lock before throwing `RuntimeException`. `0` waits forever. |
| `lockRetryDelay` | int | `10000` | Microseconds between lock attempts (10ms default). Must be > 0. |

### Field Indexing (v3.0+)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `fieldIndexEnabled` | bool | `true` | Use field indexes in queries. Indexes are always kept up to date, so turning this back on is safe. |
| `spatialIndexEnabled` | bool | `true` | Allow creating and using spatial indexes. Existing ones are always kept up to date. |

### Write Buffer

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `bufferEnabled` | bool | `false` | Collect inserts in a buffer file and add them in batches. Off by default since 3.2: direct inserts are fast and immediately visible. Buffered records are flushed before every read and write. |

---

## Security Best Practices

### 1. What the Secret Key Does (and doesn't)

- Database files are named `{hash}-{name}.nonedb`, where `hash` = PBKDF2(name, secretKey). Without the
  key the file names can't be guessed, e.g. by someone probing a web-accessible directory.
- The key does **not** encrypt anything: records are stored as plain JSON lines. Protect the directory
  (outside the web root, file permissions) and the `.nonedb` file.
- A wrong key simply doesn't see the databases of another key.
- Anyone who can list the directory sees `{hash}-{name}` and can test guesses for the key offline
  (PBKDF2-SHA256, 1000 rounds). A short or guessable key can be found this way: use 32+ random characters.
- To change the key of existing databases (for example away from the old example placeholder):

```php
noneDB::migrateKey('/var/data/nonedb', $oldKey, $newKey); // renames every file of every database
```

### 2. Secret Key Handling

```php
// BAD: Hardcoded secret
$db = new noneDB(['secretKey' => 'mysecret']);

// GOOD: Environment variable (if it isn't set, getenv() returns false and the constructor throws)
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

### 3. Protect Config File

Add to `.gitignore`:

```gitignore
.nonedb
db/
*.nonedb
*.nonedb.jidx
```

### 4. Protect Database Directory

noneDB writes this `.htaccess` (and an empty `index.html`) when it creates `dbDir`. For an existing
directory, create `db/.htaccess`:

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

### 5. Disable Auto-Create in Production

```json
{
    "autoCreateDB": false
}
```

This prevents accidental database creation from typos or injection. Database names are validated
(`A-Z a-z 0-9`, space, `'`, `-`); anything else throws `InvalidArgumentException`.

### 6. Database Directory Location

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
| secretKey | `"nonedb_dev_mode_key_" . md5(<directory of noneDB.php>)` — predictable by design |
| dbDir | `db/` in the project root (next to `vendor/` when installed with Composer, otherwise next to `noneDB.php`) |
| autoCreateDB | `true` |
| shardingEnabled | `true` |

### Check Dev Mode Status

```php
if (noneDB::isDevMode()) {
    echo "Running in development mode";
}
```

### Warning

Dev mode should **never** be used in production: its key is predictable. Outside the CLI, noneDB raises
an `E_USER_NOTICE` once per process while dev mode is active.

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

Each database has a lock file `{hash}-{name}.nonedb.lock`. Besides the lock it holds a small state
record (change counter, pending write buffer, index journal) that every operation reads with the lock,
so cache validation and crash recovery need no extra file access. Lock files stay when a database is
dropped and their handles stay open between operations (one per database, at most 64 per process).
Don't delete them while processes use the databases; deleting them while nothing runs is harmless (the
one-time check for pre-3.2 leftovers then runs again).

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

Replace the placeholder key: noneDB refuses to start with it.

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

### "timed out after Ns waiting for the lock"

Another process held the database lock longer than `lockTimeout` (a long compaction or migration).

```php
$db = new noneDB(['lockTimeout' => 30]); // or 0 to wait forever
```

### "'secretKey' is still the example placeholder" / "must be a non-empty string"

Generate a key (`php -r "echo bin2hex(random_bytes(32));"`). If databases already exist under the
placeholder key, move them first with `noneDB::migrateKey($dbDir, $oldKey, $newKey)`.

### "invalid database name"

Only `A-Z a-z 0-9`, space, `'` and `-` are allowed. Before 3.2 other characters were removed silently, so
data written as `"my_db"` is stored under the name `"mydb"`.

### "Database not accessible after upgrade"

- Changed secretKey: database files are named `{hash}-{name}.nonedb` with a hash derived from the key.
  Use `noneDB::migrateKey()` to change keys instead of editing the config only.
- Relative `dbDir`: it now follows the `.nonedb` file. Look for an `E_USER_DEPRECATED` notice naming the
  old directory, then move the files or use an absolute path.
- Went back to 3.1 and then returned to 3.2: 3.1 doesn't maintain the lock files' state. Stop all
  processes, delete the `*.nonedb.lock` files and start again.
