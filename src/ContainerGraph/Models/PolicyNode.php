<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:Policy {key, name}) -[:HANDLED_BY {action}]-> (:Abstract) policy class
 * (:Policy)             -[:FOR_MODEL]->           (:Abstract) subject model
 *
 * @property string $key
 * @property string $name
 */
final class PolicyNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_POLICY;

    protected $primaryKey = RuntimeGraphModel::POLICY_KEY;

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
     * @return MatchRelationship<AbstractNode, $this>
     */
    public function forModel(): MatchRelationship
    {
        return $this->matchRelationship(AbstractNode::class, RuntimeGraphModel::REL_FOR_MODEL.'>', 'key', 'name');
    }
}
