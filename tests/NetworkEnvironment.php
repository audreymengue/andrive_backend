<?php

namespace Tests;

use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\Role;
use App\Models\TypeSortie;
use App\Models\User;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use PDO;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class NetworkEnvironment
{
    public string $root;

    public string $directory;

    public string $runId;

    public int $mysqlPort;

    public PDO $pdo;

    public array $env;

    public array $accounts = [];

    public array $fixtures = [];

    public array $evidence = [];

    public array $servers = [];

    private ?Process $mysql = null;

    private array $secrets = [];

    public function __construct()
    {
        $this->root = dirname(__DIR__);
        if (basename($this->root) !== 'aninf-api-test' || getenv('ANINF_NETWORK_VALIDATION') !== '1') {
            throw new RuntimeException('Profil reseau explicite requis dans aninf-api-test.');
        }
        $this->runId = bin2hex(random_bytes(8));
        $this->directory = $this->root.'/storage/framework/testing/network-'.$this->runId;
        if (file_exists($this->directory)) {
            throw new RuntimeException('Un repertoire de validation existant ne doit jamais etre reutilise.');
        }
        foreach (['mysql', 'cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
            if (! mkdir($this->directory.'/'.$path, 0700, true) && ! is_dir($this->directory.'/'.$path)) {
                throw new RuntimeException('Creation du repertoire prive impossible.');
            }
        }
        file_put_contents($this->directory.'/run-id', $this->runId);
        register_shutdown_function(fn () => $this->stop());
        $this->mysqlPort = self::freePort();
        $password = bin2hex(random_bytes(20));
        $key = 'base64:'.base64_encode(random_bytes(32));
        $this->secrets = [$password, $key];
        $this->env = [
            'ANINF_VALIDATION_MYSQL_PORT' => (string) $this->mysqlPort,
            'ANINF_VALIDATION_RUN_ID' => $this->runId,
            'ANINF_VALIDATION_RUN_DIR' => $this->directory,
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => $key,
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $this->mysqlPort,
            'DB_DATABASE' => 'aninf_test', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => $password, 'DB_URL' => '',
            'LOG_CHANNEL' => 'single', 'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
            'APP_CONFIG_CACHE' => 'storage/framework/testing/network-'.$this->runId.'/cache/config.php',
            'APP_SERVICES_CACHE' => 'storage/framework/testing/network-'.$this->runId.'/cache/services.php',
            'APP_PACKAGES_CACHE' => 'storage/framework/testing/network-'.$this->runId.'/cache/packages.php',
            'APP_ROUTES_CACHE' => 'storage/framework/testing/network-'.$this->runId.'/cache/routes.php',
            'APP_EVENTS_CACHE' => 'storage/framework/testing/network-'.$this->runId.'/cache/events.php',
        ];
        $binary = (new ExecutableFinder)->find('mysqld.exe') ?? (glob('C:/laragon/bin/mysql/*/bin/mysqld.exe')[0] ?? null);
        if ($binary === null) {
            throw new RuntimeException('Binaire MySQL local requis pour le profil prive.');
        }
        $initialize = new Process([$binary, '--no-defaults', '--initialize-insecure', '--datadir='.$this->directory.'/mysql'], $this->root);
        $initialize->setTimeout(60);
        if ($initialize->run() !== 0) {
            throw new RuntimeException('Initialisation de la nouvelle instance MySQL privee impossible.');
        }
        $this->mysql = new Process([$binary, '--no-defaults', '--console', '--datadir='.$this->directory.'/mysql', '--port='.$this->mysqlPort, '--bind-address=127.0.0.1', '--mysqlx=OFF', '--skip-log-bin', '--general-log=OFF', '--slow-query-log=OFF', '--performance-schema=ON', '--innodb-buffer-pool-size=64M'], $this->root);
        $this->mysql->setTimeout(null);
        $this->mysql->start();
        $ready = null;
        $until = microtime(true) + 35;
        do {
            try {
                $ready = new PDO('mysql:host=127.0.0.1;port='.$this->mysqlPort, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (Throwable) {
                usleep(50000);
            }
        } while ($ready === null && microtime(true) < $until && $this->mysql->isRunning());
        if ($ready === null) {
            throw new RuntimeException('Instance MySQL privee indisponible.');
        }
        $identity = $ready->query('SELECT @@port AS port, @@datadir AS data_dir')->fetch(PDO::FETCH_ASSOC);
        if ((int) $identity['port'] !== $this->mysqlPort || realpath($identity['data_dir']) !== realpath($this->directory.'/mysql')) {
            throw new RuntimeException('Le serveur SQL ne correspond pas au nouveau repertoire prive.');
        }
        $existingSchemas = (int) $ready->query("SELECT COUNT(*) FROM information_schema.schemata WHERE SCHEMA_NAME NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')")->fetchColumn();
        if ($existingSchemas !== 0) {
            throw new RuntimeException('Un serveur contenant deja des bases utilisateur ne doit jamais etre reutilise.');
        }
        $ready->exec('CREATE DATABASE aninf_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $ready->exec('CREATE TABLE aninf_test.validation_run_marker (run_id VARCHAR(32) NOT NULL)');
        $statement = $ready->prepare('INSERT INTO aninf_test.validation_run_marker VALUES (?)');
        $statement->execute([$this->runId]);
        $ready->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY ".$ready->quote($password));
        $ready = null;
        $this->pdo = $this->connection();
        $this->evidence['isolation'] = ['database' => 'aninf_test', 'mysql_port' => $this->mysqlPort, 'mysql_version' => $this->pdo->query('SELECT VERSION()')->fetchColumn(), 'run_id' => $this->runId, 'existing_instance_used' => false, 'private_datadir_verified' => true, 'initial_user_databases' => $existingSchemas];
        $migrate = new Process([PHP_BINARY, 'tests/network-console.php', 'migrate', '--force', '--no-interaction'], $this->root, $this->env);
        $migrate->setTimeout(60);
        if ($migrate->run() !== 0) {
            $diagnostic = str_replace($this->secrets, '[REDACTED]', $migrate->getOutput().$migrate->getErrorOutput());
            file_put_contents($this->directory.'/setup-error.txt', $diagnostic);
            throw new RuntimeException('Migration additive de la base privee impossible.');
        }
        foreach ($this->env as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        $app = require $this->root.'/tests/network-application.php';
        $app->make(Kernel::class)->bootstrap();
        restore_error_handler();
        restore_exception_handler();
        $app->make(ParametrageSecuritySeeder::class)->run();
        $direction = Direction::factory()->create(['nom' => 'TEST NET organisation', 'code' => 'TEST_NET_'.$this->runId]);
        $role = Role::where('system_key', 'super_admin')->firstOrFail();
        foreach (['a', 'b', 'user'] as $name) {
            $secret = bin2hex(random_bytes(6));
            $user = User::factory()->create(['email' => 'network-'.$name.'-'.$this->runId.'@example.test', 'password' => Hash::make($secret), 'direction_id' => $direction->id, 'statut' => 'ACTIF', 'must_change_password' => false]);
            $user->assignRole($name === 'user' ? Role::where('system_key', 'demandeur')->firstOrFail() : $role);
            $this->accounts[$name] = ['id' => $user->id, 'email' => $user->email, 'password' => $secret, 'token' => null];
            $this->secrets[] = $secret;
        }
        $this->fixtures = ['direction' => $direction->id, 'type' => TypeSortie::factory()->create()->id, 'lieu' => EntiteGeographique::factory()->create()->id];
        foreach ([0, 1] as $index) {
            $port = self::freePort();
            $server = new Process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', '127.0.0.1:'.$port, '-t', 'public', 'tests/network-router.php'], $this->root, $this->env);
            $server->setTimeout(null);
            $server->start();
            $this->servers[$index] = ['process' => $server, 'port' => $port, 'pid' => $server->getPid()];
            $until = microtime(true) + 20;
            do {
                $health = $this->request('GET', '/up', worker: $index);
                if ($health['status'] === 200) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $until && $server->isRunning());
            if ($health['status'] !== 200) {
                throw new RuntimeException('Serveur HTTP prive indisponible.');
            }
        }
        $this->evidence['workers'] = array_map(fn (array $server): array => ['port' => $server['port'], 'pid' => $server['pid']], $this->servers);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if ($socket === false) {
            throw new RuntimeException('Port local indisponible.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($address, ':'), 1);
    }

    public function connection(): PDO
    {
        if ($this->mysqlPort === 3306) {
            throw new RuntimeException('Port de la base partagee interdit.');
        }
        $pdo = new PDO('mysql:host=127.0.0.1;port='.$this->mysqlPort.';dbname=aninf_test;charset=utf8mb4', 'root', $this->env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (! hash_equals($this->runId, (string) $pdo->query('SELECT run_id FROM validation_run_marker')->fetchColumn())) {
            throw new RuntimeException('Base privee non reconnue.');
        }

        return $pdo;
    }

    public function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function handle(string $method, string $path, array $body, ?string $token, array $headers, int $worker): \CurlHandle
    {
        $handle = curl_init('http://127.0.0.1:'.$this->servers[$worker]['port'].$path);
        $headerList = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) {
            $headerList[] = 'Authorization: Bearer '.$token;
        }
        foreach ($headers as $name => $value) {
            $headerList[] = $name.': '.$value;
        }
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headerList, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 2]);
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode((object) $body, JSON_THROW_ON_ERROR));
        }

        return $handle;
    }

    private function response(\CurlHandle $handle, string|false $raw): array
    {
        if ($raw === false) {
            return ['status' => 0, 'json' => null, 'headers' => [], 'worker' => null];
        }
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, curl_getinfo($handle, CURLINFO_HEADER_SIZE))) as $header) {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'json' => json_decode(substr($raw, curl_getinfo($handle, CURLINFO_HEADER_SIZE)), true), 'headers' => $headers, 'worker' => $headers['x-validation-worker'] ?? null];
    }

    public function request(string $method, string $path, array $body = [], ?string $token = null, array $headers = [], int $worker = 0): array
    {
        $handle = $this->handle($method, $path, $body, $token, $headers, $worker);
        $response = $this->response($handle, curl_exec($handle));
        curl_close($handle);

        return $response;
    }

    public function login(string $name): array
    {
        $account = $this->accounts[$name];
        $response = $this->request('POST', '/api/login', ['email' => $account['email'], 'password' => $account['password']]);
        if ($response['status'] !== 200 || ! is_string($response['json']['data']['token'] ?? null)) {
            throw new RuntimeException('Authentification du compte identifie de test impossible ; status '.$response['status']);
        }
        $token = $response['json']['data']['token'];
        $this->accounts[$name]['token'] = $token;
        $this->secrets[] = $token;

        return $response;
    }

    public function activeAdminToken(): string
    {
        foreach (['a', 'b'] as $name) {
            if ($this->scalar('SELECT statut FROM users WHERE id = ?', [$this->accounts[$name]['id']]) === 'ACTIF') {
                return $this->accounts[$name]['token'] ?? $this->login($name)['json']['data']['token'];
            }
        }
        throw new RuntimeException('Aucun administrateur prive actif.');
    }

    /** @param list<array{method: string, path: string, body: array, token: string, key: string}> $requests */
    public function race(array $requests, string $guardTable = 'organisation_guards'): array
    {
        if (! in_array($guardTable, ['organisation_guards', 'course_guards'], true)) {
            throw new RuntimeException('Garde de concurrence non autorisee.');
        }
        $blocker = $this->connection();
        $blocker->beginTransaction();
        $blocker->query('SELECT id FROM '.$guardTable.' WHERE id = 1 FOR UPDATE')->fetchColumn();
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $worker => $request) {
            $handle = $this->handle($request['method'], $request['path'], $request['body'], $request['token'], ['Idempotency-Key' => $request['key']], $worker);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        $maxWaiters = 0;
        $waitingConnections = [];
        $until = microtime(true) + 12;
        try {
            do {
                curl_multi_exec($multi, $running);
                $waitingStatement = $this->pdo->prepare("SELECT DISTINCT t.PROCESSLIST_ID FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE l.OBJECT_SCHEMA = 'aninf_test' AND l.OBJECT_NAME = ?");
                $waitingStatement->execute([$guardTable]);
                $waitingConnections = $waitingStatement->fetchAll(PDO::FETCH_COLUMN);
                $maxWaiters = max($maxWaiters, count($waitingConnections));
                if ($maxWaiters >= 2 || $running === 0) {
                    break;
                }
                curl_multi_select($multi, 0.02);
            } while (microtime(true) < $until);
        } finally {
            $blocker->commit();
        }
        do {
            curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running);
        $responses = [];
        foreach ($handles as $handle) {
            $responses[] = $this->response($handle, curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        return ['responses' => $responses, 'simultaneous_mysql_waiters' => $maxWaiters, 'mysql_connections' => $waitingConnections, 'worker_pids' => array_column($responses, 'worker')];
    }

    public function logsContainNoSecrets(): bool
    {
        $logs = '';
        foreach ($this->servers as $server) {
            $logs .= $server['process']->getOutput().$server['process']->getErrorOutput();
        }
        if ($this->mysql !== null) {
            $logs .= $this->mysql->getOutput().$this->mysql->getErrorOutput();
        }
        foreach (glob($this->directory.'/mysql/*.err') ?: [] as $file) {
            $logs .= file_get_contents($file);
        }
        foreach (glob($this->directory.'/storage/logs/*') ?: [] as $file) {
            if (is_file($file)) {
                $logs .= file_get_contents($file);
            }
        }
        foreach ($this->secrets as $secret) {
            if ($secret !== '' && str_contains($logs, $secret)) {
                return false;
            }
        }

        return true;
    }

    public function redactSensitiveText(string $text): string
    {
        $text = str_replace($this->secrets, '[REDACTED]', $text);

        return preg_replace('/((?:password|token|secret)[\'"\s:=]+)[^\s,;]+/i', '$1[REDACTED]', $text) ?? $text;
    }

    public function saveEvidence(): void
    {
        file_put_contents($this->directory.'/evidence.json', json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function stop(): void
    {
        foreach ($this->servers as $server) {
            if ($server['process']->isRunning()) {
                $server['process']->stop(2);
            }
        }
        if ($this->mysql?->isRunning()) {
            try {
                $this->connection()->exec('SHUTDOWN');
                $this->mysql->wait();
            } catch (Throwable) {
                $this->mysql->stop(3);
            }
        }
        if (isset($this->mysqlPort)) {
            $ports = [...array_column($this->servers, 'port'), $this->mysqlPort];
            $closed = true;
            foreach ($ports as $port) {
                $socket = @fsockopen('127.0.0.1', $port, $error, $message, 0.2);
                if ($socket !== false) {
                    $closed = false;
                    fclose($socket);
                }
            }
            $this->evidence['cleanup'] = ['private_ports_closed' => $closed, 'shared_server_stopped' => false];
            $this->saveEvidence();
        }
    }
}
