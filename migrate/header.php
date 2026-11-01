<?php
/**
 * https://github.com/orhanayd/noneDB
 *
 * noneDB-migrate.php: converts the databases of earlier noneDB releases (1.4 to 3.3-dev) to noneDB 4.
 * Its contract is docs/MIGRATION.md. It loads noneDB.php from its own directory.
 *
 *   NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --dry-run
 *   NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --backup-dir=/var/backups/nonedb --confirm-stopped
 *
 * Built from migrate/ by tools/build.php; edit those files, not this one.
 */

