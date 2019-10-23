# Creates the project with all scaffolding for Dokku deployment.

```
git clone git@github.com:slava-vishnyakov/dokku-laravel.git
dokku-laravel/dokku-laravel project project.com
```

Creates a `project` folder suitable for deployment to `project.com`

See the generated `dokku-deploy.txt` file for complete instructions.

[![CircleCI](https://circleci.com/gh/slava-vishnyakov/dokku-laravel/tree/master.svg?style=svg)](https://circleci.com/gh/slava-vishnyakov/dokku-laravel/tree/master)

# Production assets builds

Create a file `push.sh`:

```sh
#!/usr/bin/env bash

CHANGED=$(git diff-index --name-only HEAD --)
REMOTE="your-domain.com"

if [ $REMOTE == "your-domain.com" ]; then echo -e "[!] Set REMOTE in push.sh"; exit 1; fi
if [ -n "$CHANGED" ]; then echo -e "[!] Commit your changes first"; exit 1; fi

export BRANCH="prod-1"
trap 'git checkout master; git branch -D $BRANCH' EXIT
git checkout -B "$BRANCH"
yarn production
git add public/js/app.js public/css/app.css public/mix-manifest.json
git commit -m 'Production build'
git push -f "$REMOTE" "$BRANCH":master
```

Change `your-domain.com` to your git remote name, usually - your domain.

Change `package.json` where it says "push-yourdomain.com" to `./push.sh`:
```
    "push-...": "./push.sh",
```

Now run `push.sh` to push - it will create a branch, build production assets 
and force push it without having to commit JS, CSS files.

