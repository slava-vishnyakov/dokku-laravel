<?php

namespace DokkuLaravel;

use RuntimeException;

class InstallLaravel
{
    private $projectName;
    private $domain;
    private $installMigratoro;

    public function __construct($projectName, $domain, $installMigratoro=false)
    {
        $this->projectName = $projectName;
        $this->domain = $domain;
        $this->installMigratoro = $installMigratoro;
    }

    public function run()
    {
        $postgresVersion = '16';
        $postgresPort = random_int(5234, 5234+1000);
        $testDbPostgresPort = $postgresPort + 1;
        $redisVersion = '7.2';
        $redisPort = random_int(6379, 6379+1000);
        $testRedisPort = $redisPort + 1;
        $elasticVersion = '8.10.4';
        $elasticPort = random_int(9200, 9200+1000);
        $testElasticPort = $elasticPort + 1;

        $this->createDockerCompose(get_defined_vars());
        $this->createDockerignore();
        $this->updateEnv($postgresPort, $redisPort);
        $this->updatePhpUnitXml($testDbPostgresPort, $testRedisPort);
        $this->updatePackageJson();
        $this->updateTrustProxies();
        $this->updateTestCase();
        $this->createCaproverDeployFile(get_defined_vars());
        $this->miscFiles();
        $this->installIdeHelpers();
        if($this->installMigratoro) {
            $this->installMigratoro();
        }

    }

    function fileReplaceBetween($filename, $start, $end, $replace)
    {
        $text = file_get_contents($filename);
        $startI = strpos($text, $start);
        if ($startI === false) {
            throw new RuntimeException("$startI not found in $filename");
        }
        $endI = strpos($text, $end);
        if ($endI === false) {
            throw new RuntimeException("$endI not found in $filename");
        }
        $newContent = substr($text, 0, $startI) . $replace . substr($text, $endI, strlen($text) - $endI);
        file_put_contents($filename, $newContent);
    }

    function fileReplace($filename, $search, $replace)
    {
        $text = file_get_contents($filename);
        $newContent = str_replace($search, $replace, $text);
        file_put_contents($filename, $newContent);
    }

    function fileReplaceRegex($filename, $search, $replace)
    {
        $text = file_get_contents($filename);
        $newContent = preg_replace($search, $replace, $text);
        file_put_contents($filename, $newContent);
    }

    function fileInsertAfter($filename, $start, $replace)
    {
        $text = file_get_contents($filename);
        $startI = strpos($text, $start);
        if ($startI === false) {
            throw new RuntimeException("String '$startI' not found in file '$filename'");
        }
        $startI += strlen($start);
        $endI = $startI;
        $newContent = substr($text, 0, $startI) . $replace . substr($text, $endI, strlen($text) - $endI);
        file_put_contents($filename, $newContent);
    }

    public function updateTestCase()
    {
        file_put_contents($this->projectName . '/tests/TestCase.php', $this->testCaseFile());
    }

    public function updatePackageJson()
    {
        $this->fileReplaceBetween($this->projectName . '/package.json', "\"scripts\": {", "},", $this->packageJsonFile());
    }

    public function updatePhpUnitXml($testDbPostgresPort, $testRedisPort)
    {
        $connection = "postgres://webapp:secret@localhost:$testDbPostgresPort/webapp?sslmode=disable";
        $replace = "\n        <server name=\"DB_URL\" value=\"{$connection}\"/>\n" .
            "        <server name=\"DB_CONNECTION\" value=\"pgsql\"/>\n" .
            "        <server name=\"REDIS_PORT\" value=\"{$testRedisPort}\"/>\n"
        ;
        $this->fileInsertAfter($this->projectName . '/phpunit.xml', '<php>',
            $replace);
    }

    public function updateEnv($postgresPort, $redisPort)
    {
        $exampleFile = $this->projectName . '/.env.example';
        $envFile = $this->projectName . '/.env';
        foreach([$envFile, $exampleFile] as $file) {
            $dbFragment = "DB_CONNECTION=pgsql\nDB_URL=postgres://webapp:secret@postgres:5432/webapp?sslmode=disable";
            $this->fileReplaceBetween($file, 'DB_CONNECTION', 'DB_PASSWORD=', "{$dbFragment}\n");
            $this->fileReplace($file, "DB_PASSWORD=\n", "");
            $this->fileReplaceRegex($file, "/APP_URL=.*?\n/", "APP_URL=http://127.0.0.1:8081\n");
            $this->fileReplace($file, "REDIS_HOST=redis", "REDIS_HOST=localhost");
            $this->fileReplace($file, "REDIS_PORT=6379", "REDIS_PORT=$redisPort");
        }
    }

    public function createDockerCompose($vars)
    {
        $dockerCompose = $this->dockerComposeFile($vars);
        file_put_contents($this->projectName . '/docker-compose.yml', $dockerCompose);
    }

    private function installIdeHelpers()
    {
        system('cd ' . $this->projectName . ' && composer require barryvdh/laravel-ide-helper');
        $this->fileInsertAfter($this->projectName . '/composer.json',
            '"Illuminate\\\\Foundation\\\\ComposerScripts::postAutoloadDump",',
            "\n            \"php artisan ide-helper:generate\",
            \"php artisan ide-helper:meta\",");
        system('cd ' . $this->projectName . ' && (php artisan ide-helper:generate; php artisan ide-helper:meta)');
    }

    public function blade($fn, $replaces)
    {
        # we don't use actual Blade, because it meses up whitespace needed for docker-compose.yml
        $file = __DIR__ . '/../files/' . $fn . '.blade.php';
        $text = file_get_contents($file);
        foreach($replaces as $replace => $value) {
            $text = str_replace("{{ \${$replace} }}", $value, $text);
        }
        return $text;
    }

    public function dockerComposeFile($vars)
    {
        return $this->blade('docker-compose-yml', $vars);
    }

    public function testCaseFile()
    {
        return file_get_contents(__DIR__ . '/../files/TestCase');
    }

    public function packageJsonFile()
    {
        $domainDashes = preg_replace('#[^A-Za-z0-9]#', '-', $this->domain);
        $actions = <<<"EOF"
        "scripts": {
                "start-compose": "docker compose up",
                "dev": "docker compose exec php-nginx npm install; docker compose exec php-nginx ./node_modules/vite/bin/vite.js --host",
                "stop-compose": "docker compose stop",
                "deploy": "caprover deploy -n CAPROVER_HOST_REPLACE_ME -a CAPROVER_APP_NAME_REPLACE_ME -b master",
                "migrator": "docker compose exec php-nginx php artisan migrator && docker compose exec php-nginx php artisan ide-helper:model --reset --write",
                "queue": "docker compose exec php-nginx php artisan queue:work --tries=1",
                "migrate": "docker compose exec php-nginx php artisan migrate",
                "migrate:rollback": "docker compose exec php-nginx php artisan migrate:rollback",
                "build": "docker compose exec php-nginx npm install; docker compose exec php-nginx ./node_modules/vite/bin/vite build",
                "bash": "docker compose exec php-nginx bash"
            
        EOF;
        return str_replace('{DOMAIN_DASH}', $domainDashes, $actions);
    }

    public function createCaproverDeployFile($vars)
    {
        file_put_contents($this->projectName . '/caprover-deploy.txt', $this->deployCaproverFile($vars));
    }

    public function deployCaproverFile($vars)
    {
        $domainDashes = preg_replace('#[^A-Za-z0-9]#', '-', $this->domain);
        $domain = $this->domain;
        return $this->blade('deploy-caprover-txt', array_merge($vars, ['domain' => $domain, 'domainDashes' => $domainDashes]));
    }

    public function miscFiles()
    {
        mkdir($this->projectName . '/resources/docker/');
        mkdir($this->projectName . '/resources/docker/local/');
        $files = [
            'cron.sh', 'entrypoint.sh', 'nginx.conf.tpl', 'php.ini', 'php-fpm.conf.tpl', 'queue.sh', 'supervisor.conf',
            'local/nginx.conf', 'local/php.ini', 'local/php-fpm.conf', 'local/supervisor.conf'
        ];
        foreach ($files as $file) {
            copy(__DIR__ . '/../files/docker/' . $file, $this->projectName . '/resources/docker/' . $file);
        }
        chmod($this->projectName . '/resources/docker/queue.sh', 0755);
        chmod($this->projectName . '/resources/docker/cron.sh', 0755);

        copy(__DIR__ . '/../files/Dockerfile', $this->projectName . '/Dockerfile');
        copy(__DIR__ . '/../files/docker/local/Dockerfile.php-nginx', $this->projectName . '/Dockerfile.php-nginx');
    }

    private function updateTrustProxies()
    {
        $this->fileInsertAfter($this->projectName . '/bootstrap/app.php',
            '->withMiddleware(function (Middleware $middleware) {',
            "\n" . '        $middleware->trustProxies(at: \'*\');');
    }

    private function createDockerignore()
    {
        copy(__DIR__ . '/../files/.dockerignore', $this->projectName . '/.dockerignore');
    }

    private function installMigratoro()
    {
        print("Installing Migratoro...\n");
        $result = system('cd ' . $this->projectName . ' && touch database/schema.txt');
        if($result === false) {
            throw new RuntimeException("Failed to create database/schema.txt");
        }
        $result = system('cd ' . $this->projectName . ' && composer config repositories.migratoro vcs https://github.com/niogu/migratoro');
        if($result === false) {
            throw new RuntimeException("Failed to add migratoro repository");
        }
        $result = system('cd ' . $this->projectName . ' && composer require --dev niogu/migratoro:dev-master');
        if($result === false) {
            throw new RuntimeException("Failed to install migratoro");
        }
    }
}
