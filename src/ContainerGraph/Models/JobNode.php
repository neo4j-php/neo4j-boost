<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:Job {key, name, should_queue, connection, queue, unique})
 *     -[:HANDLED_BY {action}]-> (:Abstract)           job class
 * (:Job) -[:USES_CONNECTION]-> (:QueueConnection)
 *
 * @property string $key
 */
final class JobNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_JOB;

    protected $primaryKey = RuntimeGraphModel::JOB_KEY;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return MatchRelationship<AbstractNode, $this>
     */
    public function handledBy(): MatchRelationship
    {
        return $this->matchRelationship(AbstractNode::class, RuntimeGraphModel::REL_HANDLED_BY.'>', 'key', 'name');
    }

    /**
     * @return MatchRelationship<QueueConnectionNode, $this>
     */
    public function usesConnection(): MatchRelationship
    {
        return $this->matchRelationship(QueueConnectionNode::class, RuntimeGraphModel::REL_USES_CONNECTION.'>', 'key', 'key');
    }
}
