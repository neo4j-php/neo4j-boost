<?php

namespace Neo4j\LaravelBoost\Support;

use Laudis\Neo4j\Enum\QueryTypeEnum;
use Neo4j\LaravelBoost\Contracts\BoltExecutorInterface;

final class CypherQueryClassifier
{
    public const READ_REJECTION_MESSAGE = 'read-cypher can only run read-only Cypher statements. For write operations (CREATE, MERGE, DELETE, SET, etc...), schema/admin commands, or PROFILE queries, use write-cypher instead.';

    private const WRITE_KEYWORD_PATTERN = '/\b(CREATE|MERGE|DELETE|DETACH|SET|REMOVE|FOREACH|DROP|INSERT)\b/i';

    public function __construct(
        private BoltExecutorInterface $executor,
    ) {}

    public function isReadOnly(string $cypher): bool
    {
        $trimmed = trim($cypher);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^\s*(PROFILE|EXPLAIN)\b/i', $trimmed) === 1) {
            return false;
        }

        if (preg_match(self::WRITE_KEYWORD_PATTERN, $trimmed) === 1) {
            return false;
        }

        return $this->executor->getQueryType($cypher)->getValue() === QueryTypeEnum::READ_ONLY()->getValue();
    }
}
