<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

class ChangeQueue
{
    private string $filePath;
    private array $queue = [];

    public const CHANGE_STRUCT = [
        'filename' => 'filename',
        'timestamp' => 'timestamp',
        'size' => 'size',
    ];

    public function __construct(string $workDir)
    {
        $this->filePath = $workDir . DIRECTORY_SEPARATOR . '.ar_queue.dat';
        $this->load();
    }

    public function add(string $filename, int $size): void
    {
        $this->queue[] = [
            'filename' => $filename,
            'timestamp' => (int)(microtime(true) * 1000),
            'size' => $size,
        ];
        $this->save();
    }

    public function process(): array
    {
        return $this->queue;
    }

    public function clear(): void
    {
        $this->queue = [];
        $this->save();
    }

    public function remove(string $filename): void
    {
        $this->queue = array_values(array_filter(
            $this->queue,
            fn($item) => $item['filename'] !== $filename
        ));
        $this->save();
    }

    private function pack64(int|float $val): string
    {
        $high = (int)($val / 4294967296);
        $low = (int)($val % 4294967296);
        return pack('NN', $high, $low);
    }

    private function unpack64(string $data): float
    {
        $parts = unpack('Nhigh/Nlow', $data);
        return $parts['high'] * 4294967296.0 + (float)sprintf('%u', $parts['low']);
    }

    private function save(): void
    {
        $fp = fopen($this->filePath, 'wb');
        if ($fp === false) {
            Logger::error("Cannot open queue file for writing: {$this->filePath}");
            return;
        }

        $count = count($this->queue);
        fwrite($fp, pack('V', $count));

        foreach ($this->queue as $change) {
            $filenameLen = strlen($change['filename']);
            fwrite($fp, pack('V', $filenameLen));
            fwrite($fp, $change['filename']);
            fwrite($fp, $this->pack64((int)$change['timestamp']));
            fwrite($fp, $this->pack64((int)$change['size']));
        }

        fclose($fp);
    }

    private function load(): void
    {
        if (!file_exists($this->filePath)) {
            return;
        }

        $fp = fopen($this->filePath, 'rb');
        if ($fp === false) {
            return;
        }

        $data = fread($fp, 4);
        if ($data === false || strlen($data) < 4) {
            fclose($fp);
            return;
        }

        $unpacked = unpack('V', $data);
        $count = $unpacked[1] ?? 0;

        $this->queue = [];
        for ($i = 0; $i < $count; $i++) {
            $lenData = fread($fp, 4);
            if ($lenData === false || strlen($lenData) < 4) break;
            
            $lenUnpacked = unpack('V', $lenData);
            $len = $lenUnpacked[1] ?? 0;

            $filename = fread($fp, $len);
            $timestampData = fread($fp, 8);
            $sizeData = fread($fp, 8);

            if ($filename === false || $timestampData === false || $sizeData === false) {
                break;
            }

            $this->queue[] = [
                'filename' => $filename,
                'timestamp' => $this->unpack64($timestampData),
                'size' => $this->unpack64($sizeData),
            ];
        }

        fclose($fp);
    }
}