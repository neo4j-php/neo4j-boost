<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;

/**
 * (:Mailer {key, transport, nested_mailers, is_default}) from config/mail.php
 *
 * @property string $key
 */
final class MailerNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_MAILER;

    protected $primaryKey = RuntimeGraphModel::MAILER_KEY;

    public $timestamps = false;

    protected $guarded = [];
}
