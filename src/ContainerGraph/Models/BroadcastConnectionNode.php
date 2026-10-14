<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;

/**
 * (:BroadcastConnection {key, driver, is_default}) from config/broadcasting.php
 *
 * @property string $key
 */
final class BroadcastConnectionNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_BROADCAST_CONNECTION;

    protected $primaryKey = RuntimeGraphModel::BROADCAST_CONNECTION_KEY;

    public $timestamps = false;

    protected $guarded = [];
}
