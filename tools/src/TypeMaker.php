<?php
namespace Anorm\GraphQL\Tools;

use Anorm\GraphQL\ModelType;
use Anorm\GraphQL\Tools\Schema\SchemaEditor;
use Anorm\GraphQL\Tools\Schema\SchemaScaffolder;
use Anorm\GraphQL\Tools\Writer\InputBaseWriter;
use Anorm\GraphQL\Tools\Writer\InputWriter;
use Anorm\GraphQL\Tools\Writer\Php;
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

    /** @var array<string, string> Model class => why its generated code was not written */
    private $unparseable = array();

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
        $namespaces = array('--type-ns' => $o->typeNamespace);
        if ($o->testsDir !== null) {
            $namespaces['--test-ns'] = $o->testNamespace;
        }
        if ($o->schemaPath !== null) {
            $namespaces['--schema-ns'] = $o->schemaNamespace;
        }
        foreach ($namespaces as $option => $namespace) {
            $bad = Php::unusableSegment($namespace);
            if ($bad !== null) {
                $this->report[] = "Error: $option '$namespace' cannot be used: '$bad' is not a name PHP 7.4 allows in a namespace";
                return 2;
            }
        }
        $problem = $this->typeBaseProblem($o->typeBase);
        if ($problem !== null) {
            $this->report[] = "Error: --type-base '{$o->typeBase}' $problem";
            return 2;
        }
        if (!\in_array($o->mutations, array('upsert', 'create-update'), true)) {
            $this->report[] = "Error: --mutations '{$o->mutations}' must be 'upsert' or 'create-update'";
            return 2;
        }

        $locator = new ModelLocator(new NullPdo());
        $models = $locator->locate($o->modelsDir, $o->modelNamespace);
        \ksort($models, SORT_STRING);

        $builder = new TypeInfoBuilder($o->classSuffix);
        $known = array();
        $unusable = array();
        foreach ($models as $class => $model) {
            $entity = $builder->entityName($class);
            if (isset($known[$entity])) {
                // Two models, one set of files: neither can have them. Say so for both.
                $unusable[$class] = "entity '$entity' is also produced by {$known[$entity]}; rename one of the models";
                $unusable[$known[$entity]] = "entity '$entity' is also produced by $class; rename one of the models";
                continue;
            }
            $known[$entity] = $class;
            if (Php::unusableSegment($entity) !== null) {
                $unusable[$class] = "entity '$entity' is not a name PHP 7.4 allows in a namespace; rename the model";
            }
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
            if (isset($unusable[$class])) {
                continue;
            }
            $info = $builder->build($model, \in_array($entity, $readOnly, true));
            if ($info !== null) {
                $info->mutations = $o->mutations;
                $infos[] = $info;
            }
        }

        $roots = array($o->outputDir);
        if ($o->testsDir !== null) {
            $roots[] = $o->testsDir;
        }
        if ($o->schemaPath !== null) {
            $roots[] = \dirname($o->schemaPath);
        }
        $files = new FileWriter($o->dryRun, $roots);
        foreach ($infos as $i => $info) {
            if (!$this->writeEntity($files, $info)) {
                // Keep the schema from gaining entries for Types that were not written.
                unset($infos[$i]);
            }
        }
        $infos = \array_values($infos);
        if ($o->testsDir !== null && $infos) {
            $schemaClass = \trim($o->schemaNamespace, '\\') . '\\' . $this->schemaClassName();
            $files->writeOnce(
                $this->join($o->testsDir, 'TestCase.php'),
                (new TestCaseWriter())->render($o->testNamespace, $schemaClass),
                false
            );
        }
        // An entity no model can produce any more, whether its model has gone or it is
        // skipped for good, leaves its files and its schema entries behind.
        $producible = array();
        foreach ($known as $entity => $class) {
            if (!isset($unusable[$class]) && !isset($this->unparseable[$class])) {
                $producible[] = $entity;
            }
        }
        $schemaLines = $o->schemaPath === null ? array() : $this->maintainSchema($files, $infos, $producible);

        $this->report = \array_merge($this->report, $files->report);
        foreach ($this->orphans($producible) as $path) {
            $this->report[] = "orphaned $path (no model produces it; not deleted)";
        }
        foreach ($infos as $info) {
            $why = $info->readOnly ? 'is read-only now' : "uses {$info->mutations} mutations now";
            foreach ($this->staleInputs($info) as $path) {
                $this->report[] = "orphaned $path ('{$info->entity}' $why; not deleted)";
            }
        }
        foreach ($locator->skipped + $builder->skipped + $unusable + $this->unparseable as $what => $why) {
            $this->report[] = "skipped  $what: $why";
        }
        $this->report = \array_merge($this->report, $schemaLines);
        return 0;
    }

    /**
     * @return bool false when nothing was written because the entity's code would not parse
     */
    private function writeEntity(FileWriter $files, TypeInfo $info)
    {
        $o = $this->options;
        $dir = $this->join($o->outputDir, $info->entity);
        $generated = array("$dir/Base/{$info->entity}TypeBase.php" => (new TypeBaseWriter())->render($info, $o->typeNamespace, $o->typeBase));
        $once = array("$dir/{$info->entity}Type.php" => (new TypeWriter())->render($info, $o->typeNamespace));
        if (!$info->readOnly) {
            foreach ($this->inputKinds($info) as $kind) {
                $generated["$dir/Base/{$info->entity}{$kind}InputBase.php"] = (new InputBaseWriter())->render($info, $o->typeNamespace, $kind);
                $once["$dir/{$info->entity}{$kind}Input.php"] = (new InputWriter())->render($info, $o->typeNamespace, $kind);
            }
        }
        if ($o->testsDir !== null) {
            $once[$this->join($o->testsDir, "{$info->entity}TypeTest.php")]
                = (new TestWriter())->render($info, $o->typeNamespace, $o->testNamespace);
        }

        // Never write PHP that does not parse. A property or class name the templates
        // cannot carry is the likeliest cause, and it spoils every file of the entity.
        foreach ($generated + $once as $path => $code) {
            $problem = Php::parseError($code);
            if ($problem !== null) {
                $this->unparseable[$info->modelClass] = "the code generated for '{$info->entity}' would not parse ($problem); nothing written for it";
                return false;
            }
        }
        foreach ($generated as $path => $code) {
            $files->writeGenerated($path, $code);
        }
        foreach ($once as $path => $code) {
            $files->writeOnce($path, $code, $o->force);
        }
        return true;
    }

    /** @return string[] '' for the upsert Input, or 'Create' and 'Update' */
    private function inputKinds(TypeInfo $info)
    {
        return $info->mutations === 'create-update' ? array('Create', 'Update') : array('');
    }

    /**
     * Why the class named by --type-base cannot be what every TypeBase extends, or null.
     * Checked before anything is loaded or written: a base that is missing would give
     * generated files that fatal on first use, which is worse than no files.
     *
     * @param string $class
     * @return string|null
     */
    private function typeBaseProblem($class)
    {
        $name = \ltrim((string) $class, '\\');
        $segment = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';
        if (!\preg_match('/^' . $segment . '(\\\\' . $segment . ')*\z/', $name)) {
            return 'is not a class name';
        }
        if (\strcasecmp($name, ModelType::class) === 0) {
            return null;
        }
        if (\interface_exists($name) || \trait_exists($name)) {
            return 'is not a class';
        }
        if (!\class_exists($name)) {
            return 'cannot be loaded: it is not a class the autoloader can find';
        }
        if (!\is_subclass_of($name, ModelType::class)) {
            return 'does not extend Anorm\GraphQL\ModelType';
        }
        if ((new \ReflectionClass($name))->isFinal()) {
            return 'is final';
        }
        return null;
    }

    /**
     * Input files on disk that this run does not produce for the entity: it became
     * read-only, or changed between upsert and create-update.
     *
     * @return string[]
     */
    private function staleInputs(TypeInfo $info)
    {
        $dir = $this->join($this->options->outputDir, $info->entity);
        $made = $info->readOnly ? array() : $this->inputKinds($info);
        $stale = array();
        foreach (array('', 'Create', 'Update') as $kind) {
            if (\in_array($kind, $made, true)) {
                continue;
            }
            foreach (array("$dir/Base/{$info->entity}{$kind}InputBase.php", "$dir/{$info->entity}{$kind}Input.php") as $path) {
                if (\file_exists($path)) {
                    $stale[] = $path;
                }
            }
        }
        return $stale;
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
