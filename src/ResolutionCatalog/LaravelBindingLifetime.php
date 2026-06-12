<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Neo4j\LaravelBoost\Support\Graph\BindsToType;

/**
 * Default Laravel container lifetime hints for first-party binding keys.
 *
 * @see https://laravel.com/docs/facades#facade-class-reference
 */
final class LaravelBindingLifetime
{
    /** @var list<string> */
    private const SINGLETON_BINDING_KEYS = [
        'app',
        'artisan',
        'auth',
        'auth.password',
        'blade.compiler',
        'cache',
        'config',
        'cookie',
        'date',
        'db',
        'db.schema',
        'encrypter',
        'events',
        'files',
        'filesystem',
        'hash',
        'log',
        'mail.manager',
        'pipeline',
        'queue',
        'redirect',
        'redis',
        'request',
        'router',
        'session',
        'translator',
        'url',
        'validator',
        'view',
    ];

    public static function forBindingKey(?string $bindingKey): BindsToType
    {
        if ($bindingKey === null || $bindingKey === '') {
            return BindsToType::Singleton;
        }

        return in_array($bindingKey, self::SINGLETON_BINDING_KEYS, true)
            ? BindsToType::Singleton
            : BindsToType::Normal;
    }

    public static function forClassAccessor(string $accessor): BindsToType
    {
        return str_contains($accessor, '\\')
            ? BindsToType::Singleton
            : self::forBindingKey($accessor);
    }
}
