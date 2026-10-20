# Deployment requirements

Things the production image and server must provide. The application checks some of them at boot
(`ProductionChecks`) and warns about others on the panel's home.

## Image library (GD) with AVIF

The media library writes every picture as AVIF, WebP and JPEG. A development machine may report AVIF as
available while refusing to write it, so the application probes the encoder once per process.

The production Docker image must ship a PHP build whose GD extension is compiled with `libavif`
(`--with-avif`) and can actually encode. If it cannot, pictures are served as WebP and JPEG only and the
panel's home shows a warning. Verify in the built image:

    php -r 'var_dump(imageavif(imagecreatetruecolor(1, 1)));'

It must print `bool(true)` (and write the image to stdout).

## ffmpeg and ffprobe

Short uploaded videos (decision 8.11) are measured with `ffprobe` and rewritten without their metadata
(GPS position, device, time) by `ffmpeg` (package `ffmpeg`, which provides both). Without them an upload
is refused with a clear message; a video is never stored with its metadata. Both programs are started
with `-protocol_whitelist file` and the input forced to MP4, so a crafted file cannot make them read
other files or reach the network. The image must contain both binaries on `PATH`.

## Meilisearch v1.54.3

Search (decision 10) runs on Meilisearch **v1.54.3**, pinned: `search:create-key` refuses any other version.
The engine listens on the internal Docker network only and is started with a master key. That master key
stays with the engine: the application never holds it.

1. With the master key, create the application's key once: `php artisan search:create-key` asks for the
   master key (hidden) and prints a key that can only index and search the indexes starting with
   `MEILISEARCH_PREFIX`. It cannot create or read API keys, dumps or snapshots, or touch other indexes.
2. Set `MEILISEARCH_HOST`, `MEILISEARCH_KEY` (that key) and `MEILISEARCH_PREFIX`. Production refuses to
   start without them, and `search:setup` refuses to run with a key that can manage API keys.
3. At every deploy: `php artisan search:setup` (creates the indexes, applies the settings and synonyms).
   On the first deploy, follow it with `php artisan search:reindex`.

The scheduler runs `search:sync-due` every minute (a safety net for the indexing jobs) and
`search:reindex` every night at 03:30 (repairs any drift). A queue worker must run: indexing happens in
queued jobs after each save, so a save never waits for the engine. When the engine is down, visitors
are answered from PostgreSQL (pg_trgm) and the jobs retry.
