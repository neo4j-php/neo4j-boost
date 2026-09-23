<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Neo4j\LaravelBoost\ContainerGraph\BroadcastConnectionExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;

class BroadcastConnectionExtractorTest extends TestCase
{
    public function test_extracts_configured_connections_and_marks_default(): void
    {
        $rows = (new BroadcastConnectionExtractor)->extract('pusher', [
            'null' => ['driver' => 'null'],
            'pusher' => ['driver' => 'pusher'],
            'log' => ['driver' => 'log'],
        ]);

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertCount(3, $rows);
        $this->assertTrue($byKey['pusher']['is_default']);
        $this->assertSame('pusher', $byKey['pusher']['driver']);
        $this->assertFalse($byKey['null']['is_default']);
        $this->assertSame('log', $byKey['log']['driver']);
    }

    public function test_reads_live_broadcasting_config_by_default(): void
    {
        config([
            'broadcasting.default' => 'log',
            'broadcasting.connections' => [
                'log' => ['driver' => 'log'],
            ],
        ]);

        $rows = (new BroadcastConnectionExtractor)->extract();

        $this->assertNotEmpty($rows);
        $this->assertSame('log', $rows[0]['key']);
        $this->assertTrue($rows[0]['is_default']);
    }
}
