# noneDB Changelog

## v2.0.0 (2025-12-27)

### New Features

#### Method Chaining (Fluent Interface)
Query builder pattern ile zincirleme sorgu desteği eklendi.

```php
// Eski API (hala çalışıyor)
$results = $db->find("users", ["active" => true]);
$sorted = $db->sort($results, "score", "desc");
$limited = $db->limit($sorted, 10);

// Yeni Fluent API
$results = $db->query("users")
    ->where(["active" => true])
    ->sort("score", "desc")
    ->limit(10)
    ->get();
```

**Yeni noneDBQuery Class:**
- `query($dbname)` - Query builder başlatır
- Chainable metodlar: `where()`, `like()`, `between()`, `sort()`, `limit()`, `offset()`
- Terminal metodlar: `get()`, `first()`, `last()`, `count()`, `exists()`
- Aggregation: `sum()`, `avg()`, `min()`, `max()`, `distinct()`
- Write: `update()`, `delete()`

#### Auto-Sharding
Büyük veritabanları için otomatik sharding desteği.

```php
// Sharding otomatik aktif (10K kayıt sonrası)
$db->isShardingEnabled();  // true
$db->getShardSize();       // 10000

// Shard bilgisi
$info = $db->getShardInfo("users");
// ["sharded" => true, "shards" => 5, "totalRecords" => 50000]

// Manuel işlemler
$db->migrate("users");     // Sharded formata geç
$db->compact("users");     // Silinen kayıtları temizle
```

### Bug Fixes

- **like() array handling**: `like()` fonksiyonu artık array/object field değerlerinde crash etmiyor, güvenli şekilde atlıyor

### Improvements

#### Test Suite
- **448 test, 1005 assertion** (v1.4: 0 test)
- Unit, Feature, Integration test suite'leri
- 67 edge case testi
- 40 chaining testi
- 28 sharding testi

#### Examples
11 örnek dosya yeniden yazıldı/eklendi:
- `basic-usage.php` - Temel CRUD işlemleri
- `filtering.php` - Filtreleme ve arama
- `chaining.php` - Method chaining örnekleri
- `aggregation.php` - Aggregation fonksiyonları
- `query-methods.php` - Query metodları
- `utility-methods.php` - Utility fonksiyonları
- `sharding.php` - Sharding örnekleri
- `database-management.php` - DB yönetimi
- `data-types.php` - Veri tipleri
- `real-world.php` - Gerçek dünya senaryoları
- `performance.php` - Performans testleri

#### Documentation
- `README.md` - Kapsamlı dokümantasyon

### Breaking Changes

Yok. Tüm v1.x API'ları geriye uyumlu çalışmaya devam eder.

### Migration Guide

v1.x'ten v2.0'a geçiş için:
1. `noneDB.php` dosyasını güncelleyin
2. Yeni özellikleri kullanmaya başlayın (opsiyonel)
3. Mevcut kodunuz değişiklik olmadan çalışmaya devam eder

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
