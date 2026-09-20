#!/usr/bin/env bash
# Container entrypoint: make the bind-mounted workspace usable, then hand over to
# whatever command was requested (`sleep infinity` by default).
set -euo pipefail

APP_USER="${APP_USER:-dev}"
WORKSPACE="${WORKSPACE:-/workspace}"

log() {
    printf '[entrypoint] %s\n' "$*" >&2
}

# Everything below writes to the bind mount, which means it writes to the host's
# checkout. Only root can hand the results to the app user; when the container is
# started with an explicit `user:` we are already that user and skip the fixups.
if [ "$(id -u)" = "0" ]; then
    run_as_app_user() {
        su "$APP_USER" -s /bin/bash -c "$1"
    }
else
    run_as_app_user() {
        bash -c "$1"
    }
fi

# Directories the suite and php.ini's error_log expect to exist but that are
# gitignored, so a fresh worktree does not have them. build/ itself is included
# (not just its children): phpunit.xml's result cache lives directly in it, and
# TempDir fixtures create scratch directories under build/tmp/.
for dir in \
    "$WORKSPACE/build" \
    "$WORKSPACE/build/tmp" \
    "$WORKSPACE/build/coverage" \
    "$WORKSPACE/build/logs"
do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        # Created as root; the app user has to be able to write to it.
        [ "$(id -u)" = "0" ] && chown "$APP_USER:$APP_USER" "$dir"
    fi
done

# The loop above only chowns a directory it just created, so a build/ that
# already exists owned by root (from before this fix, or from an older image)
# would otherwise be skipped. Non-recursive: the children are already handled
# above, and nothing else under the workspace needs to change hands.
if [ "$(id -u)" = "0" ] && [ "$(stat -c '%U' "$WORKSPACE/build")" != "$APP_USER" ]; then
    chown "$APP_USER:$APP_USER" "$WORKSPACE/build"
fi

# A fresh worktree has no vendor/ (it is gitignored), and every useful command in
# this container needs it. Install once, on first start, rather than making each
# caller remember to.
if [ "${AUTO_COMPOSER_INSTALL:-1}" = "1" ] && [ ! -f "$WORKSPACE/vendor/autoload.php" ]; then
    log "vendor/ is missing — running composer install (first start only)"
    if run_as_app_user "cd '$WORKSPACE' && XDEBUG_MODE=off composer install --no-interaction --prefer-dist --no-progress"; then
        log "composer install complete"
    else
        # Don't take the whole container down: a shell inside it is exactly what
        # you want in order to find out why the install failed.
        log "WARNING: composer install failed — run 'docker/anorm-graphql composer install' to retry"
    fi
fi

exec "$@"
