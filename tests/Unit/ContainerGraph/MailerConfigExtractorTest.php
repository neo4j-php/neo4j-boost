<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Neo4j\LaravelBoost\ContainerGraph\MailerConfigExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;

class MailerConfigExtractorTest extends TestCase
{
    public function test_extracts_configured_mailers_and_marks_default(): void
    {
        $rows = (new MailerConfigExtractor)->extract('ses', [
            'smtp' => ['transport' => 'smtp'],
            'ses' => ['transport' => 'ses'],
            'failover' => [
                'transport' => 'failover',
                'mailers' => ['ses', 'smtp'],
            ],
        ]);

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertCount(3, $rows);
        $this->assertTrue($byKey['ses']['is_default']);
        $this->assertSame('ses', $byKey['ses']['transport']);
        $this->assertFalse($byKey['smtp']['is_default']);
        $this->assertSame('failover', $byKey['failover']['transport']);
        $this->assertSame('ses,smtp', $byKey['failover']['nested_mailers']);
    }

    public function test_reads_live_mail_config_by_default(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers' => [
                'smtp' => ['transport' => 'smtp'],
            ],
        ]);

        $rows = (new MailerConfigExtractor)->extract();

        $this->assertNotEmpty($rows);
        $this->assertSame('smtp', $rows[0]['key']);
        $this->assertTrue($rows[0]['is_default']);
    }
}
