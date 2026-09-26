#!/usr/bin/env php
<?php
namespace Anorm\GraphQL\Tools;

define('ANORM_GRAPHQL_VERSION', '0.3.0');

// Installed as a dependency, relative to vendor/saygoweb/anorm-graphql/bin
if (\file_exists(__DIR__ . '/../../../autoload.php')) {
    require_once(__DIR__ . '/../../../autoload.php');
// A checkout of this repository
} elseif (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once(__DIR__ . '/../vendor/autoload.php');
} else {
    echo 'anorm-graphql Error: Failed to load vendor/autoload.php' . PHP_EOL;
    exit(1);
}

use Anorm\Tools\CliOptions;
use cli\Arguments;

class App
{
    const TITLE = 'anorm-graphql: GraphQL Types from Anorm models';

    /** @var string|null */
    public $command = null;

    /** @var Arguments */
    public $options;

    /** @var string[] Arguments that are neither the command nor an option this tool has */
    public $unexpected = array();

    /**
     * @param string[] $argv Without the program name
     */
    public function __construct(array $argv)
    {
        $arguments = new Arguments(array('strict' => false, 'input' => CliOptions::splitAssignments($argv)));
        $arguments->addFlag(array('help', 'h'), 'Display this help');
        $arguments->addFlag('version', 'Display the version');
        $arguments->addFlag(array('force', 'f'), 'Also overwrite the once-only files');
        $arguments->addFlag('dry-run', 'Show what would change; write nothing');
        $defaults = new TypeMakerOptions();
        $arguments->addOption(array('models', 'm'), array('default' => $defaults->modelsDir, 'description' => 'Models folder'));
        $arguments->addOption(array('namespace', 'n'), array('default' => $defaults->modelNamespace, 'description' => 'Namespace of the models'));
        $arguments->addOption(array('output', 'o'), array('default' => $defaults->outputDir, 'description' => 'Folder for generated Types'));
        $arguments->addOption(array('type-ns', 't'), array('default' => $defaults->typeNamespace, 'description' => 'Namespace for generated Types'));
        $arguments->addOption('tests', array('default' => $defaults->testsDir, 'description' => "Folder for generated tests; 'none' to skip"));
        $arguments->addOption('test-ns', array('default' => $defaults->testNamespace, 'description' => 'Namespace for generated tests'));
        $arguments->addOption(array('schema', 's'), array('default' => $defaults->schemaPath, 'description' => "Path to ApiSchema.php; 'none' to skip"));
        $arguments->addOption('schema-ns', array('default' => $defaults->schemaNamespace, 'description' => 'Namespace when scaffolding a new ApiSchema'));
        $arguments->addOption(array('classsuffix', 'c'), array('default' => $defaults->classSuffix, 'description' => 'Model suffix to strip'));
        $arguments->addOption('type-base', array('default' => $defaults->typeBase, 'description' => 'Class every generated TypeBase extends'));
        $arguments->addOption('mutations', array('default' => $defaults->mutations, 'description' => 'upsert, or create-update for separate mutations'));
        $arguments->addOption('only', array('default' => '', 'description' => 'Comma-separated model names to include'));
        $arguments->addOption('readonly', array('default' => '', 'description' => 'Comma-separated models to emit without Input or mutations'));
        $arguments->addOption('input-only', array('default' => '', 'description' => 'Comma-separated models to emit as Input only'));
        $arguments->addOption(
            'without-update',
            array('default' => '', 'description' => 'Comma-separated models with no Update (create-update only)')
        );
        $arguments->addOption('without-delete', array('default' => '', 'description' => 'Comma-separated models with no Delete mutation'));
        $arguments->parse();
        $this->options = $arguments;
        $positional = $arguments->getInvalidArguments();
        if (\count($positional) >= 1) {
            $this->command = \array_shift($positional);
        }
        // The parser is lenient: a misspelt option lands here, and its default would be
        // used without a word. Writing to the default folder by accident is not lenient.
        $this->unexpected = $positional;
    }

    /** @return int The process exit code */
    public function run()
    {
        if ($this->options['help']) {
            echo self::TITLE . PHP_EOL . PHP_EOL . 'Usage: anorm-graphql make [options]' . PHP_EOL . PHP_EOL;
            echo $this->options->getHelpScreen() . PHP_EOL;
            return 0;
        }
        if ($this->options['version']) {
            echo ANORM_GRAPHQL_VERSION . PHP_EOL;
            return 0;
        }
        if ($this->unexpected) {
            echo self::TITLE . PHP_EOL;
            \printf("Error: Unexpected argument '%s', try '--help'\n", $this->unexpected[0]);
            return 2;
        }
        if ($this->command !== 'make') {
            echo self::TITLE . PHP_EOL;
            \printf("Error: Unknown command '%s', try '--help'\n", (string) $this->command);
            return 2;
        }
        try {
            $maker = new TypeMaker($this->makerOptions());
            $code = $maker->run();
        } catch (\Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
            return 2;
        }
        echo \implode(PHP_EOL, $maker->report) . PHP_EOL;
        return $code;
    }

    /** @return TypeMakerOptions */
    public function makerOptions()
    {
        $o = new TypeMakerOptions();
        $o->modelsDir = $this->options['models'];
        $o->modelNamespace = $this->options['namespace'];
        $o->outputDir = $this->options['output'];
        $o->typeNamespace = $this->options['type-ns'];
        $o->testsDir = $this->options['tests'] === 'none' ? null : $this->options['tests'];
        $o->testNamespace = $this->options['test-ns'];
        $o->schemaPath = $this->options['schema'] === 'none' ? null : $this->options['schema'];
        $o->schemaNamespace = $this->options['schema-ns'];
        $o->classSuffix = $this->options['classsuffix'];
        $o->typeBase = (string) $this->options['type-base'];
        $o->mutations = (string) $this->options['mutations'];
        $o->only = $this->names($this->options['only']);
        $o->readOnly = $this->names($this->options['readonly']);
        $o->inputOnly = $this->names($this->options['input-only']);
        $o->withoutUpdate = $this->names($this->options['without-update']);
        $o->withoutDelete = $this->names($this->options['without-delete']);
        $o->force = (bool) $this->options['force'];
        $o->dryRun = (bool) $this->options['dry-run'];
        return $o;
    }

    /** @return string[] */
    private function names($list)
    {
        return \array_values(\array_filter(\array_map('trim', \explode(',', (string) $list)), 'strlen'));
    }
}

$app = new App(isset($_SERVER['argv']) ? \array_slice($_SERVER['argv'], 1) : array());
exit($app->run());
