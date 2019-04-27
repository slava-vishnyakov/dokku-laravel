# ssh {{ $domain }}; sudo -i

#Install dokku
wget https://raw.githubusercontent.com/dokku/dokku/v0.15.5/bootstrap.sh;
sudo DOKKU_TAG=v0.15.5 bash bootstrap.sh

# Install app
dokku apps:create {{ $domain }}
dokku domains:add {{ $domain }} {{ $domain }}
dokku config:set --no-restart {{ $domain }} APP_KEY=base64:$(head /dev/urandom | head -c 32 | base64)
dokku config:set --no-restart {{ $domain }} APP_DEBUG=false
dokku config:set --no-restart {{ $domain }} APP_NAME={{ $domain }}
dokku config:set --no-restart {{ $domain }} APP_URL=http://{{ $domain }}
dokku config:set --no-restart {{ $domain }} DOKKU_WAIT_TO_RETIRE=10
dokku config:set --no-restart {{ $domain }} DOKKU_DEFAULT_CHECKS_WAIT=1
dokku config:set --no-restart {{ $domain }} HEROKU_PHP_PLATFORM_REPOSITORIES=" - https://lang-php.slava.io/dist-heroku-18-stable/packages.json"
dokku config:set --no-restart {{ $domain }} BUILDPACK_URL=https://github.com/heroku/heroku-buildpack-php
dokku config:set --no-restart {{ $domain }} STACK="heroku-18"
# MAYBE:  dokku config:set --no-restart {{ $domain }} QUEUE_CONNECTION=sync
dokku storage:mount {{ $domain }} /home/dokku/{{ $domain }}/volumes/storage/app/:/app/storage/app/
dokku storage:mount {{ $domain }} /home/dokku/{{ $domain }}/volumes/storage/logs/:/app/storage/logs/
dokku storage:mount {{ $domain }} /home/dokku/{{ $domain }}/volumes/storage/framework/cache/:/app/storage/framework/cache/
dokku storage:mount {{ $domain }} /home/dokku/{{ $domain }}/volumes/storage/framework/sessions/:/app/storage/framework/sessions/
dokku storage:mount {{ $domain }} /home/dokku/{{ $domain }}/volumes/storage/framework/views/:/app/storage/framework/views/
dokku checks:disable {{ $domain }} web

# dokku storage:mount {{ $domain }} /home/dokku/{{ $domain }}/volumes/public/persistent/:/app/public/persistent/

# MailGun
dokku config:set --no-restart {{ $domain }} MAIL_DRIVER="mailgun"
dokku config:set --no-restart {{ $domain }} MAIL_FROM_ADDRESS="no-reply@{{ $domain }}"
dokku config:set --no-restart {{ $domain }} MAIL_FROM_NAME="{{ $domain }}"
dokku config:set --no-restart {{ $domain }} MAILGUN_DOMAIN="mg.{{ $domain }}"
dokku config:set --no-restart {{ $domain }} MAILGUN_SECRET="************"

# Postgres
dokku plugin:install https://github.com/dokku/dokku-postgres.git postgres
export POSTGRES_IMAGE_VERSION="{{ $postgresVersion }}"
dokku postgres:create {{ $domainUnderscores }}
dokku postgres:link {{ $domainUnderscores }} {{ $domain }}

# ElasticSearch 6
echo 'vm.max_map_count=262144' | sudo tee -a /etc/sysctl.conf; sudo sysctl -p
sudo dokku plugin:install https://github.com/dokku/dokku-elasticsearch.git elasticsearch
# https://github.com/dokku/dokku-elasticsearch/pull/64
export ELASTICSEARCH_IMAGE="docker.elastic.co/elasticsearch/elasticsearch"
export ELASTICSEARCH_IMAGE_VERSION="6.6.0"
dokku elasticsearch:create {{ $domainUnderscores }}
dokku elasticsearch:link {{ $domainUnderscores }} {{ $domain }}
dokku config:set {{ $domain }} ELASTICSEARCH_INDEX={{ $domainUnderscores }}

# Redis
dokku plugin:install https://github.com/dokku/dokku-redis.git redis
dokku redis:create {{ $domainUnderscores }}
dokku redis:link {{ $domainUnderscores }} {{ $domain }}
# locally: composer require predis/predis
# config/database.php change in 2 places:
# $redis = getenv("REDIS_URL") ? parse_url(getenv("REDIS_URL")) : [];
# 'host' => data_get($redis, 'host', env('REDIS_HOST', '127.0.0.1')),
# 'password' => data_get($redis, 'pass', env('REDIS_PASSWORD', null)),
# 'port' => data_get($redis, 'port', env('REDIS_PORT', 6379)),

# Queue
# install, link redis
dokku config:set --no-restart {{ $domain }} QUEUE_CONNECTION=redis
sudo vi /home/dokku/{{ $domain }}/hooks/pre-receive
# add before git-hook
dokku enter {{ $domain }} cron touch /app/restarting || echo "Could not touch /app/restarting on cron"
dokku enter {{ $domain }} queue php artisan queue:restart || echo "Could not send queue:restart to queue"

# git init; echo .idea >> .gitignore; git add .; git commit -m "Initial commit"
# git remote add {{ $domain }} dokku@{{ $domain }}:{{ $domain }}
# git push {{ $domain }} master

dokku checks:enable {{ $domain }} web
dokku checks:skip {{ $domain }} cron,queue

echo -e "/home/dokku/*/volumes/storage/logs/laravel*.log {
daily
missingok
rotate 14
compress
delaycompress
notifempty
create 0640 32767 32767
}" | sudo tee /etc/logrotate.d/laravel;

echo -e "server {
listen 80 default_server;
server_name _;
access_log off;
return 410;
}"  | sudo tee /etc/nginx/conf.d/00-default-vhost.conf;

# LetsEncrypt
dokku config:set --no-restart {{ $domain }} DOKKU_LETSENCRYPT_EMAIL=****
dokku plugin:install https://github.com/dokku/dokku-letsencrypt.git
dokku letsencrypt {{ $domain }}
dokku config:set --no-restart {{ $domain }} APP_URL=https://{{ $domain }}
dokku letsencrypt:cron-job --add

# Slack

dokku config:set {{ $domain }} LOG_SLACK_WEBHOOK_URL=....

--- app/Exceptions/Handler.php add to report()
if(env('LOG_SLACK_WEBHOOK_URL')) {
Cache::driver('file')->remember('error:' . $exception->getMessage(), 10, function () use ($exception) {
Log::channel('slack')->emergency(
$exception->getMessage(),
['file' => $exception->getFile() . ':' . $exception->getLine()]
);
return 1;
});
}
---

# Logs
less /home/dokku/{{ $domain }}/volumes/storage/logs/laravel.log
dokku logs {{ $domain }} -t

# Tinker
dokku run {{ $domain }} php artisan tinker
dokku enter {{ $domain }} web php artisan tinker

# Run a process
use DOKKU_SCALE file

# Postgres
dokku postgres:connect {{ $domain }}

# Destroy (if needed!!)
# rm -rf /home/dokku/{{ $domain }}/volumes/storage; dokku apps:destroy {{ $domain }} --force
