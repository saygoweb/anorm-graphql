<?php
namespace Anorm\GraphQL\Tools;

use Anorm\GraphQL\Tools\Writer\Php;

/**
 * Writes files under the generator's rules, and keeps the report.
 *
 * It writes only inside the directories it was given, and never through a symbolic
 * link: a link under the output directory must not become a way to overwrite a file
 * somewhere else.
 */
class FileWriter
{
    /** @var string[] One line per file, e.g. 'written  src/...' */
    public $report = array();

    /** @var bool */
    private $dryRun;

    /** @var string[] Canonical directories that writes must stay inside; empty for no restriction */
    private $roots = array();

    /**
     * @param bool $dryRun
     * @param string[] $roots Directories that writes must stay inside
     */
    public function __construct($dryRun = false, array $roots = array())
    {
        $this->dryRun = (bool) $dryRun;
        foreach ($roots as $root) {
            $this->roots[] = \rtrim($this->canonical($root), '/') . '/';
        }
    }

    /**
     * A file the generator owns. Overwritten every run, but only if what is there
     * already begins with the generated header: a hand-written file is never clobbered,
     * not even one that quotes the header somewhere.
     *
     * @return bool false when refused
     */
    public function writeGenerated($path, $content)
    {
        if ($this->refuse($path)) {
            return false;
        }
        if (\file_exists($path) && !Php::isGenerated((string) \file_get_contents($path))) {
            $this->report[] = "refused  $path (exists and does not begin with the generated header; move it aside to regenerate)";
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
        if ($this->refuse($path)) {
            return;
        }
        if (\file_exists($path) && !$force) {
            $this->report[] = "kept     $path";
            return;
        }
        $this->put($path, $content, \file_exists($path) ? 'forced  ' : 'written ');
    }

    /** Replace a file's content outright; for the schema, whose edit is computed elsewhere. */
    public function replace($path, $content, $verb)
    {
        if ($this->refuse($path)) {
            return;
        }
        $this->put($path, $content, \str_pad($verb, 8));
    }

    /** @return bool true, having said why, when $path must not be written */
    private function refuse($path)
    {
        if (\is_link($path)) {
            $this->report[] = "refused  $path (is a symbolic link)";
            return true;
        }
        if (!$this->roots) {
            return false;
        }
        $canonical = $this->canonical($path);
        foreach ($this->roots as $root) {
            if (\strpos($canonical, $root) === 0) {
                return false;
            }
        }
        $this->report[] = "refused  $path (resolves to $canonical, outside the directories given)";
        return true;
    }

    /**
     * The real path of something that may not exist yet: the real path of its deepest
     * existing ancestor, plus the rest.
     */
    private function canonical($path)
    {
        if ($path === '' || $path[0] !== '/') {
            $path = \getcwd() . '/' . $path;
        }
        $rest = '';
        while (!\file_exists($path) && \dirname($path) !== $path) {
            $rest = '/' . \basename($path) . $rest;
            $path = \dirname($path);
        }
        $real = \realpath($path);
        return ($real === false ? $path : $real) . $rest;
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
