<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

/**
 * Discovers Laravel authentication wiring from config/auth.php:
 * guards, user providers, and password brokers.
 */
final class AuthConfigExtractor
{
    /**
     * @return array{
     *     providers: array<int, array{key: string, driver: string, model: string, model_kind: string, table: string}>,
     *     guards: array<int, array{key: string, driver: string, provider: string, is_default: bool}>,
     *     password_brokers: array<int, array{key: string, provider: string, table: string, expire: int, throttle: int, is_default: bool}>
     * }
     */
    public function extract(?array $auth = null): array
    {
        $auth ??= (array) config('auth', []);
        $defaults = is_array($auth['defaults'] ?? null) ? $auth['defaults'] : [];
        $defaultGuard = is_string($defaults['guard'] ?? null) ? $defaults['guard'] : '';
        $defaultPasswords = is_string($defaults['passwords'] ?? null) ? $defaults['passwords'] : '';

        return [
            'providers' => $this->providers(is_array($auth['providers'] ?? null) ? $auth['providers'] : []),
            'guards' => $this->guards(
                is_array($auth['guards'] ?? null) ? $auth['guards'] : [],
                $defaultGuard,
            ),
            'password_brokers' => $this->passwordBrokers(
                is_array($auth['passwords'] ?? null) ? $auth['passwords'] : [],
                $defaultPasswords,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $providers
     * @return array<int, array{key: string, driver: string, model: string, model_kind: string, table: string}>
     */
    private function providers(array $providers): array
    {
        $rows = [];

        foreach ($providers as $name => $config) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $config = is_array($config) ? $config : [];
            $driver = $config['driver'] ?? '';
            $model = $config['model'] ?? '';
            $table = $config['table'] ?? '';
            $model = is_string($model) ? ltrim($model, '\\') : '';

            $rows[] = [
                'key' => $name,
                'driver' => is_string($driver) ? $driver : '',
                'model' => $model,
                'model_kind' => $model !== '' ? $this->kindForTypeName($model) : '',
                'table' => is_string($table) ? $table : '',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $guards
     * @return array<int, array{key: string, driver: string, provider: string, is_default: bool}>
     */
    private function guards(array $guards, string $defaultGuard): array
    {
        $rows = [];

        foreach ($guards as $name => $config) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $config = is_array($config) ? $config : [];
            $driver = $config['driver'] ?? '';
            $provider = $config['provider'] ?? '';

            $rows[] = [
                'key' => $name,
                'driver' => is_string($driver) ? $driver : '',
                'provider' => is_string($provider) ? $provider : '',
                'is_default' => $name === $defaultGuard,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $passwords
     * @return array<int, array{key: string, provider: string, table: string, expire: int, throttle: int, is_default: bool}>
     */
    private function passwordBrokers(array $passwords, string $defaultPasswords): array
    {
        $rows = [];

        foreach ($passwords as $name => $config) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $config = is_array($config) ? $config : [];
            $provider = $config['provider'] ?? '';
            $table = $config['table'] ?? '';

            $rows[] = [
                'key' => $name,
                'provider' => is_string($provider) ? $provider : '',
                'table' => is_string($table) ? $table : '',
                'expire' => $this->intConfig($config['expire'] ?? 0),
                'throttle' => $this->intConfig($config['throttle'] ?? 0),
                'is_default' => $name === $defaultPasswords,
            ];
        }

        return $rows;
    }

    private function intConfig(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function kindForTypeName(string $typeName): string
    {
        if (interface_exists($typeName)) {
            return 'Interface';
        }

        if (class_exists($typeName)) {
            return 'Class';
        }

        return 'AbstractType';
    }
}
