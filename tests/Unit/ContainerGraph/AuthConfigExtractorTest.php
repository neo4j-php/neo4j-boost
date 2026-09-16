<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Foundation\Auth\User;
use Neo4j\LaravelBoost\ContainerGraph\AuthConfigExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;

class AuthConfigExtractorTest extends TestCase
{
    public function test_extracts_guards_providers_and_password_brokers(): void
    {
        $extracted = (new AuthConfigExtractor)->extract([
            'defaults' => [
                'guard' => 'web',
                'passwords' => 'users',
            ],
            'guards' => [
                'web' => [
                    'driver' => 'session',
                    'provider' => 'users',
                ],
                'api' => [
                    'driver' => 'sanctum',
                    'provider' => 'users',
                ],
            ],
            'providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => User::class,
                ],
                'legacy' => [
                    'driver' => 'database',
                    'table' => 'legacy_users',
                ],
            ],
            'passwords' => [
                'users' => [
                    'provider' => 'users',
                    'table' => 'password_reset_tokens',
                    'expire' => 60,
                    'throttle' => 60,
                ],
            ],
        ]);

        $providers = [];
        foreach ($extracted['providers'] as $row) {
            $providers[$row['key']] = $row;
        }

        $this->assertSame('eloquent', $providers['users']['driver']);
        $this->assertSame(User::class, $providers['users']['model']);
        $this->assertSame('Class', $providers['users']['model_kind']);
        $this->assertSame('', $providers['users']['table']);
        $this->assertSame('database', $providers['legacy']['driver']);
        $this->assertSame('legacy_users', $providers['legacy']['table']);
        $this->assertSame('', $providers['legacy']['model']);

        $guards = [];
        foreach ($extracted['guards'] as $row) {
            $guards[$row['key']] = $row;
        }

        $this->assertTrue($guards['web']['is_default']);
        $this->assertFalse($guards['api']['is_default']);
        $this->assertSame('session', $guards['web']['driver']);
        $this->assertSame('users', $guards['web']['provider']);
        $this->assertSame('sanctum', $guards['api']['driver']);

        $this->assertCount(1, $extracted['password_brokers']);
        $broker = $extracted['password_brokers'][0];
        $this->assertSame('users', $broker['key']);
        $this->assertTrue($broker['is_default']);
        $this->assertSame(60, $broker['expire']);
        $this->assertSame(60, $broker['throttle']);
        $this->assertSame('password_reset_tokens', $broker['table']);
    }

    public function test_reads_live_auth_config_by_default(): void
    {
        config([
            'auth.defaults.guard' => 'web',
            'auth.defaults.passwords' => 'users',
            'auth.guards' => [
                'web' => [
                    'driver' => 'session',
                    'provider' => 'users',
                ],
            ],
            'auth.providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => User::class,
                ],
            ],
            'auth.passwords' => [
                'users' => [
                    'provider' => 'users',
                    'table' => 'password_reset_tokens',
                    'expire' => 60,
                    'throttle' => 60,
                ],
            ],
        ]);

        $extracted = (new AuthConfigExtractor)->extract();

        $this->assertNotEmpty($extracted['guards']);
        $this->assertSame('web', $extracted['guards'][0]['key']);
        $this->assertTrue($extracted['guards'][0]['is_default']);
        $this->assertNotEmpty($extracted['providers']);
        $this->assertNotEmpty($extracted['password_brokers']);
    }

    public function test_skips_invalid_entries_and_normalizes_model_fqcn(): void
    {
        $extracted = (new AuthConfigExtractor)->extract([
            'defaults' => ['guard' => 'web', 'passwords' => 'users'],
            'guards' => [
                '' => ['driver' => 'session', 'provider' => 'users'],
                1 => ['driver' => 'session', 'provider' => 'users'],
                'web' => ['driver' => 'session', 'provider' => 'users'],
            ],
            'providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => '\\Illuminate\\Foundation\\Auth\\User',
                ],
            ],
            'passwords' => [
                'users' => [
                    'provider' => 'users',
                    'table' => 'password_reset_tokens',
                    'expire' => '45',
                    'throttle' => '30',
                ],
            ],
        ]);

        $this->assertCount(1, $extracted['guards']);
        $this->assertSame(User::class, $extracted['providers'][0]['model']);
        $this->assertSame(45, $extracted['password_brokers'][0]['expire']);
        $this->assertSame(30, $extracted['password_brokers'][0]['throttle']);
    }
}
