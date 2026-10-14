<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:ScheduledTask {key, name, expression, command, description, timezone, kind,
 *     without_overlapping, on_one_server, run_in_background, even_in_maintenance_mode})
 *     -[:HANDLED_BY {action}]-> (:Abstract)           command / job / invokable class
 *
 * @property string $key
 */
final class ScheduledTaskNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_SCHEDULED_TASK;

    protected $primaryKey = RuntimeGraphModel::SCHEDULED_TASK_KEY;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return MatchRelationship<AbstractNode, $this>
     */
    public function handledBy(): MatchRelationship
    {
        return $this->matchRelationship(AbstractNode::class, RuntimeGraphModel::REL_HANDLED_BY.'>', 'key', 'name');
    }
}
