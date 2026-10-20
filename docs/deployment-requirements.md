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

## ffprobe

Short uploaded videos (decision 8.11) are measured with `ffprobe` (package `ffmpeg`). Without it the
upload is refused with a clear message. The image must contain the binary on `PATH`.
