<?php

namespace Neo4j\LaravelBoost\Console;

use Illuminate\Console\Command;

/**
 * Test command to verify STDIO transport is working.
 * Shows verbose output of the MCP handshake and tool call.
 */
class TestStdioCommand extends Command
{
    protected $signature = 'neo4j-boost:test-stdio 
                            {--tool=get-schema : Tool to call (get-schema, read-cypher, write-cypher)}
                            {--query= : Query for read-cypher/write-cypher}';

    protected $description = 'Test the STDIO transport with the Neo4j MCP server (verbose output for debugging)';

    public function handle(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║         Neo4j Boost - STDIO Transport Test                   ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Show current configuration
        $transport = config('neo4j-boost.transport.driver', 'http');
        $command = config('neo4j-boost.transport.stdio.command', 'neo4j-mcp');
        $uri = config('neo4j-boost.transport.stdio.env.NEO4J_URI', 'not set');
        $user = config('neo4j-boost.transport.stdio.env.NEO4J_USERNAME', 'not set');

        $this->line('<fg=cyan>Configuration:</>');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Transport Driver', "<fg=yellow>{$transport}</>"],
                ['STDIO Command', $command],
                ['NEO4J_URI', $uri],
                ['NEO4J_USERNAME', $user],
            ]
        );

        if ($transport !== 'stdio') {
            $this->warn('⚠ Transport is set to "'.$transport.'", not "stdio".');
            $this->line('  Set NEO4J_MCP_TRANSPORT=stdio in .env to use STDIO transport.');
            $this->newLine();
        }

        // Check if binary exists
        $binaryPath = trim(shell_exec('which '.escapeshellarg($command).' 2>/dev/null') ?? '');
        if (empty($binaryPath)) {
            $this->error('✗ Command "'.$command.'" not found in PATH.');

            return self::FAILURE;
        }
        $this->info('✓ Found binary: '.$binaryPath);
        $this->newLine();

        // Test MCP handshake
        $this->line('<fg=cyan>Testing MCP STDIO Handshake...</>');
        $this->newLine();

        $env = array_merge(
            getenv() ?: [],
            config('neo4j-boost.transport.stdio.env', [])
        );

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorspec, $pipes, null, $env);

        if (! is_resource($process)) {
            $this->error('✗ Failed to start process: '.$command);

            return self::FAILURE;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Send initialize
        $initPayload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'neo4j-boost-test', 'version' => '1.0'],
            ],
        ];
        $initJson = json_encode($initPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->line('<fg=green>→ Sending initialize request:</>');
        $this->line('<fg=gray>'.$initJson.'</>');
        $this->newLine();

        fwrite($pipes[0], json_encode($initPayload, JSON_UNESCAPED_SLASHES)."\n");
        fflush($pipes[0]);

        // Read response with timeout
        $response = $this->readWithTimeout($pipes[1], 10);
        $stderr = $this->readWithTimeout($pipes[2], 1);

        if ($stderr) {
            $this->line('<fg=red>STDERR output:</>');
            $this->line('<fg=red>'.$stderr.'</>');
        }

        if (empty($response)) {
            $this->error('✗ No response received (timeout or process exited)');
            proc_terminate($process);
            proc_close($process);

            return self::FAILURE;
        }

        $this->line('<fg=blue>← Received response:</>');
        $decoded = json_decode($response, true);
        if ($decoded) {
            $this->line('<fg=gray>'.json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).'</>');
        } else {
            $this->line('<fg=gray>'.$response.'</>');
        }
        $this->newLine();

        if (isset($decoded['error'])) {
            $this->error('✗ Initialize failed: '.($decoded['error']['message'] ?? json_encode($decoded['error'])));
            proc_terminate($process);
            proc_close($process);

            return self::FAILURE;
        }

        $this->info('✓ MCP Handshake successful!');
        $this->newLine();

        // Send initialized notification
        $notifyPayload = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
        $this->line('<fg=green>→ Sending initialized notification</>');
        fwrite($pipes[0], json_encode($notifyPayload, JSON_UNESCAPED_SLASHES)."\n");
        fflush($pipes[0]);
        $this->newLine();

        // Call the requested tool
        $toolName = $this->option('tool');
        $arguments = [];
        if (in_array($toolName, ['read-cypher', 'write-cypher']) && $this->option('query')) {
            $arguments['query'] = $this->option('query');
        }

        $toolPayload = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => empty($arguments) ? new \stdClass : $arguments,
            ],
        ];

        $this->line('<fg=cyan>Calling tool: '.$toolName.'</>');
        $toolJson = json_encode($toolPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->line('<fg=green>→ Sending tools/call request:</>');
        $this->line('<fg=gray>'.$toolJson.'</>');
        $this->newLine();

        fwrite($pipes[0], json_encode($toolPayload, JSON_UNESCAPED_SLASHES)."\n");
        fflush($pipes[0]);

        $toolResponse = $this->readWithTimeout($pipes[1], 30);
        $stderr = $this->readWithTimeout($pipes[2], 1);

        if ($stderr) {
            $this->line('<fg=red>STDERR:</>');
            $this->line('<fg=red>'.$stderr.'</>');
        }

        $this->line('<fg=blue>← Tool response:</>');
        $toolDecoded = json_decode($toolResponse, true);
        if ($toolDecoded) {
            $this->line('<fg=gray>'.json_encode($toolDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).'</>');
        } else {
            $this->line('<fg=gray>'.($toolResponse ?: '(empty)').'</>');
        }
        $this->newLine();

        // Cleanup
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);

        if (isset($toolDecoded['result'])) {
            $this->info('╔══════════════════════════════════════════════════════════════╗');
            $this->info('║  ✓ STDIO Transport is working correctly!                     ║');
            $this->info('╚══════════════════════════════════════════════════════════════╝');

            return self::SUCCESS;
        }

        if (isset($toolDecoded['error'])) {
            $this->warn('Tool returned error (but STDIO transport worked): '.($toolDecoded['error']['message'] ?? ''));
        }

        return self::SUCCESS;
    }

    private function readWithTimeout($pipe, int $timeoutSeconds): string
    {
        $buffer = '';
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $chunk = @fread($pipe, 8192);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (strpos($buffer, "\n") !== false) {
                    break;
                }
            }
            usleep(50000);
        }

        return trim($buffer);
    }
}
