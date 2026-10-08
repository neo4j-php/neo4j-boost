<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:NotificationChannel {key, name, kind, is_default}) -[:IDENTIFIED_AS]-> (:Abstract) channel class
 *
 * @property string $key
 * @property string|null $name
 * @property string|null $kind
 */
final class NotificationChannelNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_NOTIFICATION_CHANNEL;

    protected $primaryKey = RuntimeGraphModel::NOTIFICATION_CHANNEL_KEY;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return MatchRelationship<AbstractNode, $this>
     */
    public function identifiedAs(): MatchRelationship
    {
        return $this->matchRelationship(AbstractNode::class, RuntimeGraphModel::REL_IDENTIFIED_AS.'>', 'key', 'name');
    }
}
