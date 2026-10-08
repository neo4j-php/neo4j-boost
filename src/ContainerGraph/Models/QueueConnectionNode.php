<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;

/**
 * (:QueueConnection {key, driver, default_queue, is_default}) from config/queue.php
 *
 * @property string $key
 */
final class QueueConnectionNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_QUEUE_CONNECTION;

    protected $primaryKey = RuntimeGraphModel::QUEUE_CONNECTION_KEY;

    public $timestamps = false;

    protected $guarded = [];
}
