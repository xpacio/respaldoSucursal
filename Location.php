<?php

declare(strict_types=1);

class Location
{
    public string $rbfid;
    public string $base_path;
    public string $work_path;

    public function __construct(string $rbfid, string $base_path, string $work_path)
    {
        $this->rbfid = $rbfid;
        $this->base_path = $base_path;
        $this->work_path = $work_path;
    }

    public function toArray(): array
    {
        return [
            'rbfid' => $this->rbfid,
            'base' => $this->base_path,
            'work' => $this->work_path,
        ];
    }

    public static function fromArray(array $data): ?Location
    {
        if (!isset($data['rbfid']) || !isset($data['base']) || !isset($data['work'])) {
            return null;
        }
        return new Location($data['rbfid'], $data['base'], $data['work']);
    }
}