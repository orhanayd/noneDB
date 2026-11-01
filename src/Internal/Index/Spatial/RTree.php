<?php
// R-trees, the global spatial index and their files
namespace noneDB\Internal\Index\Spatial;

use function array_chunk, array_key_exists, array_map, array_merge, array_multisort, array_pop, array_search, array_slice,
    array_values, ceil, count, crc32, floor, in_array, intdiv, is_array, is_int, json_decode, ksort, max, min, pack, sqrt,
    strlen, strpos, substr, unpack;
use const INF, SORT_NUMERIC;

/**
 * @internal
 */
final class RTree {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $globalSpatialIndexCache;
    private $rtreeNodeSize;
    private $spatialIndexCache;
    private $cacheGrew;
    private $cacheTick;
    private $cacheTouch;
    private $lockRegistry;
    private $metrics;
    private $multiLock;
    private $noWrites;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $geoJson;
    private $geometry;
    private $io;
    private $layout;
    private $locks;
    private $records;
    private $secondary;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->fieldIndex, $this->geoJson, $this->geometry, $this->io, $this->layout, $this->locks, $this->records, $this->secondary, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->rtreeNodeSize = &$this->k->rtreeNodeSize;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->multiLock = &$this->rt->multiLock;
        $this->noWrites = &$this->rt->noWrites;
    }

    /**
     * A handle to another service, bound on first use
     */
    public function __get(string $name) {
        return $this->$name = $this->k->$name;
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->cache = null;
        $this->checkpoints = null;
        $this->fieldIndex = null;
        $this->geoJson = null;
        $this->geometry = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->records = null;
        $this->secondary = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Path of a segment's R-tree
     */
    public function getSpatialIndexPath(string $dbname, string $field, ?int $shardId = null): string {
        return $this->secondary->secondaryPath($dbname, 'rx', $field, $shardId);
    }

    public function getGlobalSpatialIndexPath(string $dbname, string $field): string {
        return $this->secondary->secondaryPath($dbname, 'grx', $field);
    }

    /**
     * Integer box of a geometry (outwards), or null when it has no position
     */
    private function quantizedMBR(array $geometry): ?array {
        $mbr = $this->geometry->calculateMBR($geometry);
        return $mbr === null ? null : $this->quantizeBox($mbr);
    }

    /**
     * Integer box of [minLon, minLat, maxLon, maxLat] (clamped to the globe, rounded outwards)
     */
    private function quantizeBox(array $box): array {
        $minX = max(-180.0, min(180.0, (float)$box[0]));
        $minY = max(-90.0, min(90.0, (float)$box[1]));
        $maxX = max(-180.0, min(180.0, (float)$box[2]));
        $maxY = max(-90.0, min(90.0, (float)$box[3]));
        return [(int)floor($minX * self::RTREE_SCALE), (int)floor($minY * self::RTREE_SCALE),
            (int)ceil($maxX * self::RTREE_SCALE), (int)ceil($maxY * self::RTREE_SCALE)];
    }

    private static function boxUnion(?array $a, array $b): array {
        if($a === null){
            return $b;
        }
        return [$a[0] < $b[0] ? $a[0] : $b[0], $a[1] < $b[1] ? $a[1] : $b[1], $a[2] > $b[2] ? $a[2] : $b[2], $a[3] > $b[3] ? $a[3] : $b[3]];
    }

    private static function boxOverlaps(?array $a, array $b): bool {
        return $a !== null && $a[0] <= $b[2] && $b[0] <= $a[2] && $a[1] <= $b[3] && $b[1] <= $a[3];
    }

    private static function isBox($box): bool {
        return is_int($box[0] ?? null) && is_int($box[1] ?? null) && is_int($box[2] ?? null) && is_int($box[3] ?? null);
    }

    private static function boxArea(array $a): float {
        return ((float)$a[2] - $a[0]) * ((float)$a[3] - $a[1]);
    }

    public function newRTree(string $field, int $nodeSize): array {
        return ['kind' => 'rx', 'field' => $field, 'ns' => $nodeSize, 's' => 0, 'ck' => -1, 'e' => null, 'bytes' => 0,
            'nodes' => [0 => [0, null, []]], 'root' => 0, 'next' => 1, 'mk' => -1, 'cnt' => 0, 'leafOf' => null, 'parent' => null];
    }

    /**
     * Bulk load (sort-tile-recursive): leaves 3/4 full, ties broken by key (the same tree on every PHP). The entries
     * come as columns, sorted with array_multisort(): no array per entry until the leaves are made
     * @param int[] $ids keys (the leaves), then node ids (the levels above)
     * @param int[] $minX, $minY, $maxX, $maxY their boxes, in the same order
     */
    private function rtreeBulkLoad(array &$tree, array $ids, array $minX, array $minY, array $maxX, array $maxY): void {
        $capacity = max(2, intdiv($tree['ns'] * 3, 4));
        $tree['nodes'] = [];
        $tree['next'] = 0;
        $tree['cnt'] = count($ids);
        $tree['leafOf'] = null;
        $tree['parent'] = null;
        if(empty($ids)){
            $tree['nodes'][0] = [0, null, []];
            $tree['root'] = 0;
            $tree['next'] = 1;
            return;
        }
        for($level = 0; ; $level++){
            $count = count($ids);
            $perSlice = (int)ceil(sqrt((int)ceil($count / $capacity))) * $capacity;
            // By the x center (doubled: min + max), then the id
            $center = [];
            for($i = 0; $i < $count; $i++){
                $center[] = $minX[$i] + $maxX[$i];
            }
            array_multisort($center, SORT_NUMERIC, $ids, SORT_NUMERIC, $minX, $minY, $maxX, $maxY);
            $center = null;
            $up = [[], [], [], [], []]; // the nodes made: ids and boxes
            for($i = 0; $i < $count; $i += $perSlice){
                // A vertical slice, by the y center, then the id
                $sIds = array_slice($ids, $i, $perSlice);
                $sMinX = array_slice($minX, $i, $perSlice);
                $sMinY = array_slice($minY, $i, $perSlice);
                $sMaxX = array_slice($maxX, $i, $perSlice);
                $sMaxY = array_slice($maxY, $i, $perSlice);
                $m = count($sIds);
                $center = [];
                for($j = 0; $j < $m; $j++){
                    $center[] = $sMinY[$j] + $sMaxY[$j];
                }
                array_multisort($center, SORT_NUMERIC, $sIds, SORT_NUMERIC, $sMinX, $sMinY, $sMaxX, $sMaxY);
                for($j = 0; $j < $m; $j += $capacity){
                    $id = $tree['next']++;
                    $box = [$sMinX[$j], $sMinY[$j], $sMaxX[$j], $sMaxY[$j]];
                    $payload = [];
                    for($k = $j, $end = min($m, $j + $capacity); $k < $end; $k++){
                        $box = [min($box[0], $sMinX[$k]), min($box[1], $sMinY[$k]), max($box[2], $sMaxX[$k]), max($box[3], $sMaxY[$k])];
                        $payload[] = $level === 0 ? [$sIds[$k], $sMinX[$k], $sMinY[$k], $sMaxX[$k], $sMaxY[$k]] : $sIds[$k];
                    }
                    $tree['nodes'][$id] = [$level, $box, $payload];
                    $up[0][] = $id;
                    $up[1][] = $box[0];
                    $up[2][] = $box[1];
                    $up[3][] = $box[2];
                    $up[4][] = $box[3];
                }
            }
            if(count($up[0]) === 1){
                $tree['root'] = $up[0][0];
                return;
            }
            [$ids, $minX, $minY, $maxX, $maxY] = $up;
        }
    }

    /**
     * Parent and leaf maps (built when the first change needs them)
     */
    public function rtreeMaps(array &$tree): void {
        if($tree['parent'] !== null){
            return;
        }
        $parent = [];
        $leafOf = [];
        foreach($tree['nodes'] as $id => $node){
            if($node[0] === 0){
                foreach($node[2] as $entry){
                    $leafOf[$entry[0]] = $id;
                }
            } else {
                foreach($node[2] as $child){
                    $parent[$child] = $id;
                }
            }
        }
        $tree['parent'] = $parent;
        $tree['leafOf'] = $leafOf;
    }

    /**
     * Insert (or move) a key with its box. The way down is remembered for the box updates and splits,
     * so a key never seen before needs no parent map (built only when something is removed).
     */
    private function rtreeInsert(array &$tree, int $key, array $box): void {
        if($key <= $tree['mk']){
            $this->rtreeRemove($tree, $key);
        } else {
            $tree['mk'] = $key;
        }
        $path = [];
        $id = $tree['root'];
        while($tree['nodes'][$id][0] > 0){
            $best = null;
            $bestGrowth = INF;
            $bestArea = INF;
            foreach($tree['nodes'][$id][2] as $child){
                $childBox = $tree['nodes'][$child][1];
                if($childBox === null){
                    $best = $best ?? $child;
                    continue;
                }
                $area = self::boxArea($childBox);
                $growth = self::boxArea(self::boxUnion($childBox, $box)) - $area;
                if($growth < $bestGrowth || ($growth == $bestGrowth && $area < $bestArea)){
                    $best = $child;
                    $bestGrowth = $growth;
                    $bestArea = $area;
                }
            }
            if($best === null){
                $tree['nodes'][$id] = [0, null, []]; // an internal node without children becomes a leaf
                break;
            }
            $path[] = $id;
            $id = $best;
        }
        $tree['nodes'][$id][2][] = [$key, $box[0], $box[1], $box[2], $box[3]];
        if($tree['leafOf'] !== null){
            $tree['leafOf'][$key] = $id;
        }
        $tree['cnt']++;
        // Widen the boxes on the way up while they change
        for($node = $id, $i = count($path); ; $node = $path[--$i]){
            $old = $tree['nodes'][$node][1];
            $new = self::boxUnion($old, $box);
            if($old === $new){
                break;
            }
            $tree['nodes'][$node][1] = $new;
            if($i === 0){
                break;
            }
        }
        if(count($tree['nodes'][$id][2]) > $tree['ns']){
            $this->rtreeSplit($tree, $id, $path);
        }
    }

    private function rtreeBoxOf(array $tree, int $level, $item): ?array {
        return $level === 0 ? [$item[1], $item[2], $item[3], $item[4]] : $tree['nodes'][$item][1];
    }

    /**
     * Split an overflowing node (linear seeds, least enlargement), up to the root
     * @param array $path The node's ancestors, root first
     */
    private function rtreeSplit(array &$tree, int $id, array $path): void {
        [$level, , $items] = $tree['nodes'][$id];
        $n = count($items);
        $boxes = [];
        foreach($items as $i => $item){
            $boxes[$i] = $this->rtreeBoxOf($tree, $level, $item) ?? [0, 0, 0, 0];
        }
        // Seeds: the pair farthest apart along the axis with the greatest normalized separation
        $best = null;
        foreach([0, 1] as $axis){
            $lowHigh = 0;
            $highLow = 0;
            $min = INF;
            $max = -INF;
            foreach($boxes as $i => $b){
                if($b[$axis] > $boxes[$lowHigh][$axis]){
                    $lowHigh = $i;
                }
                if($b[$axis + 2] < $boxes[$highLow][$axis + 2]){
                    $highLow = $i;
                }
                $min = min($min, $b[$axis]);
                $max = max($max, $b[$axis + 2]);
            }
            $width = $max - $min;
            $separation = $width > 0 ? ($boxes[$lowHigh][$axis] - $boxes[$highLow][$axis + 2]) / $width : 0;
            if($best === null || $separation > $best[0]){
                $best = [$separation, $lowHigh, $highLow];
            }
        }
        [, $seed1, $seed2] = $best;
        if($seed1 === $seed2){
            $seed2 = ($seed1 + 1) % $n;
        }
        $groups = [[$items[$seed1]], [$items[$seed2]]];
        $groupBoxes = [$boxes[$seed1], $boxes[$seed2]];
        $minFill = max(1, (int)($tree['ns'] * 0.4));
        $left = $n - 2;
        foreach($items as $i => $item){
            if($i === $seed1 || $i === $seed2){
                continue;
            }
            if(count($groups[0]) + $left <= $minFill){
                $g = 0;
            } elseif(count($groups[1]) + $left <= $minFill){
                $g = 1;
            } else {
                $grow0 = self::boxArea(self::boxUnion($groupBoxes[0], $boxes[$i])) - self::boxArea($groupBoxes[0]);
                $grow1 = self::boxArea(self::boxUnion($groupBoxes[1], $boxes[$i])) - self::boxArea($groupBoxes[1]);
                $g = $grow0 < $grow1 || ($grow0 == $grow1 && count($groups[0]) <= count($groups[1])) ? 0 : 1;
            }
            $groups[$g][] = $item;
            $groupBoxes[$g] = self::boxUnion($groupBoxes[$g], $boxes[$i]);
            $left--;
        }
        $newId = $tree['next']++;
        $tree['nodes'][$id] = [$level, $groupBoxes[0], $groups[0]];
        $tree['nodes'][$newId] = [$level, $groupBoxes[1], $groups[1]];
        if($tree['parent'] !== null){
            foreach($groups[1] as $item){
                if($level === 0){
                    $tree['leafOf'][$item[0]] = $newId;
                } else {
                    $tree['parent'][$item] = $newId;
                }
            }
        }
        if(empty($path)){
            $rootId = $tree['next']++;
            $tree['nodes'][$rootId] = [$level + 1, self::boxUnion($groupBoxes[0], $groupBoxes[1]), [$id, $newId]];
            $tree['root'] = $rootId;
            if($tree['parent'] !== null){
                $tree['parent'][$id] = $rootId;
                $tree['parent'][$newId] = $rootId;
            }
            return;
        }
        $parentId = array_pop($path);
        $tree['nodes'][$parentId][2][] = $newId;
        if($tree['parent'] !== null){
            $tree['parent'][$newId] = $parentId;
        }
        if(count($tree['nodes'][$parentId][2]) > $tree['ns']){
            $this->rtreeSplit($tree, $parentId, $path);
        }
    }

    /**
     * Remove a key; emptied nodes go, boxes shrink up the tree, a root with one child is replaced by it
     * @return bool True when the key was indexed
     */
    private function rtreeRemove(array &$tree, int $key): bool {
        $this->rtreeMaps($tree);
        if(!isset($tree['leafOf'][$key])){
            return false;
        }
        $id = $tree['leafOf'][$key];
        unset($tree['leafOf'][$key]);
        $entries = $tree['nodes'][$id][2];
        foreach($entries as $i => $entry){
            if($entry[0] === $key){
                unset($entries[$i]);
                break;
            }
        }
        $tree['nodes'][$id][2] = array_values($entries);
        $tree['cnt']--;
        // Remove emptied nodes bottom-up
        while($id !== $tree['root'] && empty($tree['nodes'][$id][2])){
            $parentId = $tree['parent'][$id];
            $children = $tree['nodes'][$parentId][2];
            $at = array_search($id, $children, true);
            if($at !== false){
                unset($children[$at]);
            }
            $tree['nodes'][$parentId][2] = array_values($children);
            unset($tree['nodes'][$id], $tree['parent'][$id]);
            $id = $parentId;
        }
        // Recompute boxes up the tree while they change
        while(true){
            [$level, $old, $items] = $tree['nodes'][$id];
            $box = null;
            foreach($items as $item){
                $itemBox = $this->rtreeBoxOf($tree, $level, $item);
                if($itemBox !== null){
                    $box = self::boxUnion($box, $itemBox);
                }
            }
            if($box === $old){
                break;
            }
            $tree['nodes'][$id][1] = $box;
            if(!isset($tree['parent'][$id])){
                break;
            }
            $id = $tree['parent'][$id];
        }
        // Collapse the root
        while(true){
            [$level, , $items] = $tree['nodes'][$tree['root']];
            if($level === 0){
                break;
            }
            if(empty($items)){
                $tree['nodes'][$tree['root']] = [0, null, []];
                break;
            }
            if(count($items) > 1){
                break;
            }
            $child = $items[0];
            unset($tree['nodes'][$tree['root']]);
            $tree['root'] = $child;
            unset($tree['parent'][$child]);
        }
        return true;
    }

    /**
     * Keys whose boxes overlap one of the (integer) boxes
     */
    private function rtreeSearch(array $tree, array $boxes): array {
        $found = [];
        $stack = [$tree['root']];
        while(!empty($stack)){
            $id = array_pop($stack);
            [$level, $nodeBox, $items] = $tree['nodes'][$id];
            if($nodeBox === null){
                continue;
            }
            $hit = false;
            foreach($boxes as $box){
                if(self::boxOverlaps($nodeBox, $box)){
                    $hit = true;
                    break;
                }
            }
            if(!$hit){
                continue;
            }
            foreach($items as $item){
                if($level > 0){
                    $stack[] = $item;
                    continue;
                }
                foreach($boxes as $box){
                    if($item[1] <= $box[2] && $box[0] <= $item[3] && $item[2] <= $box[3] && $box[1] <= $item[4]){
                        $found[$item[0]] = $item[0];
                        break;
                    }
                }
            }
        }
        return array_values($found);
    }

    /**
     * Apply one data line to an R-tree: the key now holds $record (null: deleted)
     */
    private function rtreeApply(array &$tree, int $key, ?array $record): void {
        $geometry = $record === null ? null : ($record[$tree['field']] ?? null);
        $box = is_array($geometry) && $this->geoJson->validateGeoJSON($geometry)['valid'] ? $this->quantizedMBR($geometry) : null;
        if($box === null){
            if($key <= $tree['mk']){
                $this->rtreeRemove($tree, $key);
            } elseif($key > $tree['mk']){
                $tree['mk'] = $key;
            }
            return;
        }
        $this->rtreeInsert($tree, $key, $box);
    }

    /**
     * Build a segment's R-tree from its data (covers the key index's bytes)
     */
    public function buildSegmentSpatialIndex(string $dbname, string $field, ?int $shardId, array $options = []): array {
        $key = $this->secondary->keyIndexWithEpoch($dbname, $shardId);
        $nodeSize = isset($options['nodeSize']) && (int)$options['nodeSize'] >= 4 ? (int)$options['nodeSize'] : $this->rtreeNodeSize;
        $tree = $this->newRTree($field, $nodeSize);
        $columns = [[], [], [], [], []]; // keys, then their boxes' minX, minY, maxX, maxY
        foreach($this->records->eachSegmentBatch($dbname, $shardId) as $batch){
            foreach($batch as $k => $record){
                $geometry = $record[$field] ?? null;
                if(is_array($geometry) && $this->geoJson->validateGeoJSON($geometry)['valid']){
                    $box = $this->quantizedMBR($geometry);
                    if($box !== null){
                        $columns[0][] = (int)$k;
                        $columns[1][] = $box[0];
                        $columns[2][] = $box[1];
                        $columns[3][] = $box[2];
                        $columns[4][] = $box[3];
                    }
                }
            }
        }
        $this->rtreeBulkLoad($tree, ...$columns);
        $tree['mk'] = $key === null ? -1 : \noneDB\Internal\Storage\KeyIndex::maxKey($key);
        $tree['s'] = $key === null ? 0 : (int)$key['s'];
        $tree['e'] = $key === null ? null : $key['e'];
        return $tree;
    }

    /**
     * A segment's R-tree, current (the data lines after the bytes it covers are replayed); null when
     * none fits the segment (see ensureSegmentSpatialIndex)
     */
    private function readSpatialIndex(string $dbname, string $field, ?int $shardId = null): ?array {
        $key = $this->secondary->currentKeyIndex($dbname, $shardId);
        if($key === null){
            return null;
        }
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        $this->cacheTouch[$path] = ++$this->cacheTick;
        if(isset($this->spatialIndexCache[$path])){
            $tree = $this->spatialIndexCache[$path];
            if($tree['e'] !== null && $tree['e'] === $key['e'] && $tree['s'] <= (int)$key['s']){
                $this->metrics['hits']++;
                if($tree['s'] < (int)$key['s']){
                    unset($this->spatialIndexCache[$path], $tree['due']);
                    $this->replaySpatialIndex($dbname, $shardId, $tree, (int)$key['s']);
                    $this->spatialIndexCache[$path] = $tree;
                    if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
                        $this->spatialIndexCache[$path]['due'] = true;
                    }
                } elseif(isset($tree['due']) && $this->noWrites === 0 && $this->multiLock === 0){
                    unset($tree['due'], $this->spatialIndexCache[$path]['due']); // a checkpoint a read-only load put off
                    if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
                        $this->spatialIndexCache[$path]['due'] = true;
                    }
                }
                return $this->spatialIndexCache[$path];
            }
            unset($this->spatialIndexCache[$path]);
        }
        $this->metrics['misses']++;
        $file = $this->secondary->readSecondaryFile($path);
        if($file === null || !$this->secondary->secondaryFits($dbname, $shardId, $file[0], 'rx', $field)){
            return null;
        }
        $tree = $this->treeOf($field, $file[0], $file[1]);
        if($tree === null){
            return null;
        }
        $this->replaySpatialIndex($dbname, $shardId, $tree, (int)$key['s']);
        $this->spatialIndexCache[$path] = $tree;
        $this->cacheGrew = true;
        if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
            $this->spatialIndexCache[$path]['due'] = true;
        }
        return $this->spatialIndexCache[$path];
    }

    public function replaySpatialIndex(string $dbname, ?int $shardId, array &$tree, int $to): void {
        foreach($this->secondary->segmentTail($dbname, $shardId, $tree['s'], $to) as [$k, $record]){
            $this->rtreeApply($tree, (int)$k, $record);
        }
        $tree['s'] = $to;
    }

    /**
     * Write an R-tree checkpoint
     */
    public function writeSpatialIndex(string $dbname, string $field, array &$tree, ?int $shardId = null): bool {
        if($tree['e'] === null){
            return false;
        }
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        $crc = $this->checkpoints->segmentCrc($this->segments->segDataPath($dbname, $shardId), (int)$tree['s']);
        if($crc === null){
            return false;
        }
        // One line of nodes (a leaf: its number of entries), then the entries of the leaves in that order
        $nodes = [];
        $entries = '';
        foreach($tree['nodes'] as $id => $node){
            if($node[0] === 0){
                $entries .= pack('P*', ...array_merge(...$node[2]));
                $node[2] = count($node[2]);
            }
            $nodes[(string)$id] = $node;
        }
        $body = $this->io->encodeJson($nodes);
        if($body === false){
            return false;
        }
        $body .= "\n" . $entries;
        $rootBox = $tree['nodes'][$tree['root']][1];
        $this->secondary->writeSecondaryFile($dbname, $path, ['kind' => 'rx', 'v' => 1, 'field' => $field, 'q' => self::RTREE_SCALE, 'ns' => $tree['ns'],
            's' => (int)$tree['s'], 'e' => $tree['e'], 'h' => $crc, 'mk' => $tree['mk'], 'cnt' => $tree['cnt'],
            'root' => $tree['root'], 'next' => $tree['next'], 'mbr' => $rootBox, 'bc' => crc32($body)], $body);
        $tree['ck'] = (int)$tree['s'];
        $tree['bytes'] = strlen($body);
        $this->spatialIndexCache[$path] = $tree;
        $this->cacheGrew = true;
        return true;
    }

    /**
     * The tree of an .rx file, or null when it holds none (STORAGE_FORMAT.md §6.5): the crc of the body doesn't fit, or a
     * node isn't [level, box, items], where a box is four integers (null only without items), the items of a leaf a
     * number of entries the body holds, the items above it nodes of a lower level, named once. So a walk meets every
     * node it is sent to and ends. An entry is five integers by its format: no check per entry
     */
    public function treeOf(string $field, array $header, string $body): ?array {
        [$root, $next, $size] = [$header['root'] ?? null, $header['next'] ?? null, $header['ns'] ?? null];
        if(($header['v'] ?? null) !== 1 || ($header['bc'] ?? null) !== crc32($body) || !is_int($root) || !is_int($next) || !is_int($size) || $size < 4
            || !is_int($header['mk'] ?? null) || !is_int($header['cnt'] ?? null)){
            return null;
        }
        $end = (int)strpos($body, "\n");
        $nodes = json_decode(substr($body, 0, $end), true);
        if(!is_array($nodes) || !isset($nodes[$root])){
            return null;
        }
        $entries = 0;
        $named = [];
        foreach($nodes as $id => $node){
            $level = $node[0] ?? null;
            $box = $node[1] ?? null;
            $items = $node[2] ?? null;
            if($id >= $next || !is_int($level) || $level < 0 || ($box === null ? !empty($items) : !self::isBox($box))){
                return null;
            }
            if($level === 0){
                if(!is_int($items) || $items < 0){
                    return null;
                }
                $entries += $items;
                continue;
            }
            if(!is_array($items)){
                return null;
            }
            foreach($items as $item){
                if(!is_int($item) || ($nodes[$item][0] ?? $level) >= $level || isset($named[$item])){
                    return null;
                }
                $named[$item] = true;
            }
        }
        if($entries * 40 !== strlen($body) - $end - 1){
            return null;
        }
        $at = $end + 1;
        foreach($nodes as $id => $node){
            if($node[0] === 0){
                $nodes[$id][2] = $node[2] === 0 ? [] : array_chunk(unpack('P' . 5 * $node[2], $body, $at), 5);
                $at += 40 * $node[2];
            }
        }
        $tree = $this->newRTree($field, $size);
        $tree['nodes'] = $nodes;
        $tree['root'] = $root;
        $tree['next'] = $next;
        $tree['mk'] = $header['mk'];
        $tree['cnt'] = $header['cnt'];
        $tree['s'] = $header['s'];
        $tree['ck'] = $header['s'];
        $tree['e'] = $header['e'];
        $tree['bytes'] = strlen($body);
        return $tree;
    }

    /**
     * Read the global spatial index: ['le', 'sh' => [shard => ['s','e','h','b' => box or null]]]
     */
    public function readGlobalSpatialIndex(string $dbname, string $field): ?array {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        $this->cacheTouch[$path] = ++$this->cacheTick;
        if(array_key_exists($path, $this->globalSpatialIndexCache)){
            $this->metrics['hits']++;
            return $this->globalSpatialIndexCache[$path];
        }
        $this->metrics['misses']++;
        $header = $this->secondary->readSecondaryHeader($path);
        $global = null;
        if(is_array($header) && ($header['kind'] ?? null) === 'grx' && ($header['v'] ?? null) === 1 && ($header['field'] ?? null) === $field && is_array($header['sh'] ?? null)){
            $global = ['le' => $header['le'] ?? null, 'sh' => []];
            foreach($header['sh'] as $shardId => $entry){
                if(\noneDB\Internal\Index\SecondaryFiles::isEntry($entry) && array_key_exists('b', $entry) && ($entry['b'] === null || self::isBox($entry['b']))){
                    $global['sh'][(int)$shardId] = $entry;
                }
            }
        }
        $this->globalSpatialIndexCache[$path] = $global;
        $this->cacheGrew = true;
        return $global;
    }

    public function writeGlobalSpatialIndex(string $dbname, string $field, array $global): void {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        $lock = $this->lockRegistry[$this->locks->getLockPath($dbname)] ?? null;
        $global['le'] = $lock === null ? null : $this->locks->registryEpoch($this->locks->getLockPath($dbname));
        $sh = [];
        foreach($global['sh'] as $shardId => $entry){
            $sh[(string)$shardId] = $entry;
        }
        $this->secondary->writeSecondaryFile($dbname, $path, ['kind' => 'grx', 'v' => 1, 'field' => $field, 'q' => self::RTREE_SCALE,
            'le' => $global['le'], 'sh' => empty($sh) ? new \stdClass() : $sh], '');
        $this->globalSpatialIndexCache[$path] = $global;
        unset($this->lockRegistry[$this->locks->getLockPath($dbname)]['globals'][$path]);
    }

    /**
     * Record a shard's box (at the bytes its R-tree covers) in the global spatial index
     */
    public function updateGlobalSpatialShard(string $dbname, string $field, int $shardId, array $tree): void {
        $entry = $this->globalSpatialEntry($dbname, $shardId, $tree);
        if($entry === null){
            return;
        }
        $global = $this->secondary->freshGlobalEntries($dbname, $this->readGlobalSpatialIndex($dbname, $field) ?? ['le' => null, 'sh' => []]);
        $lockPath = $this->locks->getLockPath($dbname);
        if(($global['sh'][$shardId] ?? null) === $entry && $global['le'] === (isset($this->lockRegistry[$lockPath]) ? $this->locks->registryEpoch($lockPath) : null)){
            return;
        }
        $global['sh'][$shardId] = $entry;
        $this->fieldIndex->deferGlobalWrite($dbname, 'grx', $field, $global);
    }

    /**
     * A shard's entry in the global spatial index: its R-tree's root box at the bytes it covers (null:
     * none can be recorded)
     */
    private function globalSpatialEntry(string $dbname, int $shardId, array $tree): ?array {
        if($tree['e'] === null){
            return null;
        }
        $crc = $this->checkpoints->segmentCrc($this->segments->segDataPath($dbname, $shardId), (int)$tree['s']);
        return $crc === null ? null : ['s' => (int)$tree['s'], 'e' => $tree['e'], 'h' => $crc, 'b' => $tree['nodes'][$tree['root']][1]];
    }

    /**
     * A segment's R-tree: the current one, or built from the data (exclusive lock)
     */
    public function ensureSegmentSpatialIndex(string $dbname, string $field, ?int $shardId): array {
        $tree = $this->readSpatialIndex($dbname, $field, $shardId);
        if($tree !== null){
            return $tree;
        }
        if($this->noWrites > 0){
            throw new \RuntimeException("noneDB: no spatial index to read in a read-only check");
        }
        return $this->locks->withLock($dbname, true, function () use ($dbname, $field, $shardId) {
            $tree = $this->readSpatialIndex($dbname, $field, $shardId);
            if ($tree === null) {
                $nodeSize = $this->fieldIndex->getIndexManifest($dbname)['spatialOptions'][$field]['nodeSize'] ?? null;
                $tree = $this->buildSegmentSpatialIndex($dbname, $field, $shardId, $nodeSize ? ['nodeSize' => $nodeSize] : []);
                $this->writeSpatialIndex($dbname, $field, $tree, $shardId);
                if ($shardId !== null) {
                    $this->updateGlobalSpatialShard($dbname, $field, $shardId, $tree);
                }
            }
            return $tree;
        }, 'buildSpatialIndex');
    }

    /**
     * Keys of a segment whose geometry box may overlap the query boxes (float [minLon, minLat, maxLon,
     * maxLat], or a list of them); every caller checks the exact geometry
     */
    public function rtreeSearchMBR(string $dbname, string $field, array $searchMBR, ?int $shardId = null): array {
        $tree = $this->ensureSegmentSpatialIndex($dbname, $field, $shardId);
        $boxes = is_array($searchMBR[0] ?? null) ? $searchMBR : [$searchMBR];
        return $this->rtreeSearch($tree, array_map([$this, 'quantizeBox'], $boxes));
    }

    /**
     * Rebuild a spatial index for the whole database (all segments + global shard boxes)
     */
    public function rebuildSpatialIndexForDb($dbname, $field, $nodeSize = null){
        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        $nodeSize = $nodeSize ?? $manifest['spatialOptions'][$field]['nodeSize'] ?? null;
        $options = $nodeSize ? ['nodeSize' => $nodeSize] : [];
        $segments = $this->segments->listSegments($dbname);
        if(!in_array(null, $segments, true)){
            $segments[] = null; // leftovers of the non-sharded database
        }
        foreach($segments as $segment){
            $path = $this->getSpatialIndexPath($dbname, $field, $segment);
            $this->io->fsUnlink($path);
            unset($this->spatialIndexCache[$path]);
        }
        foreach([$this->getGlobalSpatialIndexPath($dbname, $field)] as $path){
            $this->io->fsUnlink($path);
            unset($this->globalSpatialIndexCache[$path]);
        }
        $indexed = 0;
        $sharded = $this->segments->isSharded($dbname);
        $global = ['le' => null, 'sh' => []]; // written once at the end (once per shard was O(shards²))
        foreach ($this->segments->listSegments($dbname) as $segment) {
            $tree = $this->buildSegmentSpatialIndex($dbname, $field, $segment, $options);
            $this->writeSpatialIndex($dbname, $field, $tree, $segment);
            $indexed += $tree['cnt'];
            if ($sharded && ($entry = $this->globalSpatialEntry($dbname, $segment, $tree)) !== null) {
                $global['sh'][$segment] = $entry;
            }
            unset($tree);
            $this->cache->evictBetweenSegments();
        }
        if ($sharded) {
            ksort($global['sh']);
            $this->writeGlobalSpatialIndex($dbname, $field, $global);
        }
        return $indexed;
    }

    /**
     * Delete the spatial index files of one shard
     */
    public function removeSpatialShardFiles($dbname, $shardId){
        foreach ($this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'spatial/s' . (int)$shardId . '.', '/\.rx$/') as $file) {
            $this->io->fsUnlink($file);
            unset($this->spatialIndexCache[$file]);
        }
        foreach ($this->fieldIndex->getIndexManifest($dbname)['spatial'] as $field) {
            $global = $this->readGlobalSpatialIndex($dbname, $field);
            if ($global !== null && isset($global['sh'][(int)$shardId])) {
                unset($global['sh'][(int)$shardId]);
                $this->writeGlobalSpatialIndex($dbname, $field, $global);
            }
        }
    }

    /**
     * True when a shard can be skipped for the query boxes: its global entry fits the shard, its box
     * misses every box, and the shard's newer lines hold no geometry in them
     */
    public function globalSpatialSkips(string $dbname, string $field, int $shardId, ?array $global, array $boxes): bool {
        $entry = $global['sh'][$shardId] ?? null;
        if($entry === null || !$this->fieldIndex->globalEntryFits($dbname, $shardId, $entry, $global['le'])){
            return false;
        }
        $quantized = array_map([$this, 'quantizeBox'], $boxes);
        foreach($quantized as $box){
            if(self::boxOverlaps($entry['b'], $box)){
                return false;
            }
        }
        return !$this->fieldIndex->shardTailMayMatch($dbname, $shardId, $entry, function($record) use ($field, $quantized){
            $geometry = $record[$field] ?? null;
            if(!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid']){
                return false;
            }
            $box = $this->quantizedMBR($geometry);
            foreach($quantized as $q){
                if(self::boxOverlaps($box, $q)){
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Check if a spatial index exists
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID
     * @return bool
     */
    public function hasSpatialIndex(string $dbname, string $field, ?int $shardId = null): bool{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $shardId){
            return $this->hasSpatialIndexUnlocked($dbname, $field, $shardId);
        });
    }

    private function hasSpatialIndexUnlocked(string $dbname, string $field, ?int $shardId = null): bool{
        if (!$this->segments->dbExists($dbname) || !in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true)) {
            return false;
        }
        return $shardId === null || in_array($shardId, $this->segments->listSegments($dbname), true);
    }
    // ==========================================
    // SPATIAL INDEX - R-TREE ENGINE
    // ==========================================
    // One R-tree per segment and indexed field: spatial/{seg}.{token}.rx (see SecondaryFiles), a
    // checkpoint like the field indexes:
    //   {"kind":"rx","v":1,"field":"loc","q":10000000,"ns":32,"s":…,"e":…,"h":…,"mk":…,"cnt":…,"root":3,"next":9}
    //   {"0":[0,[minX,minY,maxX,maxY],[[key,minX,minY,maxX,maxY],…]],"3":[1,[…],[0,2]],…}
    // A node is [level, box, payload]: level 0 holds entries [key, box], a higher level child node ids.
    // Boxes are integers: degrees * 1e7 rounded outwards (1e-7° is about 1 cm), so they don't depend on
    // serialize_precision, and every query re-checks the exact geometry. Built with sort-tile-recursive
    // bulk loading; changes after the checkpoint are replayed from the data when the index is used.
    // Sharded databases keep spatial/{token}.grx: each shard's box (null = empty) as of the bytes its
    // entry covers; a shard is skipped only when its entry fits, its box misses the query and the shard's
    // newer lines hold no geometry in it.

    public const RTREE_SCALE = 10000000;
}
