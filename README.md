# Creates the project with all scaffolding for Dokku deployment.

```
git clone git@github.com:slava-vishnyakov/dokku-laravel.git
(cd dokku-laravel && composer install)
dokku-laravel/dokku-laravel new project project.com
```

Creates a `project` folder suitable for deployment to `project.com`

See the generated `dokku-deploy.txt` file for complete instructions.

[![Run Tests](https://github.com/slava-vishnyakov/dokku-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/slava-vishnyakov/dokku-laravel/actions/workflows/tests.yml)

# Production assets builds

Create a file `push.sh`:

```sh
#!/usr/bin/env bash

CHANGED=$(git diff-index --name-only HEAD --)
REMOTE="your-domain.com"

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ $BRANCH != "master" ]; then echo -e "[!] Not on master branch"; exit 1; fi

if [ $REMOTE == "your-domain.com" ]; then echo -e "[!] Set REMOTE in push.sh"; exit 1; fi
if [ -n "$CHANGED" ]; then echo -e "[!] Commit your changes first"; exit 1; fi

export BRANCH="prod-1"
trap 'git checkout master; git branch -D $BRANCH' EXIT
git checkout -B "$BRANCH"
yarn production
git add -f public/js/app.js public/css/app.css public/mix-manifest.json
git commit -m 'Production build'
git push -f "$REMOTE" "$BRANCH":master
```

Change `your-domain.com` to your git remote name, usually - your domain.

Change `package.json` where it says "push-yourdomain.com" to `./push.sh`:
```
    "push-...": "./push.sh",
```

1) run `push.sh` to push - it will create a branch, build production assets 
and force push it without having to commit JS, CSS files.

2) Remove app.css, app.js from Git if you have committed them previously 

```
git rm --cached public/mix-manifest.json public/js/app.js public/css/app.css
```

Add to `.gitignore`:

```
/public/mix-manifest.json
/public/js/app.js
/public/css/app.css
```

This might come in handy (in `webpack.mix.js`):

```js
mix.js('resources/js/app.js', 'public/js')
    .options({
        terser: {
            terserOptions: {
                keep_fnames: true,
                safari10: true,
            }
        }
    })
```
