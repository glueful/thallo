# shellcheck shell=bash
# The update proof's site, web server and helpers (plan Task 17). Sourced by scripts/update-proof.

up_log() { printf '%s\n' "$*" >&2; }
up_die() { up_log "update-proof: $*"; exit 1; }

up_init() {
  WORK="${UPDATE_PROOF_WORK:-$(mktemp -d /tmp/thallo-update-proof-XXXXXX)}"
  mkdir -p "$WORK/bin" "$WORK/nginx"
  PORT="${UPDATE_PROOF_PORT:-18080}"
  FCGI_PORT="${UPDATE_PROOF_FCGI_PORT:-19019}"
  # An existing database the configured user owns (the proof resets its public schema, never the database).
  PROOF_DB="${UPDATE_PROOF_DB:-app_test}"
  PHP_BIN="${PHP_BIN:-$(command -v php)}"
  [[ "$(uname)" == "Darwin" && -x /Applications/MAMP/bin/php/php8.4.17/bin/php && -z "${PHP_BIN_SET:-}" ]] \
    && PHP_BIN=/Applications/MAMP/bin/php/php8.4.17/bin/php
  NGINX_BIN="${NGINX_BIN:-$(command -v nginx || true)}"
  [[ -z "$NGINX_BIN" && -x /Applications/MAMP/Library/sbin/nginx ]] && NGINX_BIN=/Applications/MAMP/Library/sbin/nginx
  [[ -n "$NGINX_BIN" ]] || up_die "nginx not found (set NGINX_BIN)"
  if [[ -n "${PHP_FPM_BIN:-}" ]]; then
    FASTCGI=fpm
  elif [[ -x "$(dirname "$PHP_BIN")/php-cgi" ]]; then
    FASTCGI=cgi; PHP_CGI_BIN="$(dirname "$PHP_BIN")/php-cgi"
  else
    up_die "no FastCGI server: set PHP_FPM_BIN, or use a PHP with php-cgi beside it"
  fi
  local real_composer; real_composer="${COMPOSER_PHAR:-/usr/local/bin/composer}"
  [[ -f "$real_composer" ]] || real_composer="$(command -v composer)"
  # Composer under the proof's PHP, whatever shim is first on PATH.
  printf '#!/bin/sh\nexec %q %q "$@"\n' "$PHP_BIN" "$real_composer" > "$WORK/bin/composer"
  chmod +x "$WORK/bin/composer"
  export COMPOSER_BINARY="$WORK/bin/composer"
  export PATH="$WORK/bin:$(dirname "$PHP_BIN"):$PATH"
  for tool in psql pg_dump pg_restore curl; do command -v "$tool" >/dev/null || up_die "$tool not found"; done
  for key in DB_PGSQL_HOST DB_PGSQL_PORT DB_PGSQL_USERNAME DB_PGSQL_PASSWORD; do
    local val; val="$(grep -E "^${key}=" "$ROOT/.env" | head -1 | cut -d= -f2- || true)"
    printf -v "$key" '%s' "$val"
  done
  export PGOPTIONS='-c client_min_messages=warning'
  export PGHOST="${DB_PGSQL_HOST:-127.0.0.1}" PGPORT="${DB_PGSQL_PORT:-5432}" PGUSER="$DB_PGSQL_USERNAME" PGPASSWORD="$DB_PGSQL_PASSWORD"
  SINGLE_USER="${UPDATE_PROOF_SINGLE_USER:-$([[ "$(uname)" == "Darwin" ]] && echo 1 || echo 0)}"
  DEPLOY_USER="${UPDATE_PROOF_DEPLOY_USER:-thallo-deploy}"
  SITE="$WORK/site"; REPO="$WORK/repo"; ART="$WORK/artifacts"
  chmod 755 "$WORK"
  up_log "update-proof: work in $WORK (FastCGI: $FASTCGI, single user: $SINGLE_USER)"
}

# Run as the code owner (CI: a separate deploy user; locally: this user).
as_deploy() {
  if [[ "$SINGLE_USER" == 1 ]]; then "$@"; else sudo -E -u "$DEPLOY_USER" env "PATH=$PATH" "$@"; fi
}

# Run as the web user, PHP-FPM's (CI: www-data; locally: this user).
as_web() {
  if [[ "$SINGLE_USER" == 1 ]]; then "$@"; else sudo -u www-data "$@"; fi
}

up_cleanup() {
  up_web_stop || true
  [[ -n "${UPDATE_PROOF_KEEP:-}" ]] || rm -rf "$WORK"
}

up_artifacts() {
  up_log "== artifacts"
  "$PHP_BIN" "$ROOT/scripts/lib/update-proof/build-artifacts.php" "$ART" >/dev/null
}

# The artifact repository the site installs from: the `from` set, plus whatever a scenario adds.
up_repo_reset() {
  rm -rf "$REPO"; mkdir -p "$REPO"
  cp "$ART/core/from.zip" "$REPO/thallo-core-900.zip"
  for z in "$ART"/pkgs-900/*.zip; do cp "$z" "$REPO/$(basename "$z" .zip)-900.zip"; done
}

# Offer a target to the site: up_repo_add <core-variant> <packages-set: 901|902>
up_repo_add() {
  cp "$ART/core/$1.zip" "$REPO/thallo-core-$1.zip"
  for z in "$ART/pkgs-$2"/*.zip; do cp "$z" "$REPO/$(basename "$z" .zip)-$2.zip"; done
}

up_build_site() {
  up_log "== site"
  rm -rf "$SITE"; cp -R "$ROOT/skeleton/." "$SITE/"
  up_repo_reset
  "$PHP_BIN" -r '
    $f = $argv[1] . "/composer.json";
    $j = json_decode(file_get_contents($f), true);
    $j["repositories"] = [["type" => "artifact", "url" => $argv[2]]];
    $j["require"]["glueful/thallo-core"] = "^1.0.0-beta.900";
    $j["autoload"]["classmap"] = ["legacy/"];
    $j["autoload"]["files"] = ["proof-helpers.php"];
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' "$SITE" "$REPO"
  mkdir -p "$SITE/app/Proof" "$SITE/legacy"
  printf '<?php\nnamespace App\\Proof;\nfinal class Greeter { public function hi(): string { return "hi"; } }\n' > "$SITE/app/Proof/Greeter.php"
  printf '<?php\nfinal class ProofLegacy {}\n' > "$SITE/legacy/ProofLegacy.php"
  printf '<?php\nfunction update_proof_helper(): string { return "helped"; }\n' > "$SITE/proof-helpers.php"
  (cd "$SITE" && COMPOSER_HOME="$WORK/composer-home" composer update --no-interaction --no-progress >"$WORK/install.log" 2>&1) \
    || { tail -30 "$WORK/install.log"; up_die "composer install of the from version failed"; }
  grep -q '"glueful/thallo-core": "\^1.0.0-beta.900"' "$SITE/composer.json" || up_die "the root constraint changed"
  "$PHP_BIN" -r '$l=json_decode(file_get_contents($argv[1]),true); foreach($l["packages"] as $p){ if($p["name"]==="glueful/thallo-core"){ exit($p["version"]==="1.0.0-beta.900"?0:1);} } exit(1);' \
    "$SITE/composer.lock" || up_die "composer.lock does not hold 1.0.0-beta.900"

  cp "$SITE/.env.example" "$SITE/.env"
  up_env_set APP_ENV testing
  up_env_set DB_PGSQL_HOST "$PGHOST"; up_env_set DB_PGSQL_PORT "$PGPORT"
  up_env_set DB_PGSQL_USERNAME "$PGUSER"; up_env_set DB_PGSQL_PASSWORD "$PGPASSWORD"
  up_env_set DB_PGSQL_DATABASE "$PROOF_DB"; up_env_set DB_POOLING_ENABLED false
  up_env_set CACHE_DRIVER file; up_env_set QUEUE_CONNECTION database
  up_env_set BASE_URL "http://127.0.0.1:$PORT"
  up_env_set THALLO_ADMIN_UPDATES true
  up_env_set THALLO_UPDATE_VERIFY_URL "http://127.0.0.1:$PORT/_update/verify"
  up_env_set THALLO_UPDATE_BARRIER_TIMEOUT "${UPDATE_PROOF_BARRIER_TIMEOUT:-120}"

  db_reset
  (cd "$SITE" && "$PHP_BIN" glueful thallo:provision --no-interaction >"$WORK/provision.log" 2>&1) \
    || { tail -30 "$WORK/provision.log"; up_die "provision of the from version failed"; }
  grep -q "Update runner: installed-first" "$WORK/provision.log" || up_die "provision did not install the update runner"
  touch "$SITE/.thallo-update/.proof"
  up_permissions
}

# Two users (CI): the code belongs to the deploy user and is only readable by the web user's group;
# storage/ is writable by both (the group, setgid). The update folders keep provision's modes (spec
# §16.1): private 0700, control 0750 with 0640 files, the inbox 2770. One user (local): nothing to do.
up_permissions() {
  [[ "$SINGLE_USER" == 1 ]] && return 0
  chown -R "$DEPLOY_USER:thallo-site" "$SITE"
  chmod -R u+rwX,g+rX,g-w,o-rwx "$SITE"
  chmod -R g+rwX "$SITE/storage"
  find "$SITE/storage" -type d -exec chmod g+s {} +
  chmod -R go-rwx "$SITE/.thallo-update"
  chmod 0750 "$SITE/.thallo-update-control"
  find "$SITE/.thallo-update-control" -type f -exec chmod 0640 {} +
  chmod 2770 "$SITE/storage/framework/updates"
}

up_env_set() {
  local key="$1" val="$2" f="$SITE/.env"
  if grep -qE "^#? ?${key}=" "$f"; then
    KEY="$key" VAL="$val" perl -0pi -e 's/^#? ?\Q$ENV{KEY}\E=.*$/$ENV{KEY}=$ENV{VAL}/m' "$f"
  else
    printf '%s=%s\n' "$key" "$val" >> "$f"
  fi
}

db_reset() {
  psql -q -v ON_ERROR_STOP=1 -d "$PROOF_DB" -c "DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;" >/dev/null \
    || up_die "cannot reset the public schema of $PROOF_DB (set UPDATE_PROOF_DB to a database this user owns)"
}

up_snapshot() {
  up_log "== snapshot"
  tar -C "$WORK" -cf "$WORK/site.tar" site
  pg_dump -Fc -d "$PROOF_DB" -f "$WORK/site.dump"
}

up_restore() {
  up_web_stop
  rm -rf "$SITE"; tar -C "$WORK" -xf "$WORK/site.tar"
  up_permissions
  db_reset
  pg_restore --no-owner -d "$PROOF_DB" "$WORK/site.dump" >/dev/null 2>&1 || true
  up_repo_reset
  up_web_start
}

# ---- web ---------------------------------------------------------------------------------------

up_web_start() {
  if [[ "$FASTCGI" == cgi ]]; then
    PHP_FCGI_CHILDREN=4 PHP_FCGI_MAX_REQUESTS=0 "$PHP_CGI_BIN" -b "127.0.0.1:$FCGI_PORT" \
      -d zend_extension=opcache -d opcache.enable=1 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=2 \
      >"$WORK/fcgi.log" 2>&1 &
    echo $! > "$WORK/fcgi.pid"
  else
    cat > "$WORK/fpm.conf" <<CONF
[global]
pid = $WORK/fpm.pid
error_log = $WORK/fpm.log
daemonize = no
[proof]
listen = 127.0.0.1:$FCGI_PORT
pm = static
pm.max_children = 4
process_control_timeout = 120
$( [[ "$SINGLE_USER" == 1 ]] || printf 'user = www-data\ngroup = thallo-site\n' )
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 2
CONF
    "$PHP_FPM_BIN" -y "$WORK/fpm.conf" -F >"$WORK/fpm.out" 2>&1 &
    echo $! > "$WORK/fcgi.pid"
  fi
  mkdir -p "$WORK/nginx/tmp"
  cat > "$WORK/nginx/nginx.conf" <<CONF
worker_processes 1;
$( [[ "$SINGLE_USER" == 1 ]] || printf 'user www-data thallo-site;' )
pid $WORK/nginx/nginx.pid;
error_log $WORK/nginx/error.log;
events { worker_connections 64; }
http {
  access_log off;
  client_body_temp_path $WORK/nginx/tmp/body;
  fastcgi_temp_path $WORK/nginx/tmp/fastcgi;
  proxy_temp_path $WORK/nginx/tmp/proxy;
  uwsgi_temp_path $WORK/nginx/tmp/uwsgi;
  scgi_temp_path $WORK/nginx/tmp/scgi;
  default_type application/octet-stream;
  server {
    listen 127.0.0.1:$PORT;
    root $SITE/public;
    location / { try_files \$uri /index.php\$is_args\$args; }
    location ~ \.php\$ {
      fastcgi_pass 127.0.0.1:$FCGI_PORT;
      fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
      fastcgi_param SCRIPT_NAME \$fastcgi_script_name;
      fastcgi_param QUERY_STRING \$query_string;
      fastcgi_param REQUEST_METHOD \$request_method;
      fastcgi_param CONTENT_TYPE \$content_type;
      fastcgi_param CONTENT_LENGTH \$content_length;
      fastcgi_param REQUEST_URI \$request_uri;
      fastcgi_param DOCUMENT_ROOT \$document_root;
      fastcgi_param SERVER_NAME \$server_name;
      fastcgi_param SERVER_PORT \$server_port;
      fastcgi_param REMOTE_ADDR \$remote_addr;
      fastcgi_param HTTP_HOST \$http_host;
      fastcgi_read_timeout 300;
    }
  }
}
CONF
  "$NGINX_BIN" -p "$WORK/nginx" -c "$WORK/nginx/nginx.conf"
  # Ready when PHP itself answers: nginx alone would answer 502 before the FastCGI server is listening.
  for _ in $(seq 1 150); do [[ "$(http_code "http://127.0.0.1:$PORT/_update")" == 200 ]] && return 0; sleep 0.2; done
  up_die "the web server did not answer on $PORT"
}

up_web_stop() {
  [[ -f "$WORK/nginx/nginx.pid" ]] && { kill "$(cat "$WORK/nginx/nginx.pid")" 2>/dev/null || true; rm -f "$WORK/nginx/nginx.pid"; }
  if [[ -f "$WORK/fcgi.pid" ]]; then
    local pid; pid="$(cat "$WORK/fcgi.pid")"
    pkill -P "$pid" 2>/dev/null || true; kill "$pid" 2>/dev/null || true
    rm -f "$WORK/fcgi.pid"
  fi
  sleep 0.3
}

# A full PHP-FPM / FastCGI restart: graceful stop, every old process gone, a new instance (scenarios 20, 23).
fpm_restart() {
  local pid; pid="$(cat "$WORK/fcgi.pid")"
  kill -QUIT "$pid" 2>/dev/null || true
  for _ in $(seq 1 1500); do
    pgrep -P "$pid" >/dev/null 2>&1 || ! kill -0 "$pid" 2>/dev/null && break
    sleep 0.1
  done
  kill -0 "$pid" 2>/dev/null && up_die "the old FastCGI master $pid did not exit"
  rm -f "$WORK/fcgi.pid"
  "$NGINX_BIN" -p "$WORK/nginx" -c "$WORK/nginx/nginx.conf" -s stop 2>/dev/null || true
  sleep 0.3
  up_web_start
}

# ---- the runner and the operation ----------------------------------------------------------------

tick() { (cd "$SITE" && as_deploy "$PHP_BIN" bin/thallo-updater tick) >>"$WORK/ticks.log" 2>&1 || true; }

# The runner's private record (the proof reads it as root in CI, as the one user locally).
OP_FILE_REL=.thallo-update/operation.json
op_field() { "$PHP_BIN" -r '$o=json_decode(@file_get_contents($argv[1]),true); $v=$o; foreach(explode(".",$argv[2]) as $k){ $v=$v[$k]??null; } echo $v === null ? "" : (is_scalar($v)?$v:json_encode($v));' "$SITE/$OP_FILE_REL" "$1"; }

tick_until() {
  local want="$1" max="${2:-180}"
  for _ in $(seq 1 "$max"); do
    tick
    [[ "$(op_field status)" == "$want" ]] && return 0
    sleep 1
  done
  up_log "status is $(op_field status), not $want; error: $(op_field error)"
  return 1
}

# A request filed in the inbox as the web user, as the admin's start endpoint does (scenarios that are
# not about the admin's preflight); the token in $WORK/token. The runner claims it on its next tick.
request() {
  "$PHP_BIN" -r 'echo bin2hex(random_bytes(32));' > "$WORK/token"
  file_request "$("$PHP_BIN" -r 'echo json_encode(["target" => $argv[1], "from" => "1.0.0-beta.900",
    "requested_by" => "update-proof", "requested_at" => gmdate("c"), "token_hash" => hash("sha256", $argv[2])]);' \
    "$1" "$(cat "$WORK/token")")"
}

# file_request <json>: write start.json into the inbox as the web user (temp file, then rename).
file_request() {
  as_web sh -c 'umask 007; printf "%s" "$1" > "$2.tmp" && mv "$2.tmp" "$2"' sh "$1" "$SITE/storage/framework/updates/start.json"
}

confirm_restart() { (cd "$SITE" && as_deploy "$PHP_BIN" bin/thallo-updater confirm-restart --yes) >"$WORK/confirm.log" 2>&1; }

# ---- assertions ------------------------------------------------------------------------------------

version_is() {
  local got; got="$(cd "$SITE" && THALLO_UPDATE_CHILD=1 as_deploy "$PHP_BIN" glueful thallo:update:verify --json 2>/dev/null | tail -1 \
    | "$PHP_BIN" -r 'echo json_decode(stream_get_contents(STDIN),true)["version"] ?? "";')"
  [[ "$got" == "$1" ]] || { up_log "command line reports '$got', expected $1"; return 1; }
}

web_version_is() {
  local secret got
  secret="$(tr -d '\n' < "$SITE/.thallo-update-control/verify.secret")"
  got="$(curl -s -X POST -H "x-thallo-verify: $secret" -H 'Content-Type: application/json' -d '{"mode":"boot"}' \
    "http://127.0.0.1:$PORT/_update/verify" | "$PHP_BIN" -r 'echo json_decode(stream_get_contents(STDIN),true)["version"] ?? "";')"
  [[ "$got" == "$1" ]] || { up_log "PHP-FPM reports '$got', expected $1"; return 1; }
}

http_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
site_open() { local c; c="$(http_code "http://127.0.0.1:$PORT/")"; [[ "$c" != 503 ]] || { up_log "site answered 503"; return 1; }; }
site_closed() { local c; c="$(http_code "http://127.0.0.1:$PORT/")"; [[ "$c" == 503 ]] || { up_log "site answered $c, not 503"; return 1; }; }
table_exists() { [[ "$(psql -tA -d "$PROOF_DB" -c "SELECT to_regclass('public.$1') IS NOT NULL")" == t ]]; }
status_is() { [[ "$(op_field status)" == "$1" ]] || { up_log "status $(op_field status), expected $1 ($(op_field error))"; return 1; }; }
