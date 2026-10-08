<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:PasswordBroker {key, table, expire, throttle, is_default}) -[:USES_PROVIDER]-> (:AuthProvider)
 *
 * @property string $key
 */
final class PasswordBrokerNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_PASSWORD_BROKER;

    protected $primaryKey = RuntimeGraphModel::PASSWORD_BROKER_KEY;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return MatchRelationship<AuthProviderNode, $this>
     */
    public function usesProvider(): MatchRelationship
    {
        return $this->matchRelationship(AuthProviderNode::class, RuntimeGraphModel::REL_USES_PROVIDER.'>', 'key', 'key');
    }
}
