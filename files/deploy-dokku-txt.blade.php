# ssh {{ $domain }}; sudo -i

# Install dokku
export VERSION=$(curl -s https://github.com/dokku/dokku/releases/latest | cut -d '/' -f 8 | cut -d '"' -f 1)
echo $VERSION
wget https://raw.githubusercontent.com/dokku/dokku/$VERSION/bootstrap.sh;
sudo DOKKU_TAG=$VERSION bash bootstrap.sh

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

# If you want to use public storage provided by php artisan storage:link
# dokku storage:mount {{ $domain }} /home/dokku/{{ $domain }}/volumes/public/storage/:/app/storage/app/public/

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
export ELASTICSEARCH_IMAGE="docker.elastic.co/elasticsearch/elasticsearch"
export ELASTICSEARCH_IMAGE_VERSION="6.6.0"
dokku elasticsearch:create {{ $domainUnderscores }}
dokku elasticsearch:link {{ $domainUnderscores }} {{ $domain }}
# connect as
# $es = parse_url(env('ELASTICSEARCH_URL'));
# $builder = ClientBuilder::create();
# $builder->setHosts(["$es[host]:$es[port]"]);

# Redis
dokku plugin:install https://github.com/dokku/dokku-redis.git redis
dokku redis:create {{ $domainUnderscores }}
# locally: composer require predis/predis
# Change 'config/database.php'
# add this line after $db = ...
# $redis = getenv("REDIS_URL") ? parse_url(getenv("REDIS_URL")) : [];
# change this in redis section (in 2 places)
# 'host' => data_get($redis, 'host', env('REDIS_HOST', '127.0.0.1')),
# 'password' => data_get($redis, 'pass', env('REDIS_PASSWORD', null)),
# 'port' => data_get($redis, 'port', env('REDIS_PORT', 6379)),
# delete or rename the Illuminate\Support\Facades\Redis facade alias from config/app.php aliases array
# add REDIS_CLIENT=predis to local .env
# add REDIS_URL=redis://localhost:6379 to local .env
# push
dokku redis:link {{ $domainUnderscores }} {{ $domain }}
dokku config:set --no-restart {{ $domain }} REDIS_CLIENT="predis"

# Queue (after first deploy)
# First, install and link redis (see above)
dokku config:set --no-restart {{ $domain }} QUEUE_CONNECTION=redis
sudo vi /home/dokku/{{ $domain }}/hooks/pre-receive
# add before git-hook
dokku enter {{ $domain }} cron touch /app/restarting || echo "Could not touch /app/restarting on cron"
dokku enter {{ $domain }} queue php artisan queue:restart || echo "Could not send queue:restart to queue"

# Now we're ready to deploy
# Fix CHECKS file - change "Laravel" to whatever you expect to be on the main page
# Run locally:
# git init; git add .; git commit -m "Initial commit"
# git remote add {{ $domain }} dokku@{{ $domain }}:{{ $domain }}
# git push {{ $domain }} master

dokku checks:enable {{ $domain }} web
dokku checks:skip {{ $domain }} cron,queue

# Fix log rotation
echo -e "/home/dokku/*/volumes/storage/logs/laravel*.log {
daily
missingok
rotate 14
compress
delaycompress
notifempty
create 0640 32767 32767
}" | sudo tee /etc/logrotate.d/laravel;

# Fix nginx default host
# - generate fake certificate
sudo mkdir /var/www/tls/
openssl req -newkey rsa:2048 -nodes -keyout /var/www/tls/snakeoil-key.pem -x509 -days 3650 -out /var/www/tls/snakeoil-certificate.pem
echo -e "server {
listen 80 default_server;
server_name _;
access_log off;
return 410;
}
server {
listen 443 ssl http2 default_server;
listen [::]:443 ssl http2 default_server ipv6only=on;
server_name _;
ssl_certificate /var/www/tls/snakeoil-certificate.pem;
ssl_certificate_key /var/www/tls/snakeoil-key.pem;
server_name_in_redirect off;
log_not_found off;
return 410;
}"  | sudo tee /etc/nginx/conf.d/00-default-vhost.conf;

service nginx reload

# Add LetsEncrypt
dokku config:set --no-restart {{ $domain }} DOKKU_LETSENCRYPT_EMAIL=****
dokku plugin:install https://github.com/dokku/dokku-letsencrypt.git
dokku letsencrypt {{ $domain }}
dokku config:set --no-restart {{ $domain }} APP_URL=https://{{ $domain }}
dokku letsencrypt:cron-job --add

# Logs
less /home/dokku/{{ $domain }}/volumes/storage/logs/laravel.log
dokku logs {{ $domain }} -t

# Tinker
dokku run {{ $domain }} php artisan tinker
dokku enter {{ $domain }} web php artisan tinker

# Run additional process
use DOKKU_SCALE file

# Postgres
dokku postgres:connect {{ $domain }}

# Destroy (if needed!!)
# rm -rf /home/dokku/{{ $domain }}/volumes/storage; dokku apps:destroy {{ $domain }} --force


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
