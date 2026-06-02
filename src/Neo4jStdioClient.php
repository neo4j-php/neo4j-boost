<?php

namespace Neo4j\LaravelBoost;

use Laravel\Mcp\Response;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;
use Neo4j\LaravelBoost\Support\Neo4jMcpConfig;
use Neo4j\LaravelBoost\Support\Neo4jMcpHealth;
use Throwable;

/**
 * STDIO client for the Neo4j MCP server.
 * Spawns the MCP server as a subprocess and communicates via newline-delimited JSON-RPC
 * on stdin/stdout. Performs MCP handshake (initialize, notifications/initialized) then
 * tools/call. Process is started on first callTool and reused until the client is destroyed.
 */
class Neo4jStdioClient implements Neo4jMcpClientInterface
{
    private const INIT_ID = 1;

    private const READ_TIMEOUT_SECONDS = 60;

    /** @var resource|null */
    private $process = null;

    /** @var resource[]|null */
    private $pipes = null;

    private bool $initialized = false;

    private int $nextToolCallId = 2;

    public function __destruct()
    {
        $this->closeProcess();
    }

    public function callTool(string $toolName, array $arguments = []): array
    {
        if (! Neo4jMcpConfig::hasNeo4jPassword()) {
            return $this->errorResult(Neo4jMcpConfig::stdioPasswordRequiredMessage());
        }

        try {
            $this->ensureProcessStarted();
            $this->ensureInitialized();

            $id = $this->nextToolCallId++;
            $payload = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => [
                    'name' => $toolName,
                    'arguments' => $arguments === [] ? new \stdClass : $arguments,
                ],
            ];
            $this->writeLine($payload);
            $body = $this->readResponseForId($id);

            if (isset($body['error'])) {
                $message = is_array($body['error']) && isset($body['error']['message'])
                    ? $body['error']['message']
                    : (string) json_encode($body['error']);

                return $this->errorResult('Neo4j MCP STDIO: '.$message);
            }

            return $body['result'] ?? [];
        } catch (Throwable $exception) {
            $health = new Neo4jMcpHealth;
            if (! $health->isBinaryInstalled()) {
                return $this->errorResult(Neo4jMcpHealth::stdioBinaryMissingMessage());
            }

            return $this->errorResult(Neo4jMcpHealth::stdioProcessFailedMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResult(string $message): array
    {
        $response = Response::error($message);
        $content = $response->content();

        return [
            'content' => [[
                'type' => 'text',
                'text' => (string) $content,
            ]],
            'isError' => true,
        ];
    }

    private function ensureProcessStarted(): void
    {
        if ($this->process !== null) {
            return;
        }

        $command = Neo4jMcpConfig::stdioCommand();
        $env = Neo4jMcpConfig::stdioEnvironment();

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
        ];

        $cwd = null;
        $proc = @proc_open(
            $command,
            $descriptorspec,
            $pipes,
            $cwd,
            $env
        );

        if (! is_resource($proc)) {
            throw new \RuntimeException('Neo4j MCP STDIO: failed to start process "'.$command.'".');
        }

        $this->process = $proc;
        $this->pipes = $pipes;

        stream_set_blocking($this->pipes[1], false);
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $initPayload = [
            'jsonrpc' => '2.0',
            'id' => self::INIT_ID,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => 'neo4j-laravel-boost',
                    'version' => '1.0',
                ],
            ],
        ];
        $this->writeLine($initPayload);
        $initBody = $this->readResponseForId(self::INIT_ID);

        if (isset($initBody['error'])) {
            $msg = is_array($initBody['error']) && isset($initBody['error']['message'])
                ? $initBody['error']['message']
                : (string) json_encode($initBody['error']);
            throw new \RuntimeException('Neo4j MCP STDIO initialize: '.$msg);
        }

        $notifyPayload = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ];
        $this->writeLine($notifyPayload);

        $this->initialized = true;
    }

    private function writeLine(array $payload): void
    {
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES)."\n";
        $written = @fwrite($this->pipes[0], $line);
        if ($written !== strlen($line)) {
            throw new \RuntimeException('Neo4j MCP STDIO: failed to write to process stdin.');
        }
        fflush($this->pipes[0]);
    }

    /**
     * Read stdout line-by-line until we get a JSON-RPC response with the given id.
     */
    private function readResponseForId(int $id): array
    {
        $deadline = time() + self::READ_TIMEOUT_SECONDS;
        $buffer = '';

        while (true) {
            stream_set_timeout($this->pipes[1], 5);
            $chunk = @fread($this->pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            }

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                if (isset($decoded['id']) && (int) $decoded['id'] === $id) {
                    return $decoded;
                }
            }

            if (time() >= $deadline) {
                throw new \RuntimeException('Neo4j MCP STDIO: read timeout waiting for response id '.$id.'.');
            }

            if ($chunk === false || $chunk === '') {
                $status = proc_get_status($this->process);
                if ($status['running'] === false) {
                    throw new \RuntimeException('Neo4j MCP STDIO: process exited before response.');
                }
                usleep(10_000);
            }
        }
    }

    private function closeProcess(): void
    {
        if ($this->pipes !== null) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = null;
        }
        if ($this->process !== null && is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        $this->initialized = false;
    }
}
