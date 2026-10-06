<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:Notification {key, name, should_queue, connection, queue, unique})
 *     -[:HANDLED_BY {action}]-> (:Abstract)            notification class
 * (:Notification) -[:USES_CHANNEL {order}]-> (:NotificationChannel)
 *
 * @property string $key
 * @property string $name
 */
final class NotificationNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_NOTIFICATION;

    protected $primaryKey = RuntimeGraphModel::NOTIFICATION_KEY;

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
     * @return MatchRelationship<NotificationChannelNode, $this>
     */
    public function usesChannel(): MatchRelationship
    {
        return $this->matchRelationship(NotificationChannelNode::class, RuntimeGraphModel::REL_USES_CHANNEL.'>', 'key', 'key');
    }
}
