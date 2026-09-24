<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

/**
 * Discovers configured mailers from the live Laravel mail config.
 */
final class MailerConfigExtractor
{
    /**
     * @return array<int, array{key: string, transport: string, nested_mailers: string, is_default: bool}>
     */
    public function extract(?string $defaultMailer = null, ?array $mailers = null): array
    {
        $defaultMailer ??= (string) config('mail.default', 'smtp');
        $mailers ??= (array) config('mail.mailers', []);

        $rows = [];

        foreach ($mailers as $name => $config) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $config = is_array($config) ? $config : [];
            $transport = $config['transport'] ?? '';
            $nested = $config['mailers'] ?? [];
            $nestedMailers = '';
            if (is_array($nested)) {
                $names = [];
                foreach ($nested as $entry) {
                    if (is_string($entry) && $entry !== '') {
                        $names[] = $entry;
                    }
                }
                $nestedMailers = implode(',', $names);
            }

            $rows[] = [
                'key' => $name,
                'transport' => is_string($transport) ? $transport : '',
                'nested_mailers' => $nestedMailers,
                'is_default' => $name === $defaultMailer,
            ];
        }

        return $rows;
    }
}
