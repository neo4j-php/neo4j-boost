<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:Mailable {key, name, should_queue, mailer, connection, queue, unique})
 *     -[:HANDLED_BY {action}]-> (:Abstract)        mailable class
 * (:Mailable) -[:USES_MAILER]->     (:Mailer)
 * (:Mailable) -[:USES_CONNECTION]-> (:QueueConnection)
 *
 * @property string $key
 */
final class MailableNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_MAILABLE;

    protected $primaryKey = RuntimeGraphModel::MAILABLE_KEY;

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
     * @return MatchRelationship<MailerNode, $this>
     */
    public function usesMailer(): MatchRelationship
    {
        return $this->matchRelationship(MailerNode::class, RuntimeGraphModel::REL_USES_MAILER.'>', 'key', 'key');
    }

    /**
     * @return MatchRelationship<QueueConnectionNode, $this>
     */
    public function usesConnection(): MatchRelationship
    {
        return $this->matchRelationship(QueueConnectionNode::class, RuntimeGraphModel::REL_USES_CONNECTION.'>', 'key', 'key');
    }
}
