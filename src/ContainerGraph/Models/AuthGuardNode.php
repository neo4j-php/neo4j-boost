<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;

/**
 * (:AuthGuard {key, driver, is_default}) -[:USES_PROVIDER]-> (:AuthProvider)
 *
 * @property string $key
 */
final class AuthGuardNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_AUTH_GUARD;

    protected $primaryKey = RuntimeGraphModel::AUTH_GUARD_KEY;

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
