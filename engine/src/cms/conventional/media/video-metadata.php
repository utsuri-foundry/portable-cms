<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

/** Bounded container inspection. The adapter supplies random-access bytes; sample data is never decoded. */
final class VideoMetadata
{
    private int $elements = 0;
    private int $metadataBytes = 0;

    /** @param \Closure(int, int): string $read */
    private function __construct(private readonly int $size, private readonly \Closure $read) {}

    /** @param \Closure(int, int): string $read
     * @return array{width: int, height: int, durationMilliseconds: int, codec: string, hasAudio: bool}
     */
    public static function inspect(string $mime, int $size, \Closure $read): array
    {
        if ($size < 1 || $size > VideoSupport::MAX_BYTES) {
            self::reject();
        }
        $parser = new self($size, $read);
        $facts = match ($mime) {
            'video/mp4' => $parser->mp4(),
            'video/webm' => $parser->webm(),
            default => self::reject(),
        };
        if ($facts['width'] < 1 || $facts['height'] < 1 || $facts['width'] > 4096 || $facts['height'] > 4096
            || $facts['width'] * $facts['height'] > 8_847_360 || $facts['durationMilliseconds'] < 1 || $facts['durationMilliseconds'] > 7_200_000) {
            self::reject();
        }
        return $facts;
    }

    private static function reject(): never
    {
        throw new MediaProcessingFailure('invalid_preencoded_video', false, 'The prepared video has unsupported or invalid container metadata.');
    }

    private function bytes(int $offset, int $length): string
    {
        $this->metadataBytes += $length;
        if ($offset < 0 || $length < 1 || $length > 1_048_576 || $offset > $this->size - $length || $this->metadataBytes > 2_097_152) {
            self::reject();
        }
        $bytes = ($this->read)($offset, $length);
        if (strlen($bytes) !== $length) {
            self::reject();
        }
        return $bytes;
    }

    private function element(): void
    {
        if (++$this->elements > 4096) {
            self::reject();
        }
    }

    private static function integer(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            if ($value > intdiv(PHP_INT_MAX - ord($byte), 256)) {
                self::reject();
            }
            $value = $value * 256 + ord($byte);
        }
        return $value;
    }

    /** @return list<array{type: string, offset: int, length: int}> */
    private function boxes(int $start, int $end): array
    {
        $boxes = [];
        while ($start < $end) {
            $this->element();
            if ($end - $start < 8) {
                self::reject();
            }
            $header = $this->bytes($start, 8);
            $length = self::integer(substr($header, 0, 4));
            $headerSize = 8;
            if ($length === 1) {
                $length = self::integer($this->bytes($start + 8, 8));
                $headerSize = 16;
            } elseif ($length === 0) {
                $length = $end - $start;
            }
            if ($length < $headerSize || $length > $end - $start) {
                self::reject();
            }
            $boxes[] = ['type' => substr($header, 4, 4), 'offset' => $start + $headerSize, 'length' => $length - $headerSize];
            $start += $length;
        }
        return $boxes;
    }

    /** @param list<array{type: string, offset: int, length: int}> $boxes
     * @return array{type: string, offset: int, length: int}
     */
    private static function box(array $boxes, string $type): array
    {
        $matches = array_values(array_filter($boxes, static fn(array $box): bool => $box['type'] === $type));
        if (count($matches) !== 1) {
            self::reject();
        }
        return $matches[0];
    }

    /** @param array{type: string, offset: int, length: int} $box
     * @return list<array{type: string, offset: int, length: int}>
     */
    private function children(array $box): array
    {
        return $this->boxes($box['offset'], $box['offset'] + $box['length']);
    }

    /** @return array{width: int, height: int, durationMilliseconds: int, codec: string, hasAudio: bool} */
    private function mp4(): array
    {
        $boxes = $this->boxes(0, $this->size);
        $ftyp = self::box($boxes, 'ftyp');
        if ($boxes[0]['type'] !== 'ftyp' || $ftyp['length'] < 8 || $ftyp['length'] > 256 || $ftyp['length'] % 4 !== 0) {
            self::reject();
        }
        $brands = $this->bytes($ftyp['offset'], $ftyp['length']);
        if (!in_array(substr($brands, 0, 4), ['isom', 'iso2', 'mp41', 'mp42', 'avc1'], true)) {
            self::reject();
        }
        $mdat = array_values(array_filter($boxes, static fn(array $box): bool => $box['type'] === 'mdat' && $box['length'] > 0));
        if ($mdat === [] || array_filter($boxes, static fn(array $box): bool => $box['type'] === 'moof') !== []) {
            self::reject();
        }
        $movie = $this->children(self::box($boxes, 'moov'));
        $mvhd = self::box($movie, 'mvhd');
        $header = $this->bytes($mvhd['offset'], min($mvhd['length'], 32));
        $version = ord($header[0]);
        $timeOffset = match ($version) {
            0 => 12, 1 => 20, default => self::reject()
        };
        if (strlen($header) < $timeOffset + ($version === 0 ? 8 : 12)) {
            self::reject();
        }
        $scale = self::integer(substr($header, $timeOffset, 4));
        $duration = self::integer(substr($header, $timeOffset + 4, $version === 0 ? 4 : 8));
        if ($scale < 1 || $duration < 1 || $duration / $scale > 7200) {
            self::reject();
        }
        $video = null;
        $hasAudio = false;
        $tracks = array_values(array_filter($movie, static fn(array $box): bool => $box['type'] === 'trak'));
        if ($tracks === [] || count($tracks) > 2) {
            self::reject();
        }
        foreach ($tracks as $track) {
            $media = $this->children(self::box($this->children($track), 'mdia'));
            $handler = self::box($media, 'hdlr');
            if ($handler['length'] < 12) {
                self::reject();
            }
            $kind = $this->bytes($handler['offset'] + 8, 4);
            if (!in_array($kind, ['vide', 'soun'], true)) {
                self::reject();
            }
            $hasAudio = $hasAudio || $kind === 'soun';
            $minf = $this->children(self::box($media, 'minf'));
            $dref = self::box($this->children(self::box($minf, 'dinf')), 'dref');
            if ($dref['length'] < 8 || self::integer($this->bytes($dref['offset'] + 4, 4)) !== 1) {
                self::reject();
            }
            $refs = $this->boxes($dref['offset'] + 8, $dref['offset'] + $dref['length']);
            $url = self::box($refs, 'url ');
            if ($url['length'] !== 4 || $this->bytes($url['offset'], 4) !== "\0\0\0\1") {
                self::reject();
            }
            $table = $this->children(self::box($minf, 'stbl'));
            $stsd = self::box($table, 'stsd');
            if ($stsd['length'] < 8 || self::integer($this->bytes($stsd['offset'] + 4, 4)) !== 1) {
                self::reject();
            }
            $samples = $this->boxes($stsd['offset'] + 8, $stsd['offset'] + $stsd['length']);
            if (count($samples) !== 1 || !in_array($samples[0]['type'], $kind === 'vide' ? ['avc1', 'avc3'] : ['mp4a'], true)) {
                self::reject();
            }
            $sample = $samples[0];
            if ($kind === 'vide') {
                if ($video !== null || $sample['length'] < 78) {
                    self::reject();
                }
                $dimensions = $this->bytes($sample['offset'] + 24, 4);
                $codec = self::box($this->boxes($sample['offset'] + 78, $sample['offset'] + $sample['length']), 'avcC');
                if ($codec['length'] < 7 || $this->bytes($codec['offset'], 1) !== "\1") {
                    self::reject();
                }
                $video = ['width' => self::integer(substr($dimensions, 0, 2)), 'height' => self::integer(substr($dimensions, 2, 2)), 'codec' => 'h264'];
            }
            $stsz = self::box($table, 'stsz');
            if ($stsz['length'] < 12 || self::integer($this->bytes($stsz['offset'] + 8, 4)) < 1) {
                self::reject();
            }
        }
        if ($video === null) {
            self::reject();
        }
        return [...$video, 'hasAudio' => $hasAudio, 'durationMilliseconds' => (int) round($duration / $scale * 1000)];
    }

    /** @return array{int, int, bool} value, width, unknown */
    private function vint(int $offset, bool $id): array
    {
        $first = ord($this->bytes($offset, 1));
        $mask = 128;
        $width = 1;
        while ($mask > 0 && ($first & $mask) === 0) {
            $mask >>= 1;
            $width++;
        }
        if ($mask === 0 || $width > ($id ? 4 : 8)) {
            self::reject();
        }
        $value = $id ? $first : $first & ($mask - 1);
        $unknown = !$id && $value === $mask - 1;
        for ($index = 1; $index < $width; $index++) {
            $byte = ord($this->bytes($offset + $index, 1));
            $unknown = $unknown && $byte === 255;
            $value = $value * 256 + $byte;
        }
        return [$value, $width, $unknown];
    }

    /** @return list<array{id: int, offset: int, length: int}> */
    private function ebml(int $start, int $end, bool $root = false): array
    {
        $items = [];
        while ($start < $end) {
            $this->element();
            [$id, $idWidth] = $this->vint($start, true);
            [$length, $width, $unknown] = $this->vint($start + $idWidth, false);
            $offset = $start + $idWidth + $width;
            if ($unknown && !($root && $id === 0x18538067)) {
                self::reject();
            }
            $length = $unknown ? $end - $offset : $length;
            if ($offset > $end || $length > $end - $offset) {
                self::reject();
            }
            $items[] = ['id' => $id, 'offset' => $offset, 'length' => $length];
            $start = $offset + $length;
        }
        return $items;
    }

    /** @param list<array{id: int, offset: int, length: int}> $items */
    private function value(array $items, int $id, ?string $default = null): string
    {
        $matches = array_values(array_filter($items, static fn(array $item): bool => $item['id'] === $id));
        if ($matches === [] && $default !== null) {
            return $default;
        }
        if (count($matches) !== 1) {
            self::reject();
        }
        return $this->bytes($matches[0]['offset'], $matches[0]['length']);
    }

    /** @param list<array{id: int, offset: int, length: int}> $items
     * @return list<array{id: int, offset: int, length: int}>
     */
    private function ebmlChildren(array $items, int $id): array
    {
        $matches = array_values(array_filter($items, static fn(array $item): bool => $item['id'] === $id));
        if (count($matches) !== 1) {
            self::reject();
        }
        $item = $matches[0];
        return $this->ebml($item['offset'], $item['offset'] + $item['length']);
    }

    /** @return array{width: int, height: int, durationMilliseconds: int, codec: string, hasAudio: bool} */
    private function webm(): array
    {
        $root = $this->ebml(0, $this->size, true);
        if (($root[0]['id'] ?? null) !== 0x1A45DFA3 || $this->value($this->ebmlChildren($root, 0x1A45DFA3), 0x4282) !== 'webm') {
            self::reject();
        }
        $segment = $this->ebmlChildren($root, 0x18538067);
        if (array_filter($segment, static fn(array $item): bool => $item['id'] === 0x1F43B675 && $item['length'] > 0) === []) {
            self::reject();
        }
        $info = $this->ebmlChildren($segment, 0x1549A966);
        $scale = self::integer($this->value($info, 0x2AD7B1, "\x0f\x42\x40"));
        $durationBytes = $this->value($info, 0x4489);
        $format = match (strlen($durationBytes)) {
            4 => 'G', 8 => 'E', default => self::reject()
        };
        $durationValue = unpack($format . 'duration', $durationBytes);
        $duration = $durationValue['duration'] ?? 0;
        $milliseconds = $duration * ($scale / 1_000_000);
        if ($scale < 1 || !is_finite($milliseconds) || $milliseconds <= 0 || $milliseconds > 7_200_000) {
            self::reject();
        }
        $video = null;
        $hasAudio = false;
        $tracks = $this->ebmlChildren($segment, 0x1654AE6B);
        $entries = array_values(array_filter($tracks, static fn(array $item): bool => $item['id'] === 0xAE));
        if ($entries === [] || count($entries) > 2) {
            self::reject();
        }
        foreach ($entries as $entry) {
            $track = $this->ebml($entry['offset'], $entry['offset'] + $entry['length']);
            if (array_filter($track, static fn(array $item): bool => $item['id'] === 0x6D80) !== []) {
                self::reject();
            }
            $kind = self::integer($this->value($track, 0x83));
            $hasAudio = $hasAudio || $kind === 2;
            $codec = $this->value($track, 0x86);
            if ($kind === 1 && in_array($codec, ['V_VP8', 'V_VP9'], true)) {
                if ($video !== null) {
                    self::reject();
                }
                $geometry = $this->ebmlChildren($track, 0xE0);
                if (self::integer($this->value($geometry, 0x54B2, "\0")) !== 0) {
                    self::reject();
                }
                $video = ['width' => self::integer($this->value($geometry, 0xB0)), 'height' => self::integer($this->value($geometry, 0xBA)), 'codec' => $codec === 'V_VP8' ? 'vp8' : 'vp9'];
            } elseif ($kind !== 2 || !in_array($codec, ['A_OPUS', 'A_VORBIS'], true)) {
                self::reject();
            }
        }
        if ($video === null) {
            self::reject();
        }
        return [...$video, 'hasAudio' => $hasAudio, 'durationMilliseconds' => (int) round($milliseconds)];
    }
}
