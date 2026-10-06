<?php

namespace Neo4j\LaravelBoost\ContainerGraph\Models;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jModel;

/**
 * (:Abstract {name, kind}) — container lookup key shared by every graph section.
 *
 * @property string $name
 * @property string $kind
 */
final class AbstractNode extends Neo4jModel
{
    protected $connection = Neo4jBoltClient::ELOQUENT_CONNECTION;

    protected $table = RuntimeGraphModel::LABEL_ABSTRACT;

    protected $primaryKey = RuntimeGraphModel::NAME_KEY;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Same effect as MERGE (a:Abstract {name}) SET a.kind = ...
     */
    public static function ensure(string $name, string $kind): self
    {
        return self::updateOrCreate(['name' => $name], ['kind' => $kind]);
    }
}
