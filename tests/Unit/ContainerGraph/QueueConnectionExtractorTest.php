<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Neo4j\LaravelBoost\ContainerGraph\QueueConnectionExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;

class QueueConnectionExtractorTest extends TestCase
{
    public function test_extracts_configured_connections_and_marks_default(): void
    {
        $rows = (new QueueConnectionExtractor)->extract('redis', [
            'sync' => ['driver' => 'sync'],
            'redis' => ['driver' => 'redis', 'queue' => 'default'],
            'database' => ['driver' => 'database', 'queue' => 'jobs'],
        ]);

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertCount(3, $rows);
        $this->assertTrue($byKey['redis']['is_default']);
        $this->assertSame('redis', $byKey['redis']['driver']);
        $this->assertSame('default', $byKey['redis']['default_queue']);
        $this->assertFalse($byKey['sync']['is_default']);
        $this->assertSame('jobs', $byKey['database']['default_queue']);
    }

    public function test_reads_live_queue_config_by_default(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections' => [
                'sync' => ['driver' => 'sync'],
            ],
        ]);

        $rows = (new QueueConnectionExtractor)->extract();

        $this->assertNotEmpty($rows);
        $this->assertSame('sync', $rows[0]['key']);
        $this->assertTrue($rows[0]['is_default']);
    }
}
