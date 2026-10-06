<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:Event {key, name}) -[:HANDLED_BY {action}]-> (:Abstract) listener class
 *
 * @property string $key
 */
final class EventNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_EVENT;

    protected $primaryKey = RuntimeGraphModel::EVENT_KEY;

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
