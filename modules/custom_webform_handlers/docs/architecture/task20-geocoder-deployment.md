# Task 20D prerequisite — self-hosted geocoder deployment and dataset pin

Records the coordinate source Task 20D resolves committed addresses through, the
exact dataset it is pinned to, and the contract that binds the future OSRM
service to the same pin. Provisioned under explicit user authorization on
2026-09-05. This is not the Task 20D coordinate policy — that is
`task20-coordinate-policy.md`, written by 20D itself.

## Why this provenance

Task 20A's permission table (`task20-provider-policy.md`) records one row that
permits all three things a routing chain needs to do with a coordinate: persist
it, pass it to another provider, and render it on a map that is not the
provider's own — "self-hosted geocoder under this project's operation". Every
hosted alternative is either rights-restricted (Mapbox temporary geocoding,
Google) or billed per request, and the public Nominatim instance is excluded by
its own usage policy. The record's "selected provider: none yet" is now closed.

## The pin

| Property | Value |
| --- | --- |
| Extract | `nyc-metro-260904.osm.pbf` |
| Origin | cut locally with `osmium extract`, not downloaded |
| Bounding box | `-74.35, 40.45` to `-72.75, 41.20` |
| Strategy | `complete_ways` (`--set-bounds`) |
| Size | 269,504,387 bytes |
| MD5 | `77b0c1c4f92b37ccd0fb32881dc04b31` |
| Contents | 30,132,002 nodes, 4,591,527 ways, 50,054 relations |
| Newest object | `2026-09-04T20:20:41Z` |
| Written by | `osmium/1.16.0` |
| Image | `mediagis/nominatim:5.3.2-2026-06-23T09-03` |
| Image digest | `sha256:7923a8e67197fc6d4f4ecb7c0e8bbedffeddcfdf4519596fe946e46a28f5a9f8` |
| `data_version` | `nycmetro-20260904T202121Z+md5.77b0c1c4` |

The box covers New York City, Newark, Jersey City, Hoboken, southern
Westchester, southwestern Connecticut, Nassau County, and western and central
Long Island — the area this business actually dispatches into.

The parent it was cut from, verified before use and deleted afterwards:

| Property | Value |
| --- | --- |
| Parent | `us-northeast-260904.osm.pbf` |
| Source | `https://download.geofabrik.de/north-america/us-northeast-260904.osm.pbf` |
| Size | 1,796,226,812 bytes |
| MD5 | `5ec14912e375b58c5925894a42cd8e79`, checked against the publisher's own `.md5` — `OK` |
| `osmosis_replication_timestamp` | `2026-09-04T20:21:21Z` |
| Replication base | `https://download.geofabrik.de/north-america/us-northeast-updates` |
| Replication sequence | `4902` |
| Bounding box | `-80.522747, 38.742866` to `-66.871640, 47.462219` |

A **dated** Geofabrik parent, not `-latest`. The dated name is immutable, so the
same URL fetches the same bytes next month; `-latest` would silently become a
different road graph and dissolve the pin. The parent's checksum was verified
against the publisher's `.md5` before anything was cut from it, and the child
carries a locally generated `nyc-metro-260904.osm.pbf.md5` beside it — which
detects later corruption but is not independent provenance the way the parent's
published checksum was.

**The timestamp in `data_version` is inherited, not readable from the file.**
`osmium extract` does not propagate `osmosis_replication_*`; the child's header
Options carry only `generator`, `pbf_dense_nodes`, `pbf_optional_feature_0` and
`sorting`. So the snapshot instant above is the parent's, recorded here because
it cannot be read back out of the child. It is corroborated inside the child by
its newest object, `2026-09-04T20:20:41Z` — 40 seconds earlier, which is what a
faithful cut of that parent should look like. One consequence: a replication
catch-up against this extract has no start point in the file and would need an
explicit start date. With `UPDATE_MODE=none` nothing replicates, so that costs
nothing today.

`data_version` is built to survive `DistanceResult::normalizeToken()`, which caps
metadata at 64 characters and allows only `[A-Za-z0-9._+/-]`; this one is 38. It
carries scope, the snapshot instant, and the first 8 hex characters of the MD5 —
enough to detect dataset drift, and not a security control. The same string
appears in **four** places and must be changed in all four together: this table,
the `userAgent` in `config/optional/geocoder.geocoder_provider.ppu_nominatim.yml`
(and the matching active configuration), the pin comment at the top of
`.ddev/docker-compose.nominatim.yaml`, and — once OSRM exists — its own recorded
version.

Validate a freshly cut extract with `osmium fileinfo -e`, which reads all of the
data rather than trusting the header. Two of its numbers are worth reading
carefully:

```
Header:  Bounding boxes:  (-74.35,40.45,-72.75,41.2)
Data:    Bounding box:    (-74.7286563,40.0976424,-9.543467,49.873033)
```

The header box is the pin. The **data** box is far larger, and that is
`complete_ways` behaving correctly: a way that crosses the boundary is kept
whole, so its nodes outside the box come too — one long coastal or ferry way
reaches the mid-Atlantic. Anything that asserts "results fall inside the
extract" must assert against the **header** box; asserting against the data box
would pass almost anything, and reading the data box as an error would condemn a
correct extract.

## The OSRM contract

Geocoding and routing must share one road graph. An endpoint geocoded against one
dataset and routed against another can snap to a road the router does not have,
which produces a plausible distance for a route nobody can drive. The contract is
structural rather than procedural: the pinned extract lives in the external
Docker volume `ppu-osm-data`, mounted read-only at `/osm`, and the OSRM service
must mount that same volume and process **that same file**. Same PBF, same
checksum, same `data_version`.

OSRM must not cut its own extract, even from the same parent. A different box, or
`smart` instead of `complete_ways`, produces a different set of ways from
identical input — and boundary roads are exactly where the two disagree, which is
exactly where a delivery route leaves the city. The parent is no longer on this
host either, so re-cutting would mean re-downloading 1.7 GB and hoping the box
matched.

The volume is declared `external: true` so `ddev delete` cannot discard the
verified extract, and so neither service can be brought up against a file the
other has never seen. It holds 258 MB: the extract and its checksum sidecar,
nothing else.

## Deployment topology

`.ddev/docker-compose.nominatim.yaml` — hand-authored, carrying no
`#ddev-generated` marker, so DDEV treats it as project-owned and will not
rewrite it. The decisions in it that are not obvious from reading it:

- **`expose: 8080`, never `ports`.** The Nominatim HTTP API has no
  authentication of any kind. It is reachable as `http://nominatim:8080` from the
  DDEV network and is not published to the host.
- **`PBF_PATH`, not `PBF_URL`.** The image forbids both, and a URL would
  re-resolve on every rebuild.
- **`UPDATE_MODE: "none"`** with `REPLICATION_URL` configured but idle.
  Continuous updates would leave `data_version` asserting a snapshot the
  database no longer holds. The monthly refresh is a deliberate re-import
  against a new pin, not a drift.
- **`FREEZE: "false"`** — freezing saves disk but permanently disables updates.
- **Every environment value quoted.** YAML reads a bare `off` as a boolean and
  would hand PostgreSQL the string `false`.
- **PostgreSQL 16** in the volume path, confirmed by inspecting the image rather
  than assumed. A wrong major version imports successfully and then loses the
  database on the next container recreate.
- PostgreSQL memory settings are sized to this Docker host (~7.6 GB, 12 CPUs),
  not to the image documentation's planet-import examples.

## Provisioning procedure

Reproducible from a clean machine. Steps 3–5 are the long ones.

```bash
composer require geocoder-php/nominatim-provider
docker volume create ppu-osm-data
```

Download the pinned parent and its checksum outside the project directory — a
1.7 GB file inside it would be synced by Mutagen on every start — and verify it
against the publisher's own checksum before cutting anything from it:

```bash
curl -O https://download.geofabrik.de/north-america/us-northeast-260904.osm.pbf
curl -O https://download.geofabrik.de/north-america/us-northeast-260904.osm.pbf.md5
md5sum -c us-northeast-260904.osm.pbf.md5
```

Cut the NYC metro extract inside the volume. `osmium` is absent from the
Nominatim image and `osmium-tool` is not an Alpine package, so install it into a
throwaway container built from the image already on disk:

```bash
docker run --rm --entrypoint sh -v ppu-osm-data:/osm -v "$PWD":/src:ro \
  mediagis/nominatim:5.3.2-2026-06-23T09-03 -c '
    set -e
    apt-get update >/dev/null && apt-get install -y --no-install-recommends osmium-tool >/dev/null
    cp /src/us-northeast-260904.osm.pbf /osm/ && cd /osm
    md5sum -c /src/us-northeast-260904.osm.pbf.md5
    osmium extract --bbox=-74.35,40.45,-72.75,41.20 --strategy=complete_ways \
      --set-bounds --output-format=pbf --output=/osm/nyc-metro-260904.osm.pbf \
      --overwrite /osm/us-northeast-260904.osm.pbf
    rc=$?; echo "extract_rc=$rc"
    [ "$rc" -eq 0 ] && [ -s /osm/nyc-metro-260904.osm.pbf ] || { echo EXTRACT_FAILED; exit 1; }
    osmium fileinfo -e /osm/nyc-metro-260904.osm.pbf
    md5sum nyc-metro-260904.osm.pbf > nyc-metro-260904.osm.pbf.md5'
```

**Check the exit status explicitly and confirm the output file exists**, as above.
The first attempt at this wrote `time osmium extract …`; `sh` in this image is
dash, which has no `time` builtin and no `/usr/bin/time` beside it, so the
command never ran. `set -e` did not stop the script either, because the failing
command sat in a pipeline whose last element succeeded — the script printed its
own success banner and exited 0. It was caught only because it also listed
`/osm`, which showed no child file. An extract reported as verified but never
written is otherwise discovered by the import, an hour later.

Only once `osmium fileinfo -e` has been read and the header box matches the pin,
reclaim the parent's 1.7 GB:

```bash
docker run --rm --entrypoint sh -v ppu-osm-data:/osm \
  mediagis/nominatim:5.3.2-2026-06-23T09-03 -c \
  'rm -v /osm/us-northeast-260904.osm.pbf /osm/us-northeast-260904.osm.pbf.md5
   cd /osm && md5sum -c nyc-metro-260904.osm.pbf.md5'
```

Generate the container's database password so it never appears in a shell
history, an argument list, or this repository:

```bash
umask 077 && printf 'NOMINATIM_PASSWORD=%s\n' "$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)" > .ddev/.env.nominatim
```

`ddev start` then brings the service up and, on a first run against empty
volumes, runs the import unattended.

**Do not judge import progress from `docker logs`.** osm2pgsql writes its
progress line with carriage returns and no newline, so Docker's log driver only
flushes it once ~16 KB has accumulated — the tail sits minutes behind and repeats
an identical node count while the importer is working normally. The flatnode file
is no better: it is sized by the planet's node-ID space rather than by the
extract, so it is ~113 GB from the start — measured at 113,226,840,656 bytes and
fully allocated, not sparse — and it is mmap'd, so its mtime stops tracking
writes. Two signals are live. The process:

```bash
docker exec ddev-ppu-nominatim ps -o pid,etime,time,pcpu,comm -C osm2pgsql
```

And the database — but read it as a step function, not a curve. It plateaus for
long stretches, because during the node phase osm2pgsql writes nodes to the
flatnode file rather than to PostgreSQL. An unchanged figure two minutes apart is
normal and is not a stall:

```bash
docker exec ddev-ppu-nominatim su postgres -c \
  'psql -tA -d nominatim -c "SELECT pg_size_pretty(pg_database_size(current_database()))"'
```

The signal that actually says *where* the import is, is which tables exist.
osm2pgsql's own phase produces `place`, `place_interpolation`, `planet_osm_ways`
and `planet_osm_rels`; Nominatim's indexing phase afterwards is what creates
`placex`, `search_name` and `word`. So **`placex` absent means phase one of two**,
however long it has been running:

```bash
docker exec ddev-ppu-nominatim su postgres -c \
  'psql -tA -d nominatim -c "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY 1"'
```

Pair it with the live workers, where a `osm2pgsql.copy/*` entry means a COPY is
running right now:

```bash
docker exec ddev-ppu-nominatim su postgres -c \
  'psql -tA -d nominatim -c "SELECT application_name, state FROM pg_stat_activity WHERE backend_type = '"'"'client backend'"'"'"'
```

A third signal, `/status`, answers only after the import: the API's listener does
not exist until then, and a connection to 8080 is genuinely refused. Two traps
around measuring that. `wget` is **not installed** in this image, so a
`wget … || echo "refusing"` probe prints the refusal message for a missing binary
and cannot tell "no listener" from "no wget" — a false negative that reads exactly
like the true one. And MSYS rewrites a leading `/usr/bin/...` into a Windows path,
so an in-container interpreter needs `MSYS_NO_PATHCONV=1`:

```bash
MSYS_NO_PATHCONV=1 docker exec ddev-ppu-nominatim /usr/bin/python3 -c \
  "import socket; s=socket.socket(); s.settimeout(3); s.connect(('127.0.0.1',8080))"
```

Or probe from a container that does have curl, which also proves the DDEV-network
path the Drupal side will use:

```bash
ddev exec curl -s -o /dev/null -w '%{http_code}\n' --max-time 4 http://nominatim:8080/status
```

## The Drupal side

| Thing | Value |
| --- | --- |
| Composer packages | `geocoder-php/nominatim-provider` 5.8.0, `geocoder-php/common-http` 4.7.0 |
| Geocoder plugins discovered | 3 — `random`, `openstreetmap`, `nominatim` |
| Provider entity id | `ppu_nominatim` |
| Shipped as | `config/optional/geocoder.geocoder_provider.ppu_nominatim.yml` |
| Shipped file vs active config | identical including key order, once `uuid` and `_core` are dropped |
| Shipped file vs contrib schema | 0 violations from `config.typed` |

The schema check matters more than it looks: contrib declares the nominatim
mapping as exactly `rootUrl`, `userAgent`, `referer`, so a key it never declared
would install here and then fail on a site with strict schema checking. Validated
against `geocoder.geocoder_provider.*` rather than assumed.

The plugin manager filters out any definition whose annotated handler class is
absent, which is why the plugin list went from 1 to 3 on installing the package
rather than the plugin simply failing at runtime.

The entity ships in `config/optional/` rather than `config/install/` because
geocoder is deliberately not a declared dependency in the module's `info.yml`.
Core's `ConfigInstaller::installOptionalConfig()` filters optional config whose
entity type is not available, so on a site without geocoder the file is skipped
rather than written as an inert raw config object — the failure mode the
`block.block.hg_express_courier_home` comment in `info.yml` warns about for
`config/install/`.

`rootUrl` is overridden per environment from `settings.php`, not by editing the
shipped file:

```php
$config['geocoder.geocoder_provider.ppu_nominatim']['configuration']['rootUrl'] = 'http://nominatim:8080';
```

## Facts Task 20D must not assume

Read from contrib source, not from the graph — `modules/contrib/` is outside
Graphify's extraction scope.

1. **Contrib already caches, permanently and without versioning.**
   `geocoder.settings:cache` is `true` on this site, and `ProviderBase::setCache()`
   writes to the `cache.geocoder` bin with no expiry and no cache tags. Its cache
   id is `sha1(serialize($configuration) . serialize($data) . $locale)` — it does
   **not** include the dataset version. So a re-import against a new extract keeps
   serving the previous coordinates until that bin is cleared. 20D's layer-1 key
   must carry the geocoder version itself; it cannot delegate that to contrib.
2. **The same layer would persist a temporary-terms provider's results.** If a
   Mapbox or Google provider entity is ever added here, contrib caches it
   permanently by default. Rights compliance cannot rest on this layer.
3. **The throttle comes from configuration only.**
   `ConfigurableProviderUsingHandlerWithAdapterBase` passes
   `$this->configuration['throttle'] ?? []`, and `GeocoderThrottle` no-ops on an
   empty array. The plugin annotation's 2s/1req is a *form default* only, so an
   absent key means no throttle at all — which is why the shipped entity states
   1s/20req explicitly. The throttle is per-process (`Stiphle\Storage\Process`),
   so it bounds one PHP request, not the site.
4. **Handler arguments are positional, in annotation order** —
   `[httpAdapter, rootUrl, userAgent, referer]`. `userAgent` must be non-empty or
   `Geocoder\Provider\Nominatim\Nominatim` throws `InvalidArgument`.
5. **The `geocoder` service erases the reason for a failure.**
   `Geocoder::geocode()` wraps its whole provider loop in
   `catch (\Exception $e) { $this->getLogger('geocoder')->warning(...); }` and
   returns `NULL` after the loop. An address outside the extract, an address that
   does not exist, a refused connection to `nominatim:8080`, and a provider entity
   that failed to load all arrive at the caller as the same `NULL`. The
   distinction survives only in `watchdog`, which is not control flow. This is
   load-bearing for 20D: the coverage decision below requires classifying an
   out-of-region address as retryable `OUTSIDE_COVERAGE` and an unusable one as
   terminal `ADDRESS_UNUSABLE`, and the contrib service cannot tell 20D which it
   had. Calling `$provider->getPlugin()->geocode()` directly keeps the vendor
   exception type visible; going through the service does not.
6. **A state token in the query string can zero an otherwise resolvable address.**
   Nominatim matches every token in a free-form query; a token that matches nothing
   in the dataset returns no result rather than being ignored. This dataset has no
   US state boundary at all — `admin_level=4` rows: **0**, and no boundary named
   `New Jersey` — because the bbox cut kept no complete state polygon. So `NJ`
   resolves only through the individual rows that happen to carry an `addr:state`
   tag, and 957,673 of 2,369,173 rank-30 rows (40.4%) carry none.

   | | row has `addr:state` | row lacks `addr:state` |
   | --- | --- | --- |
   | query **with** state token | resolves | **returns nothing** |
   | query **without** state token | resolves | resolves |

   Measured on a real address: `280 Grove Street, Jersey City, NJ 07302` returns
   nothing, the same string without `NJ` resolves, and structured search behaves
   identically (`state=NJ` → 0 results, state omitted → 1). The control for "this
   is token matching, not a coverage hole" is that a nonsense token (`ZZQQXX`)
   fails the same way. New York escapes only by accident: an `admin_level=5`
   boundary named `New York` survived the cut.

   Scale is small but not negligible — 1,043 of 64,381 New Jersey rows (1.6%), 60
   of 1,517 in Jersey City. This is a **query-construction constraint for 20D**,
   not a deployment fault: build the query from street, city and postcode and omit
   the state, or fall back to a state-free retry. Do not "fix" it by widening the
   extract; a wider cut was already rejected on import time, and the same class of
   edge would simply move outward. `.claude/tmp/geocoder-live-verify.php` asserted
   this behaviour (`unmatched_state_token_zeroes_result=CONFIRMED`) so that a
   future dataset in which it stops holding shows up as a failure and this item
   can be retired.

## Why a local cut, and not a published extract

Geofabrik's New York State extract is 473 MB against the region's 1.7 GB, and it
was rejected. A Jersey City or Stamford address is a normal delivery for this
operation, and a NY-only graph cannot resolve one. That failure does not degrade
gracefully: an unresolvable address classifies as `ADDRESS_UNUSABLE`, and
`DistanceProviderException::isTerminal()` makes that classification stop the whole
provider chain rather than fall through to the next provider — so a deliverable
New Jersey address would refuse the entire quote, and it would refuse it
identically no matter how many providers were configured behind it.

The whole US Northeast resolves those addresses and was tried first. It did not
finish on this host: 1 h 41 m of wall clock at a sustained 91 % of eight threads,
with `placex` still not created — phase one of two, and no estimate obtainable for
the rest. That import was terminated deliberately. Time was the binding
constraint, not memory: see the page-cache correction under
[Measured on first import](#measured-on-first-import), which retires the peak-RAM
figure this argument originally cited.

So the published options were either too narrow to serve the business or too
broad to import here, and the box was cut to the dispatch area instead. It is
269 MB, 15 % of the parent, and it covers every city the two rejected options
disagreed about.

`complete_ways` was chosen over `smart` because it maps directly onto the
requirement that a road way crossing the boundary stays whole — a truncated way
is a road the router can enter and not leave. The cost is that boundary
*relations* are not completed, so the address hierarchy for a place straddling
the edge can be thinner than the same place in a published extract. If hierarchy
quality proves insufficient in practice, re-cut with `--strategy=smart` from the
same parent and box; nothing else in this record changes.

Coverage is finite by design. Addresses outside the box must classify as
`OUTSIDE_COVERAGE`, which is retryable on another provider, and never as
`ADDRESS_UNUSABLE`.

**The coverage boundary moved with this pin, and one earlier claim in this record
is now void.** Under the us-northeast pin the header box reached south to 38.74 N
and west to 80.52 W, so Washington DC was inside it and a DC address resolved.
Under the NYC metro box DC is 2.7 degrees outside, so it must not resolve at all.
DC is therefore no longer an informational probe — it is a second negative
control, and a nearer one than Los Angeles, which makes it the more useful of the
two.

## Monthly refresh

A refresh is a deliberate re-import against a new pin, never a replication
catch-up. In order:

1. Download the new dated parent and verify its published `.md5` on the host.
2. Cut the new child with the **same box and the same strategy** as the pin table,
   and validate it with `osmium fileinfo -e` before going further. Both children
   stay in `ppu-osm-data` for now; the new parent can be removed as soon as the
   new child is verified.
3. Update `PBF_PATH` in `.ddev/docker-compose.nominatim.yaml`.
4. Recompute `data_version` — the new parent's `osmosis_replication_timestamp`
   plus the new child's MD5 — and change it in all four places named above,
   including the active configuration, not only the exported file.
5. Remove the `nominatim-db` and `nominatim-flatnode` volumes so the entrypoint
   takes the import path rather than the start path, and bring the service up.
6. Clear the geocoder result cache, or the previous dataset's coordinates keep
   being served from a bin whose keys do not mention the dataset:

```bash
ddev exec vendor/bin/drush cache:clear bin geocoder
```

7. Leave the superseded child in the volume until OSRM has also been rebuilt
   against the new one. Deleting it first breaks the shared-graph contract in the
   window between the two rebuilds.

## Credentials

Nominatim's HTTP API takes none, which is the point of this provenance. One
secret exists — `NOMINATIM_PASSWORD`, the container's own PostgreSQL password —
and it lives in `.ddev/.env.nominatim`, git-ignored at `.gitignore:218`. The rule
is in the root `.gitignore` rather than `.ddev/.gitignore` because DDEV rewrites
the latter on every start and would drop it. It is generated per machine, not
shared, and the service definition is versioned deliberately so that only the
secret is not.

One secret, **two** roles. `init.sh:90-91` applies the same value to `nominatim`
(a superuser) and to `www-data`, so anything that rotates one and not the other
leaves the old value live. `SELECT count(*) FROM pg_authid WHERE rolpassword IS
NOT NULL` is the check: it must be 2, and both verifiers must move together.

On this host the file's protection is **NTFS ACLs on the user profile plus the
ignore rule**, not POSIX mode bits. `umask 077` was used when writing it, but
`stat -c %a` reports `644` because MSYS over NTFS does not map POSIX modes, and a
`chmod` here would change the reported number without changing any access
decision. Do not claim mode-bit protection on Windows; on a POSIX host, verify
`600` and expect the umask to have held.

**Do not read this container's logs unfiltered.** The image's entrypoint runs
under `set -x`, so it traces its own `ALTER USER ... WITH ENCRYPTED PASSWORD`
statements and the generated value appears in `docker logs` output. Filter every
read:

```bash
docker logs ddev-ppu-nominatim 2>&1 | grep -v -i password | tail -20
```

This was learned by tripping it during the 2026-09-05 provisioning: the value was
echoed into a terminal, so it was treated as exposed and rotated the same day,
mid-import.

### The rotation, and why it did not interrupt the import

Rotating during a running import is safe here, and that was established before
acting rather than hoped for. Every in-container client connects over the unix
socket under `local all all peer` — `pg_stat_activity` showed six backends, all
with a NULL `client_addr` — and `/nominatim/.env` carries no credential. Nothing
that was running presents a password, so replacing the verifier cannot break a
live connection or the reconnects osm2pgsql makes between phases.

The flow, which is the one to reuse:

1. `set +x` and `set +o history`, so neither value reaches a trace or a history
   file.
2. Generate into the ignored env file with `printf` — a shell builtin, so the
   value never appears in an argument list — under `umask 077`.
3. Apply it with `ALTER USER` piped to `psql -f -` over **stdin**. Never `-c`:
   that puts the secret in argv, where `ps` can read it. `log_statement=none` and
   `log_min_duration_statement=-1` were confirmed first, so the statement is not
   logged either.
4. Verify over loopback TCP, which is `scram-sha-256`: the new value must be
   accepted and a random wrong value must be rejected. The wrong-value control is
   what proves the path actually authenticates rather than trusting.

Result on 2026-09-06, recorded per check rather than as a verdict:

| Check | `nominatim` | `www-data` |
| --- | --- | --- |
| Role altered | PASS | PASS |
| New credential accepted over loopback | verified | verified |
| Random wrong value rejected | verified | verified |
| Old credential rejected | verified, tested directly | **not directly tested** |
| Verifier fingerprint moved | yes | yes |

Rotation is complete for both roles. For `www-data` the old value was deliberately
not retained in shell scope, so its rejection was not tested directly; the role now
holds a freshly generated verifier, and a SCRAM verifier is salted and one-way, so
the previous secret cannot validate against it. The exposed value must not be
recovered, searched for, or reproduced to close that gap — a direct negative test
is not worth re-handling a compromised credential for, and the verifier
replacement is the stronger evidence anyway.

The fingerprints in that table are `substr(md5(rolpassword),1,8)` over the stored
verifier. They differ between the two roles even though both were set to the same
value, because each `ALTER USER` generates a fresh salt — which also means the
fingerprint is not a password oracle and is safe to record.

`start.sh` does **not** re-apply the credential; only `init.sh` does. So a
restart or a container recreate keeps the rotated value, and only a fresh import
re-applies from the env file — which now holds the new value, so the two stay
consistent either way. `start.sh:29` does pass `$NOMINATIM_PASSWORD` to
`useradd -p`, but that is the container's own OS account, `-p` expects a crypt
hash rather than plaintext, and no `sshd` runs here.

### What the exposure actually was

Bounded, but wider than "internal to the container". Measured, not assumed:
5432 is **not** published to the host — `docker inspect` shows `5432/tcp->[]`,
the empty binding that `expose` without `ports` produces — but it **is** reachable
from other containers on the DDEV project network, confirmed by opening a socket
to `nominatim:5432` from `ddev-ppu-web`. With `listen_addresses = '*'` and a
`host all all 0.0.0.0/0 md5` rule in `pg_hba.conf`, any container on that network
could attempt password authentication as any role, including the superuser. That
is the exposure the hardening below closes, and it is why the rotation was not
optional.

## PostgreSQL hardening

Closes the exposure described above. The first deployment was hardened after the
fact; this one never ran unhardened. The mounts and the boot gate were in place
before the container that runs the NYC metro import was created, so PostgreSQL
has never started here with the image's own authentication rules. No ephemeral
in-container edit was made at any point — the section explains why the obvious
edit is the wrong one, and why the obvious *durable* edit is also wrong.

Measured from the first deployment's running container **before** hardening:

| Property | Value |
| --- | --- |
| `hba_file` | `/etc/postgresql/16/main/pg_hba.conf` — **image layer, not a volume** |
| `listen_addresses` | `*` |
| `password_encryption` | `scram-sha-256` |
| 5432 published to host | no (`5432/tcp->[]`) |
| 5432 reachable from `ddev-ppu-web` | **yes** |
| The broad rule | `host all all 0.0.0.0/0 md5`, last line of `pg_hba.conf` |
| Roles holding a verifier | 2 — `nominatim` (superuser), `www-data` |
| In-container clients using TCP | none — all six backends had a NULL `client_addr` |

### What the image supports, read from its own scripts

- **`postgresql.conf` settings: env-var driven, but not overridable by a drop-in.**
  `config.sh:35-44` `sed -i`s each `POSTGRES_*` value into
  `/etc/postgresql/16/main/conf.d/postgres-tuning.conf`, and `postgresql.conf:817`
  carries `include_dir = 'conf.d'`. That include is **not** the end of the file:
  line 828 — the last line — is `listen_addresses='*'`, appended by the image
  *after* its own include. PostgreSQL takes the last assignment it reads, so a
  `conf.d` drop-in overrides everything before line 817 and loses to anything the
  image appends after it. Within `conf.d`, files are still read in filename order.
- **The import toggle uses the same directory.** `init.sh:84` copies
  `conf.d/postgres-import.conf.disabled` to `postgres-import.conf`; `init.sh:147`
  deletes it.
- **`pg_hba.conf` and the listener: no hook at all.** Grepped across `/app`, not
  assumed: no image script mentions `postgresql.conf`, `pg_hba`, `hba_file` or
  `listen_addresses`. There is no environment variable for authentication rules and
  no override mechanism for either file, which is why the broad rule cannot be
  narrowed through configuration the image accepts, and why the listener cannot be
  narrowed by a drop-in.

Both `pg_hba.conf` and `conf.d/` live in the image layer, so editing either in
place is ephemeral — silently reverted by the next container recreate, which is
exactly when a hardening regression would go unnoticed. `ALTER SYSTEM` is durable
(it writes `postgresql.auto.conf` into the `nominatim-db` volume, which already
exists there) and it would beat line 828, but it is invisible to version control,
outranks every file, and is lost with the volume it lives in, so it is the wrong
home for a rule that should be reviewable in a diff. Command-line options would
also win; the image does not pass any (`init.sh:86` and `start.sh:41` both call
`service postgresql start` with no `-c`).

### What was changed

Three project-owned files under `.ddev/nominatim/`, bind-mounted read-only over the
image's paths, so the rules are versioned, reviewable in a diff, and survive a
container recreate. `.ddev/nominatim/` follows the local convention set by
`.ddev/redis/` — a directory named after the service. Deliberately **not**
`.ddev/postgres/`, which DDEV reserves for the *project's own* database container
and would manage.

One caveat on "versioned": at the time of writing `.ddev/nominatim/` and
`.ddev/docker-compose.nominatim.yaml` are **untracked**, because the authorization
for this work forbade committing. Surviving a container recreate is already true;
surviving a fresh clone is not, until those paths are committed. That commit is
owed, and `.ddev/.env.nominatim` must stay out of it — it is ignored at
`.gitignore:218`, verified with `git check-ignore`, and holds the only secret here.

**1. `.ddev/nominatim/pg_hba.conf`** replaces the image's file. Every line is
retained from the image's own copy except the last one. Keep `local … peer` — the
importer and the API both connect over the Unix socket and present no password,
which is what made a mid-import credential rotation safe; removing peer breaks the
service. Keep loopback SCRAM, because that is what makes a credential test
possible at all. The replication lines are loopback-only and cost nothing.

```
local   all             postgres                                peer
local   all             all                                     peer
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256
local   replication     all                                     peer
host    replication     all             127.0.0.1/32            scram-sha-256
host    replication     all             ::1/128                 scram-sha-256
```

**2. `.ddev/nominatim/zz-ppu-hardening.conf`** narrows the listener, so a future
regression in those rules has nothing to listen on:

```
listen_addresses = 'localhost'
```

`'localhost'` rather than `''` so loopback SCRAM keeps working — it is how the
database credential is verified.

**On its own, in this image, this file does nothing.** Line 828 of
`postgresql.conf` reassigns `'*'` after the `conf.d` include, and the later
assignment wins. It is mounted anyway and it stays: it is the declarative
statement of intent, and if a future image tag stops pinning `listen_addresses`
the drop-in becomes the effective setting and already says `localhost`. What
enforces it today is file 3.

**3. `.ddev/nominatim/pg-hardening-entrypoint.sh`** is the container's `command:`.
It runs before the image's own `/app/start.sh`, which it `exec`s unchanged at the
end, and it does two things:

- **Rewrites the one line that wins.** A `sed -i` over `postgresql.conf` replaces
  whatever `listen_addresses` assignment the image shipped with
  `listen_addresses = 'localhost'`, then re-reads the file and fails if any
  non-comment `listen_addresses` line is left non-loopback. Applied on every start,
  so a container recreate — or a deleted database volume — cannot quietly undo it.
- **Asserts the mounts landed, before any postmaster starts.** All three targets
  must be regular files, `postgres-tuning.conf` must still be visible in `conf.d`,
  no `0.0.0.0/0` rule may be active, and both the `local … peer` and the
  `127.0.0.1/32 … scram-sha-256` rules must be present. Every rule check strips
  comments and blank lines first. Any failure prints `ppu-hardening: FATAL:` and
  exits non-zero, so the service never listens.

Mounted in `.ddev/docker-compose.nominatim.yaml`:

```yaml
      - "./nominatim/pg_hba.conf:/etc/postgresql/16/main/pg_hba.conf:ro"
      - "./nominatim/zz-ppu-hardening.conf:/etc/postgresql/16/main/conf.d/zz-ppu-hardening.conf:ro"
      - "./nominatim/pg-hardening-entrypoint.sh:/app/ppu-pg-hardening-entrypoint.sh:ro"
    command: ["sh", "/app/ppu-pg-hardening-entrypoint.sh"]
```

`sh <path>`, not the bare path: a bind mount from NTFS carries no reliable execute
bit. The image declares no `ENTRYPOINT` and `/app/start.sh` as its `CMD`, so
overriding `command:` wraps the image's boot rather than replacing it.

Five traps, each of which fails quietly or catastrophically:

- **Mount the files, never the `conf.d` directory.** A directory mount hides
  `postgres-tuning.conf` and the import toggle, discarding every `POSTGRES_*`
  setting in `.ddev/docker-compose.nominatim.yaml` with no error.
- **The source files must exist before the container is recreated.** Docker
  creates a *directory* at a bind-mount source that is missing, and PostgreSQL
  will not start with a directory where its hba file should be.
- **A mistyped mount _target_ is worse: it does not fail.** Docker creates a
  directory beside the real path, PostgreSQL reads the image's own `pg_hba.conf`,
  and the container looks healthy while `host all all 0.0.0.0/0 md5` is active
  again. This is the failure the shim's assertions exist to catch, and why they
  are fatal rather than warnings — no listener is better than an open one.
- **LF line endings only.** Authored on Windows, and a trailing CR joins the last
  token: `scram-sha-256\r` is not an authentication method, and the postmaster
  refuses to start rather than starting insecurely. Verified with
  `tr -cd '\r' | wc -c` returning 0, not assumed from the editor. The shim is a
  shell script, so a CR there breaks the boot outright.
- **`listen_addresses` needs a postmaster restart**, not a reload. That is why it
  is set from the shim before `/app/start.sh` runs, rather than applied to a
  running instance: there is no window in which the listener is wrong, and no
  restart is owed to a mid-import database.

The `zz-` prefix is load-bearing: `conf.d` files are read in filename order and
`postgres-tuning.conf` must be overridden, not override.

One verification trap worth naming, because it is the same shape as several
already recorded here: `grep -c '0\.0\.0\.0/0' pg_hba.conf` returns **1** on the
hardened file. The match is this file's own comment explaining what was removed.
Any check for the broad rule must filter comments first —
`grep -v -E '^[[:space:]]*#|^[[:space:]]*$'` — or it reports a rule that is not
active. That file-level check is only the boot gate. The *authoritative* check is
`pg_hba_file_rules`, which reports the rules the postmaster actually parsed and
loaded plus an `error` column for lines it rejected: a file can be correct and
still not be the file that was loaded.

**4. Then consider dropping `www-data`'s password entirely.** Nothing outside the
container consumes it and every observed connection is peer, so
`ALTER USER "www-data" PASSWORD NULL` would remove a credential rather than
rotate it. Confirm first that the API — which is not serving during an import —
also connects over the socket; `init.sh:91` will re-set the password on any future
re-import regardless, so this is a per-deployment cleanup, not a permanent state.

Removing the `md5` line is worth stating precisely rather than dramatically: with
`password_encryption = scram-sha-256`, an `md5` hba line still negotiates SCRAM
when the stored verifier is SCRAM, so the rule's weakness was never its algorithm.
It was its breadth — any role, any database, any address.

### Verified on the fresh deployment, 2026-09-06

Read from the running container's loaded state, not from the files on disk. The
shim's own boot line — `ppu-hardening: pg_hba mounts verified, listen_addresses
forced to loopback` — is the first evidence, but it only proves the gate ran.

| Check | Source of truth | Result |
| --- | --- | --- |
| Rules loaded | `pg_hba_file_rules` | 7 |
| Lines the parser rejected | `pg_hba_file_rules.error` | 0 |
| Broad `0.0.0.0/0` rules | parsed view, comment-blind by construction | **0** |
| `local … peer` rules | parsed view | 3 |
| Loopback SCRAM rules | parsed view | 4 |
| `listen_addresses` | `pg_settings` | `localhost` — source `configuration file`, `postgresql.conf` line **828** |
| Published ports | `docker inspect` | `{"5432/tcp":null,"8080/tcp":null}` |
| 5432 from `ddev-ppu-web` | TCP probe | `NO_CONNECTION` |
| 8080 from `ddev-ppu-web` | TCP probe | `NO_CONNECTION` (HTTP is not served during an import) |
| Probe harness works | TCP probe to `db:3306` | `OPEN` — positive control |
| Name resolves | DNS lookup of `nominatim` | `172.19.0.6` |
| `www-data` over loopback SCRAM | `psql` | ACCEPTED |
| Wrong value over the same path | `psql` | REJECTED — negative control |
| Roles holding a verifier | `pg_authid` | 2 — `nominatim`, `www-data` |

Three of those rows carry more weight than they look like they do.

`listen_addresses` reporting **line 828** is the proof that the shim, not the
drop-in, is what holds. The effective value comes from the very line the image
appended to defeat `conf.d`, because that line has been rewritten in place. Had
the value been attributed to `conf.d/zz-ppu-hardening.conf`, the drop-in would
have been sufficient and the shim unnecessary; had it read `*`, the hardening
would have shipped as done while the listener stayed open on the DDEV network.
That is exactly what the earlier version of this document claimed had happened.

A refused TCP connection proves nothing on its own — an unreachable name, a
container that is not running, or a probe tool that never worked all look
identical to a closed port. So the probe was run from inside `ddev-ppu-web`
against `db:3306` in the same shape and returned `OPEN`, and `nominatim` was shown
to resolve to a routable address first. The negative is only meaningful with both.

The same logic applies to the credential row: ACCEPTED on its own would be
consistent with a path that accepts anything, so a deliberately wrong value was
sent over the identical loopback SCRAM path and was REJECTED. This re-confirms the
rotation on the fresh deployment; it does not change what was recorded above about
the old value, which was never directly tested and was not searched for.

### One thing never to do while verifying this

`ddev debug compose-config` renders the whole project's Compose configuration —
and because `env_file` values are resolved into that render, **the output contains
the live `NOMINATIM_PASSWORD` in clear text.** It was needed here (it is the only
way to confirm that DDEV composed the three mounts and the `command:` override as
intended, rather than trusting the source file), and it was handled accordingly:
written only to the system temp directory outside the repository, inspected with
filtered greps that print mount and command lines only, then deleted.

That the render carries the secret is not an assumption. It was established by
boolean containment — asking whether the file contains the value, never printing
it — with a positive control proving the test could detect a value it was given
(`positive_control_hits=2`) beside the real check (`secret_occurrences=1`).

Never redirect that command into the repository, never paste its output into a
report, and never let it reach a log that is kept. `git status` would not have
saved this: `.ddev/.ddev-docker-compose-full.yaml` is DDEV-generated and ignored,
so a credential written there is invisible to review and still on disk.

## Licence obligation, still open

The extract is OpenStreetMap data under ODbL 1.0. Self-hosting removes the
per-request billing and the persistence restrictions that ruled out every other
provider; it does not remove the licence. Two obligations follow, and neither is
discharged by this task:

- **Attribution.** Output derived from OSM data that is shown to a user carries
  an attribution requirement — "© OpenStreetMap contributors" or equivalent.
  Nothing in this session displays OSM-derived output: the geocoder is
  provisioned and no coordinate reaches a browser. The obligation becomes live
  when OSRM distances or geometry are presented, so the placement decision
  belongs to the rollout task, not here.
- **Share-alike.** It applies to publishing a derivative *database*, not to
  serving results from one. A coordinate cache internal to this site is not a
  publication; exporting one would need this question reopened.

## Provisioning stop boundary (historical)

The authorization for the provisioning pass stopped at the prerequisite. The
following statements describe that hand-off point; Task 20D later closed the
configuration, resolver/cache and policy-document items. Provider adapters and
the live distance-provider alias remain outside this record.

- `routing.geocoder.provider`, `.version`, `.persist` and
  `.request_allowance` were still empty/`false`/`0` in active configuration.
- `AddressCoordinateResolver`, `ResolvedLocation` and `CoordinateCache` did not
  exist yet.
- No coordinate service wiring, provider alias or routing adapter was changed.
- `task20-coordinate-policy.md` had not yet been created.

## Measured on first import

Recorded rather than estimated, because the image documentation scopes every
figure larger than its 2 GB floor to a full planet import and publishes nothing
for a regional extract. Two imports were measured: the us-northeast attempt that
was abandoned — the reason the localized cut exists — and the NYC metro import
this deployment actually runs.

### The abandoned us-northeast attempt

| Measurement | Value |
| --- | --- |
| Started | `2026-09-05T23:30:39Z` (container `StartedAt`, not a log line) |
| Stopped | after 1 h 41 m 42 s elapsed — SIGTERM, exit code 143 |
| `osm2pgsql` CPU time | 1 h 32 m 17 s, i.e. 90.7% of elapsed |
| Peak node rate | ~162k nodes/s at 8 threads |
| `nominatim` database when stopped | 1242 MB across 15 tables |
| `placex` | never created |

It was stopped, not failed, and the distinction is the whole argument for the
pivot. `placex` is the phase-two indicator: it does not exist until osm2pgsql has
finished loading raw OSM data and Nominatim begins building the searchable place
table. After 1 h 41 m on a 1.80 GB source the import was still in phase one, with
no way to bound the remaining time from inside the container. Requirement 4 of the
pivot authorized discarding exactly this.

### The NYC metro import

| Measurement | Value |
| --- | --- |
| Volumes created | `2026-09-06T01:38:15Z` — fresh `nominatim-db` and `nominatim-flatnode` |
| Import start | `2026-09-06T01:38:18.843Z` (container `StartedAt`) |
| Importer | `osm2pgsql` PID 115, reading `nyc-metro-260904.osm.pbf` |
| At 48 m 38 s | 87.3% CPU, RSS 168 MB, database 250 MB, 11 tables, `placex` absent |
| At 1 h 03 m 29 s | 81.1% CPU (51 m 32 s CPU time), RSS 280 MB, database 893 MB, 11 tables, `placex` absent |
| At 1 h 15 m 26 s | 77.5% CPU (58 m 28 s CPU time), database 1669 MB, 11 tables, `placex` absent |
| Import finished | `2026-09-06T03:59:45Z` — mtime of the `import-finished` sentinel, written by the image itself |
| API answering | `2026-09-06T04:02:11Z` — `/status` 200 from `ddev-ppu-web`, i.e. over the network Drupal uses |
| Wall-clock duration | 2 h 21 m 30 s (sentinel mtime minus volume creation) |
| Final shape | 686 tables, `placex` + `search_name` + `word` all present, database 5,863 MB |
| `nominatim-db` volume size after import | 7,245,302,195 bytes — 6.75 GiB |
| First live geocode verified | `350 5th Ave, New York, NY 10118` → 40.748442, -73.985659 through the `ppu_nominatim` plugin |

The source is 269.5 MB against the parent's 1796.2 MB — 15.0%, a 6.7× reduction.
Resist reading the database column as progress against the abandoned run, though:
at 1 h 15 m this import's database is 1669 MB, already larger than the parent run's
1242 MB at 1 h 41 m. Database size is a step function of which phase osm2pgsql is
in, not a fraction of the work remaining, so the two runs cannot be compared at
arbitrary points. `placex` is the only phase indicator that means anything, and it
is still absent in both figures.

### Two figures that mislead

**Disk is not sized by the extract.** `flatnode.file` is 113,226,840,656 bytes —
105 GiB — and it is fully allocated rather than sparse: `stat` reports 221,146,208
512-byte blocks, which is the apparent size. osm2pgsql sizes that index by the
*largest node ID* in the input, not by the node count. This extract's largest node
ID is 14,152,317,502, and 14,152,317,502 × 8 bytes is 113,218,540,016. Node IDs are
global, so localizing the extract reduced this file by nothing at all: a regional
cut buys import time and database size, not flatnode disk. Sizing this deployment
from the 269.5 MB source would be wrong by more than two orders of magnitude.
Measured headroom on the Docker VM filesystem at that point: 831 GB available.

**Container memory is mostly page cache.** cgroup v2 `memory.peak` read
6,494,818,304 bytes, which rounds to the same 6.05 GiB the abandoned run reported —
and that coincidence is the tell. `memory.stat` at the same moment: `file`
5,799,841,792 against `anon` 103,522,304. Roughly 93% of the figure is page cache
from writing the 105 GiB index, and the importer's own working set is the 168 MB
`ps` reports as RSS. Use RSS, or `memory.stat`'s `anon`, for a working set; treat
`memory.peak` here as an I/O signal.

The second of those retires one argument that was made for the pivot: the
us-northeast run was not running out of memory. What remains — an elapsed hour and
forty minutes still inside phase one, with no bound obtainable on the rest — is
the argument that actually holds, and it is unaffected.

## The flatnode file

`flatnode.file` is 105 GiB, which is more than twenty times everything else this
deployment stores, so whether it is steady-state storage or a temporary import
artifact decides the disk budget. It is an import artifact. Three independent
sources agree, and none of them is inference from the file's name.

**The query package never reads it.** Grepping the installed `nominatim_api`
package — the one Gunicorn serves on `:8080`, entry point
`nominatim_api.server.falcon.server:run_wsgi` — finds `flatnode` in exactly one
file, `resources/settings/env.defaults`, which is the settings template shared by
both packages. No `.py` and no `.pyc` under `nominatim_api` refers to it. Every
code reference lives in `nominatim_db`: `tools/database_import.py`,
`tools/exec_utils.py`, `clicmd/args.py`, and both halves of `freeze`.

**Upstream classifies it as update data, and upstream's own command deletes
it.** `nominatim freeze` is documented as removing "data required for dynamic
updates from the Nominatim database after the initial import if you do not plan
to apply updates" — and `nominatim_db/clicmd/freeze.py:36` calls
`freeze.drop_flatnode_file(...)`, which unlinks the file
(`nominatim_db/tools/freeze.py:49-53`). The vendor's own never-updating command
treats it as disposable. A second vendor path agrees:
`tools/database_import.py:146-147` unlinks it when `nominatim import --drop` is
used. This image does not pass `--drop` — `init.sh:100-102` runs `nominatim
import` without it — which is why the file survived this import at all.

**An import without it is a supported mode, not a degraded one.**
`env.defaults`: "Location of the flatnode file used by osm2pgsql to store node
locations. When unset, osm2pgsql stores the location in the PostgreSQL
database." The upstream import guide tells you to size the osm2pgsql cache for
that case, and `tools/database_import.py:120-124` does that sizing itself when
the setting is empty and no explicit cache was passed — which is exactly this
image's invocation. Upstream recommends the file "for large datasets"; the
setting's own documentation scopes it to "continents or the full planet". A
269.5 MB extract is neither.

The one documented requirement is the one this deployment does not use: "If you
have configured a flatnode file for the import process, it is crucial to retain
this file for subsequent updates to function correctly." `exec_utils.py:52-53`
is where that bites — `--flat-nodes` is appended to the osm2pgsql command line
only when the setting is non-empty, and an *append* run needs node locations the
file alone holds. This service sets `UPDATE_MODE: none`, and the approved refresh
policy is a monthly full re-import against a new pin, not a replication catch-up.

### Removing it cannot silently relocate it

The 105 GiB is only ever written because a directory exists. `config.sh:54-56` is
the entire mechanism:

```sh
# if flatnode directory was created by volume / mount, use flatnode files
if [ -d "${PROJECT_DIR}/flatnode" ]; then sed -i 's\^NOMINATIM_FLATNODE_FILE=$\NOMINATIM_FLATNODE_FILE="/nominatim/flatnode/flatnode.file"\g' ${CONFIG_FILE}; fi
```

The setting is populated only if `/nominatim/flatnode` exists. The obvious failure
mode of dropping the volume would be the file landing on the container's writable
layer instead — same disk consumed, worse provenance, and invisible to
`docker volume ls`. That cannot happen with this image: `/nominatim` in the image
contains one file, a 222-byte `.env`, and no `flatnode` directory. Verified by
running the image with its command overridden, not by reading a Dockerfile. With
the mount gone the directory does not exist, the sed does not fire, the setting
stays empty, and `exec_utils.py` omits `--flat-nodes` entirely.

`start.sh:24` runs `config.sh` on **every** boot, so this is re-decided at each
start rather than fixed at import time. Two consequences worth recording:
restoring the volume restores the old behaviour with no other change, and none of
this re-triggers an import — `start.sh:32-39` gates `init.sh` on the sentinel
`/var/lib/postgresql/16/main/import-finished`, which lives inside the
`nominatim-db` volume. Deleting the flatnode volume cannot cause a re-import; only
deleting the database volume can.

### What deleting it forecloses

`REPLICATION_URL` is set on this service and `FREEZE` is `false`, so
`start.sh:46-48` runs `nominatim replication --init` on every boot and then prints
"skipping replication", because `UPDATE_MODE` matches none of `continuous`,
`once` or `catch-up`. `--init` only writes the replication status row; it runs no
osm2pgsql. Nothing on the boot path consumes the flatnode file.

What is lost is the option the compose comment used to advertise: flipping
`UPDATE_MODE` to `catch-up` against *this* database will not work once the file is
gone, because an osm2pgsql append cannot recover node locations that were never
written to PostgreSQL. Re-enabling replication would need a full re-import with
the volume restored. That is a capability trade the monthly-full-reimport policy
already makes, recorded here rather than left for someone to discover from a
failed append.

### What was done about it

| Step | Value |
| --- | --- |
| Determination | import artifact — not required for query-only service, not required for a full re-import |
| Authority | `nominatim_api` has no code reference; `freeze` deletes it; unset is a documented import mode |
| Action | removed after the successful import; the service was recreated without `/nominatim/flatnode` |
| `ddev-ppu_nominatim-flatnode` | absent; `ddev-ppu_nominatim-db` is the only remaining Nominatim volume |
| Compose wiring removed | yes — no flatnode volume, mount or environment setting remains; the compose comments document the supported unset mode |
| Disk reclaimed | 113,226,840,656 bytes (approximately 105.5 GiB), the exact former `flatnode.file` size |
| Re-verified after the change | `/status` 200, database and import sentinel preserved, four in-area geocodes passed, two out-of-area controls refused, and PostgreSQL remained network-isolated |

## Prerequisite gate cleared — 2026-09-08

The provisioned service was rechecked after removal of the flatnode volume and
after the database hardening restart. These are live results from the network
path and Drupal plugin that Task 20D will use, not container-internal shortcuts.

| Check | Result |
| --- | --- |
| Service health from the DDEV web container | `http://nominatim:8080/status` returned HTTP 200 |
| Imported source | 269,504,387 bytes; MD5 `77b0c1c4f92b37ccd0fb32881dc04b31` |
| Import persistence | image-managed `import-finished` sentinel present; `placex` present; PostgreSQL database size 6,147,619,863 bytes |
| Steady-state storage | only `ddev-ppu_nominatim-db` remains; measured volume size 7,228,664,243 bytes |
| Positive controls | Manhattan, Jersey City, Stamford and Hempstead all resolved inside their plausibility boxes (4/4) |
| Coverage controls | Washington, DC and Los Angeles returned no results as required (2/2) |
| Determinism | the Manhattan result remained identical after clearing Drupal's geocoder cache bin |
| Drupal provider | `ppu_nominatim` loads the `nominatim` plugin at `http://nominatim:8080` with data version `nycmetro-20260904T202121Z+md5.77b0c1c4` |
| PostgreSQL hardening | `listen_addresses = 'localhost'`; all seven parsed HBA rules are error-free; only local peer and loopback SCRAM access remain |
| Network isolation | TCP connection to `nominatim:5432` from the DDEV web container was refused; port 5432 is not published to the host |

The negative controls also confirmed an important limitation already recorded
above: contrib's `geocoder` service collapses provider failures to `NULL`, while
calling the configured plugin directly preserves the vendor exception. Task 20D
must therefore use the approved provider plugin directly when it needs to
classify coverage and provider failures.

`custom_webform_handlers.settings:routing.geocoder` remains intentionally
unset in active configuration. Provisioning proves the provider and its dataset;
Task 20D owns the configuration migration that records provider
`ppu_nominatim`, the pinned data version, and the persistence policy. No Task 20D
PHP source, service alias or routing adapter was introduced during provisioning.
