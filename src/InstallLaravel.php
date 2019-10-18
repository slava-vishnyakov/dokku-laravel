<?php

namespace DokkuLaravel;

use RuntimeException;

class InstallLaravel
{
    private $projectName;
    private $domain;

    public function __construct($projectName, $domain)
    {
        $this->projectName = $projectName;
        $this->domain = $domain;
        $this->stack = 'heroku-18'; # 'cedar-14';
    }

    public function run()
    {
        $postgresVersion = '11.4';
        $postgresPort = mt_rand(5234, 5600);
        $testDbPostgresPort = $postgresPort + 1;

        $this->createDockerCompose($postgresPort, $postgresVersion, $testDbPostgresPort);
        $this->updateDbConfig();
        $this->updateEnv($postgresPort);
        $this->updatePhpUnitXml($testDbPostgresPort);
        $this->updatePackageJson();
        $this->updateTrustProxies();
        $this->updateTestCase();
        $this->createDokkuDeployFile($postgresVersion);
        $this->createDokkuScaleFile();
        $this->miscFiles();
        $this->installIdeHelpers();

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

    function fileInsertAfter($filename, $start, $replace)
    {
        $text = file_get_contents($filename);
        $startI = strpos($text, $start);
        if ($startI === false) {
            throw new RuntimeException("$startI not found in $filename");
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
        $this->fileInsertAfter($this->projectName . '/package.json', "\"scripts\": {", $this->packageJsonFile());
    }

    public function updatePhpUnitXml($testDbPostgresPort)
    {
        $connection = "postgres://webapp:secret@localhost:$testDbPostgresPort/webapp?sslmode=disable";
        $replace = "\n        <env name=\"DATABASE_URL\" value=\"{$connection}\"/>";
        $this->fileInsertAfter($this->projectName . '/phpunit.xml', '<php>',
            $replace);
    }

    public function updateEnv($postgresPort)
    {
        $dbFragment = "DATABASE_URL=postgres://webapp:secret@localhost:$postgresPort/webapp?sslmode=disable";
        $this->fileReplaceBetween($this->projectName . '/.env.example', 'DB_CONNECTION', 'DB_PASSWORD=', "{$dbFragment}\n");
        $this->fileReplaceBetween($this->projectName . '/.env', 'DB_CONNECTION', 'DB_PASSWORD=', "{$dbFragment}\n");
        $this->fileReplace($this->projectName . '/.env', "DB_PASSWORD=\n", "");
    }

    public function createDockerCompose($postgresPort, $postgresVersion, $testDbPostgresPort)
    {
        $dockerCompose = $this->dockerComposeFile($postgresPort, $postgresVersion, $testDbPostgresPort);
        file_put_contents($this->projectName . '/docker-compose.yml', $dockerCompose);
    }

    public function updateDbConfig()
    {
        $dbFragment = file_get_contents(__DIR__ . '/../files/database.php.fragment');;
        $this->fileReplaceBetween($this->projectName . '/config/database.php', '<?php', "\n    ],", $dbFragment);
    }

    private function installIdeHelpers()
    {
        system('cd ' . $this->projectName . ' && composer require barryvdh/laravel-ide-helper');
        $start = "         * Package Service Providers...\n         */\n";
        $replace = "        \\Barryvdh\\LaravelIdeHelper\\IdeHelperServiceProvider::class,\n        ";
        $this->fileInsertAfter($this->projectName . '/config/app.php', $start, $replace);
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

    public function dockerComposeFile($postgresPort, $postgresVersion, $testDbPostgresPort)
    {
        return $this->blade('docker-compose-yml',
            [
                'postgresPort' => $postgresPort,
                'postgresVersion' => $postgresVersion,
                'testDbPostgresPort' => $testDbPostgresPort
            ]
        );
    }

    public function testCaseFile()
    {
        return file_get_contents(__DIR__ . '/../files/TestCase');
    }

    public function packageJsonFile()
    {
        return str_replace('{DOMAIN}', $this->domain, '
        "serve": "php artisan serve",
        "push-{DOMAIN}": "git push {DOMAIN} master",
        "queue": "php artisan queue:work --tries=1",
        "start-db": "docker-compose up",
        "stop-db": "docker-compose stop",
        "migrate": "php artisan migrate",
        "migrate:rollback": "php artisan migrate:rollback",
        "composer:update": "composer update",
        "composer:install": "composer install",');
    }

    public function createDokkuScaleFile()
    {
        file_put_contents($this->projectName . '/DOKKU_SCALE', "web=1\ncron=1\nqueue=1\n");
    }

    public function createDokkuDeployFile($postgresVersion)
    {
        file_put_contents($this->projectName . '/dokku-deploy.txt', $this->deployDokkuFile($postgresVersion));
    }

    public function deployDokkuFile($postgresVersion)
    {
        $key = base64_encode(random_bytes(32));
        $domainUnderscores = preg_replace('#[^A-Za-z0-9]#', '_', $this->domain);
        $domain = $this->domain;
        return $this->blade('deploy-dokku-txt',
            [
                'postgresVersion' => $postgresVersion,
                'key' => $key,
                'domainUnderscores' => $domainUnderscores,
                'domain' => $domain,
            ]
        );
    }

    public function miscFiles()
    {
        copy(__DIR__ . '/../files/Procfile', $this->projectName . '/Procfile');
        copy(__DIR__ . '/../files/cron.sh', $this->projectName . '/cron.sh');
        chmod($this->projectName . '/cron.sh', 0755);

        copy(__DIR__ . '/../files/queue.sh', $this->projectName . '/queue.sh');
        chmod($this->projectName . '/queue.sh', 0755);

        copy(__DIR__ . '/../files/php.ini', $this->projectName . '/php.ini');
        copy(__DIR__ . '/../files/CHECKS', $this->projectName . '/CHECKS');

        copy(__DIR__ . '/../files/app.json', $this->projectName . '/app.json');
    }

    private function updateTrustProxies()
    {
        $this->fileInsertAfter($this->projectName . '/app/Http/Middleware/TrustProxies.php',
            'protected $proxies',
            "= [\n        '172.17.0.1'\n    ]");
    }
}
