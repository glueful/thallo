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
    $skip = "#^(vendor|composer\\.lock|composer\\.json|storage/logs|storage/framework/updates|storage/backups)(/|$)#";
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
    [[ -z "$(grep -v 'PHP-FPM cannot write the code' <<<"$failing")" ]] \
      || { up_log "$self"; return 1; }
    up_log "SKIP user separation (CI enforces it): PHP-FPM can write the code with one user"
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
      "http://127.0.0.1:$PORT/v1/admin/updates")"
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
  ! grep -rq "storage/framework/updates" "$SITE"/vendor/composer/autoload_*.php || { up_log "an autoload path points into the stage"; return 1; }
  [[ ! -d "$SITE/storage/framework/updates/$(op_field id)" ]] || { up_log "the stage was not deleted"; return 1; }
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
