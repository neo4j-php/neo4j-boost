<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:BroadcastChannel {key, name, guards}) -[:HANDLED_BY {action}]-> (:Abstract) channel auth class
 *
 * @property string $key
 */
final class BroadcastChannelNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_BROADCAST_CHANNEL;

    protected $primaryKey = RuntimeGraphModel::BROADCAST_CHANNEL_KEY;

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
