<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:ModelEvent {key, name, lifecycle, source})
 *   -[:HANDLED_BY {action}]-> (:Abstract) observer class
 *   -[:FOR_MODEL]->           (:Abstract) Eloquent model
 *   -[:DISPATCHES]->          (:Abstract) $dispatchesEvents target
 *
 * @property string $key
 * @property string $name
 * @property string $lifecycle
 * @property string $source
 */
final class ModelEventNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_MODEL_EVENT;

    protected $primaryKey = RuntimeGraphModel::MODEL_EVENT_KEY;

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

    /**
     * @return MatchRelationship<AbstractNode, $this>
     */
    public function dispatches(): MatchRelationship
    {
        return $this->matchRelationship(AbstractNode::class, RuntimeGraphModel::REL_DISPATCHES.'>', 'key', 'name');
    }
}
