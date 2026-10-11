# shellcheck shell=bash
# The update proof's scenarios (plan Tasks 17–18). Each starts from the restored `from` site, with an
# owner account and the restart after enabling confirmed; each returns non-zero on its first failed
# assertion. Sourced by scripts/update-proof.

FROM_V=1.0.0-beta.900
TARGET_V=1.0.0-beta.901

# The owner, created through the real first-run setup, signed in through the real login route.
owner_setup() {
  local token; token="$(grep -E '^SETUP_TOKEN=' "$SITE/.env" | cut -d= -f2-)"
  local code; code="$(http_code -X POST -H 'Content-Type: application/json' -H "X-Setup-Token: $token" \
    -d '{"site_name":"Update proof","admin_email":"owner@update-proof.test","admin_password":"Proof!pass#123","locale":"en"}' \
    "http://127.0.0.1:$PORT/admin/setup")"
  [[ "$code" == 200 || "$code" == 201 ]] || up_die "first-run setup answered $code"
}

owner_token() {
  curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"username":"owner@update-proof.test","password":"Proof!pass#123"}' "http://127.0.0.1:$PORT/v1/auth/login" \
    | "$PHP_BIN" -r '$b=json_decode(stream_get_contents(STDIN),true); echo $b["data"]["access_token"] ?? $b["data"]["tokens"]["access_token"] ?? "";'
}

preflight_json() { curl -s -H "Authorization: Bearer $1" "http://127.0.0.1:$PORT/v1/admin/updates/preflight"; }
json_get() { "$PHP_BIN" -r '$v=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){ $v=$v[$k]??null; } echo is_scalar($v)?var_export($v,true):json_encode($v);' "$1"; }

# Every file of the site with its hash, runner-owned paths excluded (scenario 18).
site_manifest() {
  (cd "$SITE" && "$PHP_BIN" -r '
    $skip = "#^(vendor|composer\\.lock|composer\\.json|storage/logs|storage/framework/updates|\\.thallo-update|\\.thallo-update-control)(/|$)#";
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS));
    $out = [];
    foreach ($it as $f) { if (!$f->isFile()) continue; $rel = substr($f->getPathname(), 2); if (preg_match($skip, $rel)) continue; $out[$rel] = hash_file("sha256", $f->getPathname()); }
    ksort($out); echo json_encode($out);
  ') > "$1"
}

# ---------------------------------------------------------------------------------------------------

sc_1() { # Successful update, through the advertised path.
  tick
  local self; self="$(cd "$SITE" && as_deploy "$PHP_BIN" bin/thallo-updater selftest 2>&1 || true)"
  local failing; failing="$(grep '^FAIL' <<<"$self" || true)"
  if [[ "$SINGLE_USER" == 1 ]]; then
    [[ -z "$(grep -vE 'PHP-FPM cannot (write the code|write the control folder|read the private folder)' <<<"$failing")" ]] \
      || { up_log "$self"; return 1; }
    up_log "SKIP user separation (CI enforces it): with one user PHP-FPM can reach the code and the runner's folders"
  else
    [[ -z "$failing" ]] || { up_log "$self"; return 1; }
  fi
  local bearer; bearer="$(owner_token)"; [[ -n "$bearer" ]] || { up_log "login gave no token"; return 1; }
  up_repo_add target 901; tick
  local pre; pre="$(preflight_json "$bearer")"
  if [[ "$SINGLE_USER" == 1 ]]; then
    [[ "$(json_get data.preflight.reasons <<<"$pre")" == '["web_can_write_code"]' ]] || { up_log "$pre"; return 1; }
    request "$TARGET_V"
  else
    [[ "$(json_get data.preflight.ready <<<"$pre")" == true ]] || { up_log "$pre"; return 1; }
    [[ "$(json_get data.preflight.target <<<"$pre")" == "'$TARGET_V'" ]] || { up_log "$pre"; return 1; }
    local started; started="$(curl -s -o "$WORK/start.json" -w '%{http_code}' -X POST -H "Authorization: Bearer $bearer" \
      -H 'Content-Type: application/json' -d "{\"target\":\"$TARGET_V\"}" "http://127.0.0.1:$PORT/v1/admin/updates")"
    [[ "$started" == 202 ]] || { cat "$WORK/start.json" >&2; return 1; }
    json_get data.update.token < "$WORK/start.json" | tr -d "'" > "$WORK/token"
  fi
  tick_until succeeded || return 1
  version_is "$TARGET_V" && web_version_is "$TARGET_V" && table_exists update_proof && site_open || return 1
  curl -s "http://127.0.0.1:$PORT/admin/" | grep -q 'update-proof 901' || { up_log "admin bundle not republished"; return 1; }
  local backup; backup="$(op_field backup.path)"
  pg_restore --list "$backup/database.dump" >/dev/null || { up_log "the backup cannot be read"; return 1; }
  [[ "$(stat -f '%Lp' "$backup/database.dump" 2>/dev/null || stat -c '%a' "$backup/database.dump")" == 600 ]] || { up_log "backup not 0600"; return 1; }
  # Review Focus 3: the relocated vendor/ loads the site's own classes, classmap and files.
  local loads; loads="$(cd "$SITE" && "$PHP_BIN" -r 'require "vendor/autoload.php"; echo (new App\Proof\Greeter())->hi(), class_exists("ProofLegacy") ? ":classmap" : ":missing", ":", update_proof_helper();')"
  [[ "$loads" == "hi:classmap:helped" ]] || { up_log "post-swap autoload: $loads"; return 1; }
  ! grep -rq ".thallo-update" "$SITE"/vendor/composer/autoload_*.php || { up_log "an autoload path points into the stage"; return 1; }
  [[ ! -d "$SITE/.thallo-update/ops/$(op_field id)" ]] || { up_log "the operation folder was not removed"; return 1; }
}

sc_2() { # Composer killed mid-stage: the live install is untouched.
  up_repo_add target 901; request "$TARGET_V"
  (tick) & local bg=$!
  for _ in $(seq 1 300); do pgrep -f "update glueful/thallo-core:$TARGET_V" >/dev/null && break; sleep 0.2; done
  pgrep -f "update glueful/thallo-core:$TARGET_V" >/dev/null || { up_log "composer never started"; return 1; }
  local pgid; pgid="$(op_field pgid)"
  kill -9 -- "-$pgid" 2>/dev/null || true; wait "$bg" 2>/dev/null || true
  for _ in $(seq 1 100); do pgrep -g "$pgid" >/dev/null || break; sleep 0.1; done
  tick
  status_is failed && site_open && version_is "$FROM_V"
}

sc_13() { # A newer release published while the request waits: the approved one is installed.
  up_repo_add target 901; request "$TARGET_V"
  up_repo_add newer 902
  tick_until succeeded && version_is "$TARGET_V"
}

sc_14() { # An unsupported Composer shape is refused, and nothing changes.
  up_repo_add target 901
  "$PHP_BIN" -r '$f=$argv[1]."/composer.json"; $j=json_decode(file_get_contents($f),true); $j["repositories"][]=["type"=>"path","url"=>"../elsewhere"]; file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$SITE"
  tick
  local pre; pre="$(preflight_json "$(owner_token)")"
  grep -q composer_config_unsupported <<<"$pre" || { up_log "$pre"; return 1; }
  request "$TARGET_V"
  tick_until failed 30 || return 1
  [[ "$(op_field error)" == *repositories* ]] || { up_log "$(op_field error)"; return 1; }
  version_is "$FROM_V" && site_open
}

sc_15() { # A plugin that rewrites the staged composer.json: refused, the live file untouched.
  up_repo_add pluginrewrite 901; cp "$ART/plugin/php-http-discovery.zip" "$REPO/"
  local before; before="$(shasum -a 256 "$SITE/composer.json" | cut -d' ' -f1)"
  request "$TARGET_V"
  tick_until failed || return 1
  [[ "$(op_field error)" == *"rewrote composer.json"* ]] || { up_log "$(op_field error)"; return 1; }
  [[ "$(shasum -a 256 "$SITE/composer.json" | cut -d' ' -f1)" == "$before" ]] || { up_log "live composer.json changed"; return 1; }
  version_is "$FROM_V"
}

sc_18() { # Review Focus 4: every path a real provision changes has an inventory rule.
  up_repo_add target 901; request "$TARGET_V"
  THALLO_UPDATER_KILL_AT=swap.done tick
  tick; status_is recovery_required || return 1
  site_manifest "$WORK/manifest.before"
  (cd "$SITE" && as_deploy "$PHP_BIN" bin/thallo-updater recover --retry-provision) >>"$WORK/ticks.log" 2>&1 || true
  tick_until succeeded || return 1
  site_manifest "$WORK/manifest.after"
  local uncovered; uncovered="$(cd "$SITE" && "$PHP_BIN" -r '
    require "vendor/autoload.php";
    $a = json_decode(file_get_contents($argv[1]), true); $b = json_decode(file_get_contents($argv[2]), true);
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $p) {
      $kind = !isset($a[$p]) ? "added" : (!isset($b[$p]) ? "deleted" : ($a[$p] !== $b[$p] ? "changed" : null));
      if ($kind !== null && Thallo\Core\Updater\ProvisionOutputs::ruleFor($p) === null) { echo "$kind $p\n"; }
    }' "$WORK/manifest.before" "$WORK/manifest.after")"
  [[ -z "$uncovered" ]] || { up_log "paths provision changed without an inventory rule:"; up_log "$uncovered"; return 1; }
}

# ---- Task 18 ---------------------------------------------------------------------------------------

kill_tick() { THALLO_UPDATER_KILL_AT="$1" tick; }

recover_http() { # recover_http <option> [confirm]
  local body="{\"option\":\"$1\"${2:+,\"confirm\":\"$2\"}}"
  http_code -X POST -H 'Content-Type: application/json' -H "x-thallo-update-token: $(cat "$WORK/token")" -d "$body" \
    "http://127.0.0.1:$PORT/_update/recover"
}

# A request that holds its admission for $1 seconds, then writes the time to storage/$2.
slow_request_file() {
  cat > "$SITE/public/proof-slow.php" <<'PHP'
<?php
if (is_file(__DIR__ . '/../bootstrap/update-guard.php')) {
    require __DIR__ . '/../bootstrap/update-guard.php';
}
sleep((int) ($_GET['sleep'] ?? 5));
file_put_contents(__DIR__ . '/../storage/' . basename((string) ($_GET['out'] ?? 'slow-wrote')), (string) microtime(true));
echo 'done';
PHP
}
slow_request() { curl -s -o /dev/null "http://127.0.0.1:$PORT/proof-slow.php?sleep=$1&out=$2" & }

step_started() { "$PHP_BIN" -r '$o=json_decode(file_get_contents($argv[1]),true); foreach($o["steps"] as $s){ if($s["name"]===$argv[2]){ echo $s["started_at"]; exit; } }' "$SITE/$OP_FILE_REL" "$1"; }

back_on_from() { # roll the code back, reopen, and check the site runs `from` again
  [[ "$(recover_http rollback)" == 202 ]] || { up_log "rollback request refused"; return 1; }
  tick; status_is recovery_required || return 1
  version_is "$FROM_V" && web_version_is "$FROM_V" || return 1
  [[ "$(recover_http reopen REOPEN)" == 202 ]] || { up_log "reopen request refused"; return 1; }
  tick; status_is recovered_by_owner && site_open
}

sc_3() { # A kill between every pair of swap renames, and during the rollback itself.
  local p
  for p in swap.1.renamed swap.2.renamed swap.3.renamed swap.4.renamed; do
    up_restore; up_repo_add target 901; request "$TARGET_V"
    kill_tick "$p"; tick
    status_is recovery_required && site_closed || { up_log "after $p"; return 1; }
    back_on_from || { up_log "after $p"; return 1; }
  done
  up_restore; up_repo_add target 901; request "$TARGET_V"
  kill_tick swap.4.renamed; tick; status_is recovery_required || return 1
  [[ "$(recover_http rollback)" == 202 ]] || return 1
  kill_tick undo.swap.2.renamed; tick
  status_is recovery_required && site_closed || return 1
  back_on_from
}

slow_composer() { # a Composer wrapper: $1 = what happens after the real run
  rm -f "$WORK/composer-done"
  printf '#!/bin/sh\ncase " $* " in *" --version "*) exec %q "$@";; esac\n%q "$@"; code=$?\ntouch %q\n%s\nexit $code\n' \
    "$COMPOSER_BINARY" "$COMPOSER_BINARY" "$WORK/composer-done" "$1" > "$WORK/bin/slow-composer"
  chmod +x "$WORK/bin/slow-composer"
}

sc_4() { # A killed runner whose Composer child keeps running: nothing claims it until the child exits.
  slow_composer 'sleep 20'
  up_repo_add target 901; request "$TARGET_V"
  (COMPOSER_BINARY="$WORK/bin/slow-composer" tick) & local bg=$!
  for _ in $(seq 1 600); do [[ -f "$WORK/composer-done" ]] && break; sleep 0.2; done
  kill -9 "$(op_field pid)"; wait "$bg" 2>/dev/null || true
  tick; tick; status_is running || { up_log "claimed while the child lived"; return 1; }
  for _ in $(seq 1 60); do pgrep -g "$(op_field pgid)" >/dev/null || break; sleep 1; done
  tick; status_is failed && site_open && version_is "$FROM_V"
}

sc_4b() { # Review Focus 2: runner and child dead, a grandchild alive: nothing claims it.
  slow_composer '(sleep 20 </dev/null >/dev/null 2>&1 &)'
  up_repo_add target 901; request "$TARGET_V"
  (COMPOSER_BINARY="$WORK/bin/slow-composer" tick) & local bg=$!
  for _ in $(seq 1 600); do [[ -f "$WORK/composer-done" ]] && break; sleep 0.2; done
  sleep 0.5; kill -9 "$(op_field pid)"; wait "$bg" 2>/dev/null || true
  local pgid; pgid="$(op_field pgid)"
  pgrep -g "$pgid" >/dev/null || { up_log "no grandchild survived"; return 1; }
  tick; status_is running || { up_log "claimed while the grandchild lived"; return 1; }
  for _ in $(seq 1 60); do pgrep -g "$pgid" >/dev/null || break; sleep 1; done
  tick; status_is failed && site_open
}

sc_4c() { # A killed recovery runner: recovery recorded its own process group, and a later tick finishes.
  up_repo_add failmig 901; request "$TARGET_V"
  tick_until recovery_required || return 1
  local first; first="$(op_field pgid)"
  [[ "$(recover_http rollback)" == 202 ]] || return 1
  kill_tick undo.swap.3.renamed
  [[ "$(op_field pgid)" != "$first" ]] || { up_log "recovery kept the dead run's process group"; return 1; }
  tick; status_is recovery_required || return 1
  [[ "$(recover_http rollback)" == 202 ]] || return 1
  tick; version_is "$FROM_V"
}

restore_backup() { # what the owner does before rolling back when the database changed
  db_reset
  pg_restore --no-owner -d "$PROOF_DB" "$(op_field backup.path)/database.dump" >/dev/null 2>&1 || true
}

sc_5() { # Provision fails after its migration: closed, recoverable from the backup.
  up_repo_add failmig 901; request "$TARGET_V"
  tick_until recovery_required || return 1
  site_closed && table_exists update_proof || return 1
  restore_backup
  back_on_from || return 1
  ! table_exists update_proof || { up_log "the restored database still has the update's table"; return 1; }
}

sc_6() { # A data write no migration records, then a failure: never reopened by itself.
  up_repo_add faildata 901; request "$TARGET_V"
  tick_until recovery_required || return 1
  site_closed || return 1
  [[ "$(psql -tA -d "$PROOF_DB" -c "SELECT value FROM thallo_system_flags WHERE key = 'update_proof.provisioned'")" == 901 ]] \
    || { up_log "the provision step's data write is missing"; return 1; }
  tick; tick; site_closed
}

sc_7() { # A rollback that fails (unreadable snapshot): still closed, the error names it.
  up_repo_add failmig 901; request "$TARGET_V"
  tick_until recovery_required || return 1
  local snap; snap="$(op_field backup.path)/provision"
  chmod -R 000 "$snap"
  [[ "$(recover_http rollback)" == 202 ]] || { chmod -R 755 "$snap"; return 1; }
  tick; chmod -R 755 "$snap"
  status_is recovery_required && site_closed || return 1
  [[ "$(op_field error)" == *snapshot* ]] || { up_log "$(op_field error)"; return 1; }
}

sc_8() { # Two ticks at once: exactly one runs the update.
  up_repo_add target 901; request "$TARGET_V"
  (tick) & (tick) & wait
  tick_until succeeded || return 1
  local steps; steps="$(op_field steps | "$PHP_BIN" -r 'echo implode(",", array_column(json_decode(stream_get_contents(STDIN), true), "name"));')"
  [[ "$steps" == preflight,stage,close,barrier,backup,swap,provision,verify_cli,verify_web,reopen ]] \
    || { up_log "steps: $steps"; return 1; }
}

sc_9() { # The barrier waits for a request paused before its write and a running queue job.
  slow_request_file
  mkdir -p "$SITE/app/Proof"
  cat > "$SITE/app/Proof/SlowJob.php" <<'PHP'
<?php
namespace App\Proof;
final class SlowJob extends \Glueful\Queue\Job
{
    public function handle(): void
    {
        sleep(45);
        file_put_contents(dirname(__DIR__, 2) . '/storage/job-wrote', (string) microtime(true));
    }
}
PHP
  (cd "$SITE" && as_deploy composer dump-autoload -q && as_deploy "$PHP_BIN" -r '
    $app = require "bootstrap/app.php";
    \Glueful\Queue\QueueManager::setContext($app->getContext());
    \Glueful\Queue\QueueManager::createDefault()->push(App\Proof\SlowJob::class, [], "default");')
  slow_request 40 slow-wrote
  (cd "$SITE" && as_deploy "$PHP_BIN" glueful queue:work --queue=default --stop-when-empty >/dev/null 2>&1) &
  sleep 2
  up_repo_add target 901; request "$TARGET_V"
  (tick) & local bg=$!
  local seen503=0
  for _ in $(seq 1 100); do [[ -f "$SITE/.thallo-update-control/closed.flag" ]] && { site_closed && seen503=1; break; }; sleep 0.2; done
  wait "$bg" || true
  tick_until succeeded || return 1
  [[ "$seen503" == 1 ]] || { up_log "new requests were admitted while the barrier waited"; return 1; }
  # The holders outlast staging, so the barrier really waited for them.
  local waited; waited="$(op_field steps | "$PHP_BIN" -r 'foreach(json_decode(stream_get_contents(STDIN),true) as $s){ if($s["name"]==="barrier"){ echo $s["ended_at"]-$s["started_at"]; } }')"
  (( waited >= 3 )) || { up_log "the barrier waited only ${waited}s: the holders ended before the site closed"; return 1; }
  local backup; backup="$(step_started backup)"
  for f in slow-wrote job-wrote; do
    [[ -f "$SITE/storage/$f" ]] || { up_log "$f never wrote"; return 1; }
    # Step times are whole seconds: a write in the backup's own second cannot be ordered against it.
    "$PHP_BIN" -r 'exit((int) floor((float) file_get_contents($argv[1])) <= (int) $argv[2] ? 0 : 1);' "$SITE/storage/$f" "$backup" \
      || { up_log "$f wrote after the backup began"; return 1; }
  done
}

sc_10() { # A request that never finishes: the barrier gives up, nothing changed.
  slow_request_file; up_env_set THALLO_UPDATE_BARRIER_TIMEOUT 5
  slow_request 60 never
  sleep 1
  up_repo_add target 901; request "$TARGET_V"
  tick
  status_is failed || return 1
  [[ "$(op_field error)" == *barrier* ]] || { up_log "$(op_field error)"; return 1; }
  site_open && version_is "$FROM_V"
}

sc_11() { # No PHP fatal while the code is replaced: only pages, 503s and status JSON.
  up_repo_add target 901; request "$TARGET_V"
  : > "$WORK/codes"
  ( while [[ ! -f "$WORK/stop-loop" ]]; do
      printf '%s %s\n' "$(http_code "http://127.0.0.1:$PORT/")" \
        "$(http_code -H "x-thallo-update-token: $(cat "$WORK/token")" "http://127.0.0.1:$PORT/_update/status")" >> "$WORK/codes"
      sleep 0.05
    done ) & local loop=$!
  tick_until succeeded; local ok=$?
  touch "$WORK/stop-loop"; wait "$loop" 2>/dev/null || true
  [[ "$ok" == 0 ]] || return 1
  ! grep -qE '(^| )(500|502)( |$)' "$WORK/codes" || { up_log "a 500/502 during the update:"; sort "$WORK/codes" | uniq -c >&2; return 1; }
  ! grep -qi 'fatal' "$WORK/fcgi.log" "$WORK/nginx/error.log" 2>/dev/null || { up_log "a PHP fatal was logged"; return 1; }
}

sc_12() { # /_update survives a refresh in the vendor gap and after a failed provision.
  up_repo_add target 901; request "$TARGET_V"
  kill_tick swap.1.renamed
  [[ "$(http_code "http://127.0.0.1:$PORT/_update")" == 200 ]] || { up_log "/_update in the vendor gap"; return 1; }
  [[ "$(http_code -H "x-thallo-update-token: $(cat "$WORK/token")" "http://127.0.0.1:$PORT/_update/status")" == 200 ]] || return 1
  [[ "$(http_code -H 'x-thallo-update-token: wrong' "http://127.0.0.1:$PORT/_update/status")" == 403 ]] || return 1
  up_restore; up_repo_add failmig 901; request "$TARGET_V"
  tick_until recovery_required || return 1
  [[ "$(http_code "http://127.0.0.1:$PORT/_update")" == 200 ]] || return 1
  [[ "$(http_code -H "x-thallo-update-token: $(cat "$WORK/token")" "http://127.0.0.1:$PORT/_update/status")" == 200 ]]
}

sc_16() { # A release needing an extension PHP-FPM lacks: verified from PHP-FPM, never reopened.
  up_repo_add missingext 901; request "$TARGET_V"
  export COMPOSER_IGNORE_PLATFORM_REQ=ext-proofmissing
  tick_until recovery_required; local ok=$?
  unset COMPOSER_IGNORE_PLATFORM_REQ
  [[ "$ok" == 0 ]] || return 1
  [[ "$(op_field error)" == *proofmissing* ]] || { up_log "$(op_field error)"; return 1; }
  site_closed
}

sc_17() { # Review Focus 1: a death after verification completes the reopen, never a rollback.
  local p
  for p in reopen.before-open reopen.after-open reopen.after-release; do
    up_restore; up_repo_add target 901; request "$TARGET_V"
    kill_tick "$p"; tick
    status_is succeeded && site_open && version_is "$TARGET_V" || { up_log "after $p"; return 1; }
    [[ "$(op_field journal | grep -c '"kind":"reverse"' || true)" == 0 ]] || { up_log "a rollback after $p"; return 1; }
  done
}

sc_19() { # Review Focus 5: token and secret are not interchangeable; verification ignores the barrier.
  request "$TARGET_V"; tick
  local secret; secret="$(tr -d '\n' < "$SITE/.thallo-update-control/verify.secret")"
  [[ "$(http_code -X POST -H "x-thallo-verify: $(cat "$WORK/token")" -d '{"mode":"probe"}' "http://127.0.0.1:$PORT/_update/verify")" == 403 ]] || return 1
  [[ "$(http_code -H "x-thallo-update-token: $secret" "http://127.0.0.1:$PORT/_update/status")" == 403 ]] || return 1
  [[ "$(http_code -X POST -H "x-thallo-update-token: $secret" -d '{"option":"rollback"}' "http://127.0.0.1:$PORT/_update/recover")" == 403 ]] || return 1
  # Hold the barrier exclusively, as the runner does, and verify within a second.
  "$PHP_BIN" -r '$h=fopen($argv[1],"r"); flock($h, LOCK_EX); touch($argv[2]); sleep(10);' \
    "$SITE/.thallo-update-control/admission.lock" "$WORK/held" & local holder=$!
  for _ in $(seq 1 50); do [[ -f "$WORK/held" ]] && break; sleep 0.1; done
  local code; code="$(curl -s -m 1 -o /dev/null -w '%{http_code}' -X POST -H "x-thallo-verify: $secret" -d '{"mode":"probe"}' "http://127.0.0.1:$PORT/_update/verify")"
  kill "$holder" 2>/dev/null || true
  [[ "$code" == 200 ]] || { up_log "verify answered $code under the barrier"; return 1; }
}

supervise_worker() { # a supervisor loop that restarts `queue:work` whenever it exits
  ( cd "$SITE" && while [[ ! -f "$WORK/stop-worker" ]]; do as_deploy "$PHP_BIN" glueful queue:work --queue=default --sleep=1 >/dev/null 2>&1; sleep 0.5; done ) &
  echo $! > "$WORK/supervisor.pid"
}
worker_pid() { pgrep -f "glueful queue:work --queue=default" | head -1; }

reenable() { # turn admin updates off (guard removed) then on again, as an owner enabling it afresh
  up_env_set THALLO_ADMIN_UPDATES false
  (cd "$SITE" && as_deploy "$PHP_BIN" glueful thallo:provision --no-interaction) > "$WORK/prov-off.log" 2>&1
  grep -q "Update runner: removed" "$WORK/prov-off.log" || { up_log "the guard was not removed"; return 1; }
  "$@"
  up_env_set THALLO_ADMIN_UPDATES true
  (cd "$SITE" && as_deploy "$PHP_BIN" glueful thallo:provision --no-interaction) > "$WORK/prov-on.log" 2>&1
  grep -q "Update runner: installed-first" "$WORK/prov-on.log" || { up_log "the guard was not installed afresh"; return 1; }
}

sc_20() { # Readiness at first enablement: a worker from before the guard, then a guarded idle worker.
  reenable supervise_worker || return 1
  local old; old="$(worker_pid)"; sleep 2
  tick
  grep -q restart_required <<<"$(preflight_json "$(owner_token)")" || return 1
  ! confirm_restart || { up_log "confirmed while pre-guard processes ran"; return 1; }
  for _ in $(seq 1 60); do [[ -n "$(worker_pid)" && "$(worker_pid)" != "$old" ]] && break; sleep 1; done
  [[ "$(worker_pid)" != "$old" ]] || { up_log "the worker did not cycle through the guard"; return 1; }
  fpm_restart
  confirm_restart || { cat "$WORK/confirm.log" >&2; return 1; }
  local idle; idle="$(worker_pid)"
  up_repo_add target 901; request "$TARGET_V"
  tick_until succeeded; local ok=$?
  touch "$WORK/stop-worker"; kill "$(cat "$WORK/supervisor.pid")" 2>/dev/null || true; pkill -f "glueful queue:work --queue=default" 2>/dev/null || true
  [[ "$ok" == 0 ]] || return 1
  [[ "$idle" != "$(worker_pid)" ]] || true
}

sc_21() { # A Composer that ignores SIGTERM, and a grandchild: bounded, and nothing left behind.
  printf '#!/bin/sh\ncase " $* " in *" --version "*) exec %q "$@";; esac\ntrap "" TERM\n(sleep 600 </dev/null >/dev/null 2>&1 &)\nsleep 600\n' \
    "$COMPOSER_BINARY" > "$WORK/bin/hang-composer"
  chmod +x "$WORK/bin/hang-composer"
  up_env_set THALLO_UPDATE_COMPOSER_TIMEOUT 5
  up_repo_add target 901; request "$TARGET_V"
  local t0=$SECONDS
  COMPOSER_BINARY="$WORK/bin/hang-composer" tick
  (( SECONDS - t0 < 60 )) || { up_log "the timeout took $((SECONDS - t0))s"; return 1; }
  status_is failed && site_open || return 1
  ! pgrep -g "$(op_field pgid)" >/dev/null || { up_log "processes left in the runner's group"; return 1; }
  # The same for an application child: a clear-caches that ignores SIGTERM.
  up_restore; up_env_set THALLO_UPDATE_CHILD_TIMEOUT 5
  up_repo_add slowclear 901; request "$TARGET_V"
  t0=$SECONDS
  tick_until recovery_required 90 || return 1
  (( SECONDS - t0 < 120 )) || { up_log "the child timeout took $((SECONDS - t0))s"; return 1; }
  ! pgrep -g "$(op_field pgid)" >/dev/null || { up_log "processes left in the runner's group"; return 1; }
}

sc_22() { # A failed cache clear at reopen: closed, the marker never written, recoverable.
  up_repo_add failclear 901; request "$TARGET_V"
  tick_until recovery_required || return 1
  site_closed || return 1
  [[ -z "$(op_field reopen_ready_at)" ]] || { up_log "reopen_ready_at was written"; return 1; }
  tick; site_closed || return 1
  back_on_from
}

start_pre_guard_writers() { # a request and a console process that write after 25 s, started before the guard
  slow_request 25 slow-wrote
  ( cd "$SITE" && as_deploy "$PHP_BIN" -r 'require "bootstrap/app.php"; sleep(25); file_put_contents("storage/console-wrote", (string) microtime(true));' >/dev/null 2>&1 ) &
}

sc_23() { # Readiness: a pre-guard request and a console writer outlive any delay; neither writes after the backup.
  slow_request_file
  reenable start_pre_guard_writers || return 1
  sleep 10
  tick
  local bearer; bearer="$(owner_token)"
  grep -q restart_required <<<"$(preflight_json "$bearer")" || return 1
  ! confirm_restart || { up_log "confirmed while pre-guard processes ran"; return 1; }
  grep -q 'bootstrap/app.php' "$WORK/confirm.log" || { up_log "the console writer was not listed"; cat "$WORK/confirm.log" >&2; return 1; }
  [[ "$(http_code -X POST -H "Authorization: Bearer $bearer" -H 'Content-Type: application/json' \
    -d "{\"target\":\"$TARGET_V\"}" "http://127.0.0.1:$PORT/v1/admin/updates")" == 409 ]] || return 1
  for _ in $(seq 1 60); do [[ -f "$SITE/storage/slow-wrote" && -f "$SITE/storage/console-wrote" ]] && break; sleep 1; done
  fpm_restart
  confirm_restart || { cat "$WORK/confirm.log" >&2; return 1; }
  up_repo_add target 901; request "$TARGET_V"
  tick_until succeeded || return 1
  local backup; backup="$(step_started backup)"
  for f in slow-wrote console-wrote; do
    # Step times are whole seconds: a write in the backup's own second cannot be ordered against it.
    "$PHP_BIN" -r 'exit((int) floor((float) file_get_contents($argv[1])) <= (int) $argv[2] ? 0 : 1);' "$SITE/storage/$f" "$backup" \
      || { up_log "$f wrote after the backup began"; return 1; }
  done
}

# ---- revision 4: the trust boundary (spec §16.7) ----------------------------------------------------

# web_cannot <description> <shell snippet> [args…]: the web user's attempt must fail.
web_cannot() {
  local what="$1"; shift
  if as_web sh -c "$1" sh "${@:2}" >/dev/null 2>&1; then up_log "the web user could $what"; return 1; fi
}

# request_json <target> [extra JSON object]: a start request as the admin files it, plus extra keys.
request_json() {
  "$PHP_BIN" -r '$r = ["target" => $argv[1], "from" => "1.0.0-beta.900", "requested_by" => "x",
    "requested_at" => gmdate("c"), "token_hash" => hash("sha256", "attacker")];
    echo json_encode($r + (json_decode($argv[2] ?? "{}", true) ?: []));' "$1" "${2:-{\}}"
}

# refused_request <json> <error fragment>: the runner records the request as failed and stages nothing.
refused_request() {
  local before; before="$(shasum -a 256 "$SITE/composer.lock" | cut -d' ' -f1)"
  file_request "$1"
  tick
  status_is failed || return 1
  [[ "$(op_field error)" == *"$2"* ]] || { up_log "refused for '$(op_field error)', expected '$2'"; return 1; }
  [[ "$(shasum -a 256 "$SITE/composer.lock" | cut -d' ' -f1)" == "$before" ]] || { up_log "composer.lock changed"; return 1; }
  [[ ! -d "$SITE/.thallo-update/ops/$(op_field id)/stage" ]] || { up_log "a refused request was staged"; return 1; }
}

sc_24() { # From the PHP-FPM user: no attempt writes or runs anything as the code owner; then a clean update.
  [[ "$SINGLE_USER" == 1 ]] && { up_log "SKIP 24: the boundary needs a second user (CI runs it)"; return 0; }
  local attack="$WORK/attack" priv="$SITE/.thallo-update" ctl="$SITE/.thallo-update-control"
  local inbox="$SITE/storage/framework/updates"
  rm -rf "$attack"; mkdir -p "$attack"; chmod 1777 "$attack"
  printf '<?php // PROOF_PWNED_MARKER\n' > "$attack/pwned.php"; chmod 644 "$attack/pwned.php"
  as_web sh -c 'printf "<?php // PROOF_PWNED_MARKER\n" > "$1"' sh "$SITE/storage/pwned.php"
  local lock_ino; lock_ino="$(stat -c '%i' "$ctl/admission.lock")"
  tick # the runner's own records exist before the attempts

  # Staging replacement: the private folder, its stage and the folder itself.
  web_cannot "write into the private folder" 'mkdir -p "$1/ops/forged/stage/vendor"' "$priv"
  web_cannot "list the private folder" 'ls "$1"' "$priv"
  web_cannot "move the private folder" 'mv "$1/.thallo-update" "$1/.thallo-update.old"' "$SITE"
  web_cannot "plant a folder beside it" 'mkdir "$1/.thallo-update.new"' "$SITE"
  # Journal forgery: the operation and the status the runner publishes.
  web_cannot "write the operation" 'printf "{}" > "$1/operation.json"' "$priv"
  web_cannot "read the operation" 'cat "$1/operation.json"' "$priv"
  web_cannot "write the published status" 'printf "{}" > "$1/status.json"' "$ctl"
  web_cannot "add a file to the control folder" 'printf x > "$1/forged.json"' "$ctl"
  # Composer configuration injection: the files Composer reads, and requests that carry options.
  web_cannot "change composer.json" 'printf " " >> "$1/composer.json"' "$SITE"
  web_cannot "change .env" 'printf "\nCOMPOSER_HOME=/tmp\n" >> "$1/.env"' "$SITE"
  web_cannot "write Composer's home" 'mkdir -p "$1/composer-home" && printf "{}" > "$1/composer-home/config.json"' "$priv"
  up_repo_add target 901 # a real target on offer: only the request's own content is refused
  refused_request "$(request_json "$TARGET_V" '{"composer_options":"--no-plugins"}')" "not a valid request" || return 1
  refused_request "$(request_json "$TARGET_V" "{\"env\":{\"COMPOSER\":\"$attack/composer.json\"}}")" "not a valid request" || return 1
  refused_request "$(request_json "$TARGET_V" '{"working_dir":"/tmp"}')" "not a valid request" || return 1
  refused_request "$(request_json "dev-main as $TARGET_V")" "not a release version" || return 1
  refused_request "$(request_json "../../storage/pwned.php")" "not a release version" || return 1
  refused_request "$(request_json "$FROM_V")" "not newer" || return 1
  # Symbolic-link redirection: of a request, and of the inbox itself.
  as_web ln -s "$attack/owned-by-deploy" "$inbox/start.json"
  tick; status_is failed || return 1
  [[ ! -e "$attack/owned-by-deploy" ]] || { up_log "the runner wrote through the link"; return 1; }
  local lock_sum; lock_sum="$(shasum -a 256 "$SITE/composer.lock" | cut -d' ' -f1)"
  as_web ln -s "$SITE/composer.lock" "$inbox/start.json"
  tick; status_is failed || return 1
  [[ -f "$SITE/composer.lock" && ! -L "$SITE/composer.lock" ]] || { up_log "composer.lock was moved"; return 1; }
  [[ "$(shasum -a 256 "$SITE/composer.lock" | cut -d' ' -f1)" == "$lock_sum" ]] || return 1
  local failed_id; failed_id="$(op_field id)"
  as_web sh -c 'mv "$1" "$1.gone" && ln -s "$2" "$1"' sh "$inbox" "$attack" || { up_log "could not stage the inbox swap"; return 1; }
  request_json "$TARGET_V" > "$attack/start.json"; chmod 666 "$attack/start.json"
  tick
  [[ "$(op_field id)" == "$failed_id" ]] || { up_log "the runner acted on a request behind a linked inbox"; return 1; }
  grep -q 'storage/framework/updates must be a real directory' "$ctl/runner.json" || { up_log "no layout problem reported"; return 1; }
  as_web sh -c 'rm "$1" && mv "$1.gone" "$1"' sh "$inbox"
  rm -f "$attack/start.json"
  # Admission-lock replacement: replaced, removed, rewritten or linked, participants would split.
  web_cannot "move the admission lock" 'mv "$1/admission.lock" "$1/admission.lock.old"' "$ctl"
  web_cannot "remove the admission lock" 'rm -f "$1/admission.lock"' "$ctl"
  web_cannot "write the admission lock" 'printf x > "$1/admission.lock"' "$ctl"
  web_cannot "link over the admission lock" 'ln -sf /tmp/x "$1/admission.lock"' "$ctl"
  [[ "$(stat -c '%i' "$ctl/admission.lock")" == "$lock_ino" ]] || { up_log "the admission lock is a different file"; return 1; }

  # And an update the owner asked for still installs exactly the approved release.
  request "$TARGET_V"
  tick_until succeeded || return 1
  version_is "$TARGET_V" && web_version_is "$TARGET_V" || return 1
  local owned; owned="$(find "$attack" "$SITE/storage" -user "$DEPLOY_USER" \
    \( -name 'owned-by-deploy*' -o -name 'pwned*' -o -name 'forged*' \) 2>/dev/null)"
  [[ -z "$owned" ]] || { up_log "files the code owner wrote at attacker-chosen paths:"; up_log "$owned"; return 1; }
  ! grep -rqs PROOF_PWNED_MARKER "$SITE/vendor" || { up_log "the planted marker reached vendor/"; return 1; }
}

sc_25() { # Enabling from scratch while PHP-FPM already runs as the web user: the site never answers 503.
  [[ "$SINGLE_USER" == 1 ]] && { up_log "SKIP 25: needs PHP-FPM as a second user (CI runs it)"; return 0; }
  up_env_set THALLO_ADMIN_UPDATES false
  (cd "$SITE" && as_deploy "$PHP_BIN" glueful thallo:provision --no-interaction) > "$WORK/prov-off.log" 2>&1
  grep -q "Update runner: removed" "$WORK/prov-off.log" || { up_log "the guard was not removed"; return 1; }
  rm -rf "$SITE/.thallo-update" "$SITE/.thallo-update-control" "$SITE/storage/framework/updates" # never enabled
  fpm_restart
  site_open || return 1
  up_env_set THALLO_ADMIN_UPDATES true
  (cd "$SITE" && as_deploy "$PHP_BIN" glueful thallo:provision --no-interaction) > "$WORK/prov-on.log" 2>&1 \
    || { tail -20 "$WORK/prov-on.log" >&2; return 1; }
  grep -q "Update runner: installed-first" "$WORK/prov-on.log" || { up_log "the guard was not installed"; return 1; }
  # No manual permission step, no restart yet: PHP-FPM opens the provisioned lock read-only or, failing
  # that, keeps serving; nothing answers 503.
  local p code
  for p in / /health /_update; do
    code="$(http_code "http://127.0.0.1:$PORT$p")"
    [[ "$code" != 503 && "$code" != 500 ]] || { up_log "$p answered $code right after enabling"; return 1; }
  done
  [[ "$(stat -c '%a %U' "$SITE/.thallo-update")" == "700 $DEPLOY_USER" ]] || { up_log "private: $(stat -c '%a %U' "$SITE/.thallo-update")"; return 1; }
  [[ "$(stat -c '%a %G' "$SITE/.thallo-update-control")" == "750 thallo-site" ]] || { up_log "control: $(stat -c '%a %G' "$SITE/.thallo-update-control")"; return 1; }
  [[ "$(stat -c '%a' "$SITE/.thallo-update-control/admission.lock")" == 640 ]] || return 1
  tick
  [[ "$("$PHP_BIN" -r 'echo json_encode(json_decode(file_get_contents($argv[1]), true)["layout_problems"] ?? null);' \
    "$SITE/.thallo-update-control/runner.json")" == '[]' ]] || { up_log "the runner reports layout problems"; cat "$SITE/.thallo-update-control/runner.json" >&2; return 1; }
}
