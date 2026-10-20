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
