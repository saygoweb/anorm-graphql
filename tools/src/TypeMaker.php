<?php
namespace Anorm\GraphQL\Tools;

use Anorm\GraphQL\Tools\Schema\SchemaEditor;
use Anorm\GraphQL\Tools\Schema\SchemaScaffolder;
use Anorm\GraphQL\Tools\Writer\InputBaseWriter;
use Anorm\GraphQL\Tools\Writer\InputWriter;
use Anorm\GraphQL\Tools\Writer\TestCaseWriter;
use Anorm\GraphQL\Tools\Writer\TestWriter;
use Anorm\GraphQL\Tools\Writer\TypeBaseWriter;
use Anorm\GraphQL\Tools\Writer\TypeWriter;
use Anorm\Tools\ModelLocator;

/** One run of `anorm-graphql make`. */
class TypeMaker
{
    /** @var string[] Lines for the user, in order */
    public $report = array();

    /** @var TypeMakerOptions */
    private $options;

    public function __construct(TypeMakerOptions $options)
    {
        $this->options = $options;
    }

    /**
     * @return int Process exit code: 0, or 2 for a bad argument
     */
    public function run()
    {
        $o = $this->options;
        $locator = new ModelLocator(new NullPdo());
        $models = $locator->locate($o->modelsDir, $o->modelNamespace);
        \ksort($models, SORT_STRING);

        $builder = new TypeInfoBuilder($o->classSuffix);
        $known = array();
        foreach ($models as $class => $model) {
            $known[$builder->entityName($class)] = $class;
        }
        foreach (array('only' => $o->only, 'readonly' => $o->readOnly) as $option => $names) {
            foreach ($names as $name) {
                if (!isset($known[$this->entityOf($name)])) {
                    $this->report[] = "Error: --$option names '$name', which is not a model in {$o->modelsDir}";
                    return 2;
                }
            }
        }
        $only = \array_map(array($this, 'entityOf'), $o->only);
        $readOnly = \array_map(array($this, 'entityOf'), $o->readOnly);

        $infos = array();
        foreach ($models as $class => $model) {
            $entity = $builder->entityName($class);
            if ($only && !\in_array($entity, $only, true)) {
                continue;
            }
            $info = $builder->build($model, \in_array($entity, $readOnly, true));
            if ($info !== null) {
                $infos[] = $info;
            }
        }

        $files = new FileWriter($o->dryRun);
        foreach ($infos as $info) {
            $this->writeEntity($files, $info);
        }
        if ($o->testsDir !== null && $infos) {
            $schemaClass = \trim($o->schemaNamespace, '\\') . '\\' . $this->schemaClassName();
            $files->writeOnce(
                $this->join($o->testsDir, 'TestCase.php'),
                (new TestCaseWriter())->render($o->testNamespace, $schemaClass),
                false
            );
        }
        $schemaLines = $o->schemaPath === null ? array() : $this->maintainSchema($files, $infos, \array_keys($known));

        $this->report = \array_merge($this->report, $files->report);
        foreach ($this->orphans(\array_keys($known)) as $path) {
            $this->report[] = "orphaned $path (no model produces it; not deleted)";
        }
        foreach ($locator->skipped + $builder->skipped as $what => $why) {
            $this->report[] = "skipped  $what: $why";
        }
        $this->report = \array_merge($this->report, $schemaLines);
        return 0;
    }

    private function writeEntity(FileWriter $files, TypeInfo $info)
    {
        $o = $this->options;
        $dir = $this->join($o->outputDir, $info->entity);
        $files->writeGenerated(
            "$dir/Base/{$info->entity}TypeBase.php",
            (new TypeBaseWriter())->render($info, $o->typeNamespace)
        );
        $files->writeOnce("$dir/{$info->entity}Type.php", (new TypeWriter())->render($info, $o->typeNamespace), $o->force);
        if (!$info->readOnly) {
            $files->writeGenerated(
                "$dir/Base/{$info->entity}InputBase.php",
                (new InputBaseWriter())->render($info, $o->typeNamespace)
            );
            $files->writeOnce("$dir/{$info->entity}Input.php", (new InputWriter())->render($info, $o->typeNamespace), $o->force);
        }
        if ($o->testsDir !== null) {
            $files->writeOnce(
                $this->join($o->testsDir, "{$info->entity}TypeTest.php"),
                (new TestWriter())->render($info, $o->typeNamespace, $o->testNamespace),
                $o->force
            );
        }
    }

    /**
     * @param TypeInfo[] $infos
     * @param string[] $knownEntities
     * @return string[] Lines to report after everything else
     */
    private function maintainSchema(FileWriter $files, array $infos, array $knownEntities)
    {
        $o = $this->options;
        $exists = \file_exists($o->schemaPath);
        $before = $exists
            ? (string) \file_get_contents($o->schemaPath)
            : (new SchemaScaffolder())->render($o->schemaNamespace, $this->schemaClassName());

        $result = (new SchemaEditor($o->typeNamespace))->edit($before, $infos, $knownEntities);
        $lines = $result->messages;
        if ($result->failed) {
            $lines[] = 'Add these entries to ' . $o->schemaPath . ' by hand, in alphabetical order:';
            foreach ($result->paste as $rootKey => $entries) {
                foreach ($entries as $entry) {
                    $lines[] = "in the '$rootKey' fields array:\n" . $entry;
                }
            }
            return $lines;
        }
        if ($exists && $result->source === $before) {
            $files->report[] = "current  {$o->schemaPath}";
            return $lines;
        }
        if ($o->dryRun && $exists) {
            $lines[] = $this->diff($before, $result->source, $o->schemaPath);
        }
        $files->replace($o->schemaPath, $result->source, $exists ? 'updated' : 'written');
        return $lines;
    }

    /** @return string A unified diff, or the new content where `diff` is not installed */
    private function diff($before, $after, $label)
    {
        $a = \tempnam(\sys_get_temp_dir(), 'agq');
        $b = \tempnam(\sys_get_temp_dir(), 'agq');
        \file_put_contents($a, $before);
        \file_put_contents($b, $after);
        $command = 'diff -u --label ' . \escapeshellarg($label) . ' --label ' . \escapeshellarg($label . ' (new)')
            . ' ' . \escapeshellarg($a) . ' ' . \escapeshellarg($b) . ' 2>/dev/null';
        $diff = \shell_exec($command);
        \unlink($a);
        \unlink($b);
        return \is_string($diff) && $diff !== '' ? \rtrim($diff) : $after;
    }

    /**
     * Base files on disk whose entity no located model produces.
     *
     * @param string[] $entities Every entity the models directory produces, whatever --only says
     * @return string[]
     */
    private function orphans(array $entities)
    {
        $orphans = array();
        $pattern = $this->join($this->options->outputDir, '*/Base/*Base.php');
        foreach ((array) \glob($pattern) as $path) {
            $entity = \basename(\dirname(\dirname($path)));
            if (!\in_array($entity, $entities, true)) {
                $orphans[] = $path;
            }
        }
        return $orphans;
    }

    /** Accepts 'Client' or 'ClientModel'. */
    private function entityOf($name)
    {
        return (new TypeInfoBuilder($this->options->classSuffix))->entityName($name);
    }

    private function schemaClassName()
    {
        return \basename((string) $this->options->schemaPath, '.php') ?: 'ApiSchema';
    }

    private function join($dir, $path)
    {
        return \rtrim($dir, '/') . '/' . $path;
    }
}
