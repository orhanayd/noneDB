<?php
// Build manifest of noneDB-migrate.php (tools/build.php): the header, the class files (namespace noneDB\Migrate...)
// in this order, then the footer (the command line entry)
return [
    'header' => 'header.php',
    'files' => [
        'Stop.php',
        'Files.php',
        'Readers/Discovery.php',
        'Readers/Source.php',
        'Converter/Writer.php',
        'Validator/Verifier.php',
        'Backup/Backup.php',
        'Journal/Journal.php',
        'Publisher/Migration.php',
        'Restore/Restore.php',
        'Cli.php',
    ],
    'footer' => 'footer.php',
];
