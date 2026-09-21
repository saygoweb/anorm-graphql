<?php

namespace Anorm\GraphQL\Test;

/** A scratch directory under build/ that a test makes and removes. */
trait TempDir
{
    /** @var string */
    protected $dir;

    protected function makeTempDir(): void
    {
        $this->dir = dirname(__DIR__) . '/build/tmp/' . uniqid('t', true);
        mkdir($this->dir, 0777, true);
    }

    protected function removeTempDir(): void
    {
        if (!$this->dir || !is_dir($this->dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }
}
