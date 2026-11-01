<?php
// A run that stops, with the result that names why (docs/MIGRATION.md §6.1, §13)
namespace noneDB\Migrate;

/**
 * @internal
 */
final class Stop extends \RuntimeException {
    /** Exit status of each result (docs/MIGRATION.md §13) */
    public const STATUS = ['AMBIGUOUS_SOURCE' => 2, 'UNSUPPORTED_SOURCE' => 2, 'MAINTENANCE_BUSY' => 3,
        'MAINTENANCE_IN_PROGRESS' => 4, 'FAILED' => 5];

    /** @var string one of the keys of STATUS */
    public $result;

    public function __construct(string $result, string $message, ?\Throwable $previous = null){
        parent::__construct($message, 0, $previous);
        $this->result = $result;
    }
}
