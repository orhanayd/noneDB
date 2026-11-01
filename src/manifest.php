<?php
// Build manifest of noneDB.php (tools/build.php): the internal class files, then the global classes,
// their modules in order and the text before each. A module src/<class>/<Module>.php holds part of a
// class body in a trait.
return [
    'header' => 'header.php',
    // Namespaced internal classes (noneDB\Internal\...), one per file
    'files' => [
        'Internal/Cache/Runtime.php',
        'Internal/Core/Kernel.php',
        'Internal/Cache/CacheManager.php',
        'Internal/Concurrency/LockManager.php',
        'Internal/Concurrency/Snapshot.php',
        'Internal/Config/Configuration.php',
        'Internal/Core/Crud.php',
        'Internal/Core/DatabaseOps.php',
        'Internal/Diagnostics/Integrity.php',
        'Internal/Diagnostics/Metrics.php',
        'Internal/Durability/FileIO.php',
        'Internal/Durability/Sync.php',
        'Internal/Index/FieldIndex.php',
        'Internal/Index/PagedFile.php',
        'Internal/Index/FieldIndexApi.php',
        'Internal/Index/SecondaryFiles.php',
        'Internal/Index/ShardFilters.php',
        'Internal/Index/Spatial/GeoJson.php',
        'Internal/Index/Spatial/Geometry.php',
        'Internal/Index/Spatial/RTree.php',
        'Internal/Index/Spatial/SpatialIndexApi.php',
        'Internal/Maintenance/Compaction.php',
        'Internal/Maintenance/BackupRestore.php',
        'Internal/Maintenance/KeyMaintenance.php',
        'Internal/Maintenance/Maintenance.php',
        'Internal/Query/Aggregation.php',
        'Internal/Query/FindFilter.php',
        'Internal/Query/Planner.php',
        'Internal/Query/QueryMethods.php',
        'Internal/Query/SpatialQueries.php',
        'Internal/Query/Utility.php',
        'Internal/Query/Values.php',
        'Internal/Recovery/Recovery.php',
        'Internal/Recovery/RecoveryLog.php',
        'Internal/Recovery/RollbackScope.php',
        'Internal/Sharding/LayoutSwitch.php',
        'Internal/Sharding/ShardInfo.php',
        'Internal/Sharding/ShardMeta.php',
        'Internal/Sharding/ShardRouter.php',
        'Internal/Storage/Buffer.php',
        'Internal/Storage/Checkpoints.php',
        'Internal/Storage/KeyIndex.php',
        'Internal/Storage/Layout.php',
        'Internal/Storage/RecordIO.php',
        'Internal/Storage/Segments.php',
        'Internal/Storage/StoreRecord.php',
        'Internal/Storage/Writer.php',
    ],
    // Global classes: the public API (noneDB, noneDBQuery), built from their modules
    'classes' => [
        [
            'name' => 'noneDB',
            'before' => '',
            'modules' => [
                'Facade',
            ],
        ],
        [
            'name' => 'noneDBQuery',
            'before' => '
/**
 * Query Builder for noneDB - Fluent Interface
 *
 * Allows method chaining for queries:
 * $db->query("users")->where(["active" => true])->sort("name")->limit(10)->get();
 */
',
            'modules' => [
                'Core',
                'Filters',
                'Chain',
                'SpatialChain',
                'Execution',
                'Aggregates',
                'Writes',
            ],
        ],
    ],
];
