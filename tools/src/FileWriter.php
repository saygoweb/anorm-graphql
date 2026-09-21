<?php
namespace Anorm\GraphQL\Tools;

use Anorm\GraphQL\Tools\Writer\Php;

/** Writes files under the generator's two rules, and keeps the report. */
class FileWriter
{
    /** @var string[] One line per file, e.g. 'written  src/...' */
    public $report = array();

    /** @var bool */
    private $dryRun;

    public function __construct($dryRun = false)
    {
        $this->dryRun = (bool) $dryRun;
    }

    /**
     * A file the generator owns. Overwritten every run, but only if what is there
     * already carries the generated header: a hand-written file is never clobbered.
     *
     * @return bool false when refused
     */
    public function writeGenerated($path, $content)
    {
        if (\file_exists($path) && \strpos((string) \file_get_contents($path), Php::HEADER) === false) {
            $this->report[] = "refused  $path (exists without the generated header; move it aside to regenerate)";
            return false;
        }
        if (\file_exists($path) && \file_get_contents($path) === $content) {
            $this->report[] = "current  $path";
            return true;
        }
        $this->put($path, $content, 'written ');
        return true;
    }

    /**
     * A file the project owns. Written when absent, or when forced.
     */
    public function writeOnce($path, $content, $force = false)
    {
        if (\file_exists($path) && !$force) {
            $this->report[] = "kept     $path";
            return;
        }
        $this->put($path, $content, \file_exists($path) ? 'forced  ' : 'written ');
    }

    /** Replace a file's content outright; for the schema, whose edit is computed elsewhere. */
    public function replace($path, $content, $verb)
    {
        $this->put($path, $content, \str_pad($verb, 8));
    }

    private function put($path, $content, $verb)
    {
        $this->report[] = $verb . ' ' . $path . ($this->dryRun ? ' (dry run)' : '');
        if ($this->dryRun) {
            return;
        }
        $dir = \dirname($path);
        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \Exception("Could not create directory '$dir'");
        }
        if (\file_put_contents($path, $content) === false) {
            throw new \Exception("Could not write '$path'");
        }
    }
}
