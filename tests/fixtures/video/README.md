# Prepared video provenance

Copyright 2026 Utsuri Foundry, SL. Apache-2.0.

These are original generated colour frames, not imported footage. There is no
music, audio stream, person, client media or third-party artwork. Generated with
FFmpeg 8.1 from `color=c=0x587869:s=160x90:r=10:d=1`, one second at 160 × 90.

```sh
ffmpeg -f lavfi -i 'color=c=0x587869:s=160x90:r=10:d=1' -an -c:v libx264 -pix_fmt yuv420p -movflags +faststart -map_metadata -1 prepared.mp4
ffmpeg -f lavfi -i 'color=c=0x587869:s=160x90:r=10:d=1' -an -c:v libvpx-vp9 -map_metadata -1 prepared.webm
```

The committed prepared bytes are authoritative. The current Nix shell provides
FFmpeg for inspection or a deliberate new fixture revision; a different FFmpeg
version need not reproduce encoder/container bytes exactly. Installed PHP and
tests consume these bytes and do not invoke FFmpeg. The publication review
verified H.264/VP9, duration, dimensions and absence of audio with ffprobe.

| File | SHA-256 |
| --- | --- |
| prepared.mp4 | c8539682e08d72380a314056705cbe93537cecb1e7bbc1f73cf12025205e4b1a |
| prepared.webm | 14abbfb8bbbba8cb614d3a1a9b53116cac659d8e6813ac6ed536b20374da5d6c |
