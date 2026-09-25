<?php
namespace Anorm\GraphQL\Tools;

class TypeMakerOptions
{
    /** @var string */
    public $modelsDir = 'src/Models/';
    /** @var string */
    public $modelNamespace = 'App\Models';
    /** @var string */
    public $outputDir = 'src/GraphQL/Type/';
    /** @var string */
    public $typeNamespace = 'App\GraphQL\Type';
    /** @var string|null null to write no tests */
    public $testsDir = 'tests/GraphQL/';
    /** @var string */
    public $testNamespace = 'Tests\GraphQL';
    /** @var string|null null to leave the schema alone */
    public $schemaPath = 'src/GraphQL/ApiSchema.php';
    /** @var string */
    public $schemaNamespace = 'App\GraphQL';
    /** @var string */
    public $classSuffix = 'Model';
    /** @var string Class every generated <Entity>TypeBase extends; must extend Anorm\GraphQL\ModelType */
    public $typeBase = 'Anorm\GraphQL\ModelType';
    /** @var string 'upsert', or 'create-update' for <entity>Create and <entity>Update */
    public $mutations = 'upsert';
    /** @var string[] Entity or model class short names; empty for all */
    public $only = array();
    /** @var string[] Entity or model class short names */
    public $readOnly = array();
    /** @var string[] Entity or model class short names: emit their Input(s) only */
    public $inputOnly = array();
    /** @var bool */
    public $force = false;
    /** @var bool */
    public $dryRun = false;
}
