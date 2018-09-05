<?php
require_once(__DIR__ . '/../../../slim/vendor/autoload.php');
/**********************************************************************
 * ClassGenerator.class.php
 **********************************************************************/
use Illuminate\Support\Pluralizer;
use Quantimodo\Api\Model\StringHelper;
use Quantimodo\Api\Model\Swagger\SwaggerDefinition;
use Quantimodo\Api\Model\Swagger\SwaggerDefinitionProperty;
use Quantimodo\Api\Model\Swagger\SwaggerJson;
use Quantimodo\Api\Model\Swagger\SwaggerPathMethod;
use Quantimodo\Api\Model\Swagger\SwaggerResponseDefinition;
define('PERMISSION_EXCEPTION', 'Permission error : No permission to write on ' . CLASSGENERATOR_DIR . '.');
define('SERVER_EXCEPTION', 'Host error : Enter a valid host.');
define('BASE_EXCEPTION', 'Database error : Enter a valid database.');
define('AUTH_EXCEPTION', 'Authentication error : Enter a valid user name and password.');
class ClassGenerator
{
    private $exception;
    private $str_replace = array('-');
    private $str_replace_column = array(' ', '-');
    private $skip_table = array();
    private $columnsInfo;
    private $columns;
    private $foreignKeys;
    private $swaggerJson;
    private $tableName;
    private $routeContent ='';
    public function __construct(){
        $this->swaggerJson = SwaggerJson::get();
        $this->generateClasses($this->getTables());
        SwaggerJson::updateSwaggerJsonFile($this->getSwaggerJson());
        $this->createRoutesFile();
    }
    /**
     * @return string
     */
    private function getClassName():string {
        $tableName = $this->getTableName();
        $className = str_replace($this->str_replace, '', $tableName);
        $className = preg_replace('/[0-9]+/', '', $className);
        $className = StringHelper::singularize($className);
        $className = StringHelper::camelize($className);
        $className = ucfirst($className);
        $className = str_replace('WpBp', '', $className);
        $className = str_replace('Metum', 'Meta', $className);
        $className = str_replace('etum', 'meta', $className);
        return $className;
    }
    private function generateClasses($tables){
        foreach ($tables as $tableName => $table_type) {
            if(stripos($tableName, 'meta') !== false){continue;}
            $this->tableName = $tableName;
            if (!in_array($tableName, $this->skip_table)) {
                if(stripos($tableName, '_bp_') === false){continue;}
                $this->setColumns($tableName);
                $this->setColumnsInfo($tableName);
                $this->setForeignKeys($tableName);
                $content = $this->addHeader($tableName, $table_type);
                $content = $this->addVariables($tableName, $content);
                //$content .= TAB.'public function __construct($array = array()) {'.PHP_EOL;
                //$content .= TAB.TAB.'if (!empty($array)) { $this = '.$class.'::readArray($array); }'.PHP_EOL;
                //$content .= TAB.'}'.PHP_EOL.PHP_EOL;
                $content = $this->addSetterFunctions($content);
                $content = $this->addGetterFunctions($content);
                $filePath = $this->getFilePath($tableName, '/');
                $className = $this->getClassName();
                $this->createClassFile($filePath, $content, $className);  // Write file
                $this->createControllerFile($className, 'get');
                $this->createControllerFile($className, 'post');
                $this->createResponseFile($className, 'get');
                $this->createResponseFile($className, 'post');
                $this->createControllerTestFile($className);
                $this->createModelTestFile($className);
            }
        }
    }
    private function getTableComment($table){
        $result = Database::select('SHOW TABLE STATUS WHERE NAME="' . $table . '"');
        foreach ($result as $key => $column) {
            if (!empty($column['Comment'])) {
                return $column['Comment'];
            } else
                return '';
        }
        return '';
    }
    private function setColumns($table){
        $result = Database::select('SHOW COLUMNS FROM `' . $table . '`');
        $this->columns = [];
        foreach ($result as $key => $column)
            $this->columns[$key] = $column['Field'];
        return $this->columns;
    }
    private function setColumnsInfo($table){
        $result = Database::select('SHOW FULL COLUMNS FROM `' . $table . '`');
        $columns = [];
        foreach ($result as $key => $column) {
            $columns[$column['Field']]['Comment'] = $column['Comment'];
            $columns[$column['Field']]['Type'] = $column['Type'];
        }
        return $this->columnsInfo = $columns;
    }
    private function setForeignKeys($table){
        $result = Database::select('SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME = :table', [':table' => $table]);
        $this->foreignKeys = [];
        foreach ($result as $key => $column) {
            if ($column['REFERENCED_TABLE_SCHEMA'] == dbdatabase)
                $this->columns[$column['COLUMN_NAME']] = str_replace($this->str_replace, '', $column['REFERENCED_TABLE_NAME']);
        }
        return $this->foreignKeys;
    }
    /**
     * @param $table
     * @return array
     */
    private function getForeignKeyTable($table){
        $result = Database::select('SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME = :table', [':table' => $table]);
        $columns = [];
        foreach ($result as $key => $column) {
            if ($column['REFERENCED_TABLE_SCHEMA'] == dbdatabase) {
                //$this->columns[$column['COLUMN_NAME']] = $column['REFERENCED_TABLE_SCHEMA'].'.'.$column['REFERENCED_TABLE_NAME'].'.'.$column['REFERENCED_COLUMN_NAME'];
                $columns[$column['COLUMN_NAME']]['TABLE_NAME'] = $column['REFERENCED_TABLE_NAME'];
                $columns[$column['COLUMN_NAME']]['COLUMN_NAME'] = $column['REFERENCED_COLUMN_NAME'];
                $columns[$column['COLUMN_NAME']]['DATABASE_NAME'] = $column['REFERENCED_TABLE_SCHEMA'];
            }
        }
        return $columns;
    }
    public function getPrimaryKeys($table){
        $result = Database::select('SHOW COLUMNS FROM `' . $table . '`');
        $pKeys = [];
        foreach ($result as $key => $column) {
            if ($column['Key'] == 'PRI') {
                $pKeys[$key] = $column['Field'];
            }
        }
        return $pKeys;
    }
    private function mapMysqlTypeWithPhpType($type){
        if (strpos($type, 'int') !== FALSE) {
            return 'int';
        } elseif (strpos($type, 'float') !== FALSE) {
            return 'float';
        } elseif (strpos($type, 'decimal') !== FALSE) {
            return 'float';
        } elseif (strpos($type, 'bit(1)') !== FALSE) {
            return 'boolean';
        } else {
            return 'string';
        }
    }
    private function createClassFile($nameSpace, $text_to_save, $className){
        $directory = '/vagrant/slim/'.$nameSpace;
        $this->writeToFile($directory, $className . '.php', $text_to_save);
    }
    private function writeToFile($directory, $fileName, $content){
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }
        chmod($directory, 0777);
        $filePath = $directory.'/'.$fileName;
        if (!file_exists($filePath))
            if (!touch($filePath))
                $this->exception = PERMISSION_EXCEPTION;
            else
                chmod($filePath, 0777);
        $fp = fopen($filePath, 'w');
        fwrite($fp, $content);
        return fclose($fp);
    }
    private function createRoutesFile(){
        $directory = '/vagrant';
        $this->writeToFile($directory, 'routes', $this->routeContent);
    }
    private function createControllerFile($className, $method){
        $directory = '/vagrant/slim/Api/Controller/'.$className;
        $controllerName = $this->getControllerName($method);
        $this->writeToFile($directory, ucfirst($method) .$className . 'Controller.php',
            '<?php
namespace Quantimodo\Api\Controller\\'.$className.';
use Quantimodo\Api\Controller\\'.ucfirst($method).'Controller;
class '.$controllerName.' extends '. ucfirst($method).'Controller
{
    public function '. $method.'(){
        $this->getApp()->setCacheControlHeader(60);
        $this->writeJsonWithGlobalFields(200, new '.ucfirst($method).$className.'Response());
    }
}');
    }
    /**
     * @return string
     */
    public function getTableName()
    {
        return $this->tableName;
    }
    /**
     * @param string $tableName
     */
    public function setTableName($tableName)
    {
        $this->tableName = $tableName;
    }
    /**
     * @return string
     */
    private function getPluralCamelCaseClassName(){
        $camel = $this->getCamelCaseClassName();
        $plural = Pluralizer::plural($camel);
        return $plural;
    }
    /**
     * @return string
     */
    private function getPluralTitleCaseClassName(){
        $class = $this->getClassName();
        $plural = Pluralizer::plural($class);
        return $plural;
    }
    private function getCamelCaseClassName(){
        return StringHelper::camelize($this->getClassName());
    }
    private function createResponseFile($className, $method){
        $directory = '/vagrant/slim/Api/Controllers/'.$className;
        $responseClassName = ucfirst($method) . $className . 'Response';
        $filePath = $responseClassName . '.php';
        $this->writeToFile($directory, $filePath, '<?php
namespace Quantimodo\Api\Controller\\'.$className.';
use Quantimodo\Api\Model\QMResponseBody;
use Quantimodo\Api\Model\\'.$className.';
class '.$responseClassName.' extends QMResponseBody {
    public $'.$this->getPluralCamelCaseClassName().';
    public function __construct(){
        $this->'.$this->getPluralCamelCaseClassName().' = '.$className.'::get();
        parent::__construct();
    }
}');
    }
    private function createControllerTestFile($className){
        $directory = '/vagrant/slim/tests/Api/Controllers';
        $testClassName = $className . 'ControllerTest';
        $filePath = $testClassName . '.php';
        $content = '<?php
namespace QuantimodoTest\Api\Controllers;
use QuantimodoTest\Api\QMTestCase;
/**
 * Class '.$testClassName.'
 * @package QuantimodoTest\Api\Controllers
 */
class '.$testClassName.' extends QMTestCase
{
    /**
     * List of fixture files
     *
     * @var string[]
     */
    protected $fixtureFiles = [
        \'wp_usermeta\' => \'common/wp_usermeta.xml\',
        \'wp_users\' => \'common/wp_users.xml\',
    ];
    /**
     * @group Controllers
     */
    public function testPostAndGet'.$className.'(){
        $implemented = false;
        if(!$implemented){
            $this->markTestSkipped("Test not yet implemented");
            return;
        }' . PHP_EOL;
        $content .= TAB . TAB . '$this->setAuthenticatedUser(1);' . PHP_EOL;
        $content .= TAB . TAB . '$postData = $this->getPostData();' . PHP_EOL;
        $content .= TAB . TAB . '$body = $this->postAndGetDecodedBody(\'v1/'.StringHelper::camelize($className).'\', $postData);' . PHP_EOL;
        $content .= TAB . TAB . '$this->assertCount(1, $body->'.$this->getPluralCamelCaseClassName().');' . PHP_EOL;
        $content .= TAB . TAB . '$body = $this->getAndDecodeBody(\'v1/'.StringHelper::camelize($className).'\');' . PHP_EOL;
        $content .= TAB . TAB . '$this->assertCount(1, $body->'.$this->getPluralCamelCaseClassName().');'. PHP_EOL;
        $content .= TAB . TAB . '$this->assertEquals($postData, $body->'.$this->getPluralCamelCaseClassName().'[0]);'. PHP_EOL;
        $content .= TAB . '}' . PHP_EOL;
        $content .= TAB . '/**'. PHP_EOL;
        $content .= TAB . "* @return mixed". PHP_EOL;
        $content .= TAB . '*/'. PHP_EOL;
        $content .= TAB . 'public function getPostData() {' . PHP_EOL;
        $content .= TAB . TAB . '$data = \''. PHP_EOL;
        $content .= TAB . TAB . '' . PHP_EOL;
        $content .= TAB . TAB . '\';' . PHP_EOL;
        $content .= TAB . TAB . 'return json_decode($data);' . PHP_EOL;
        $content .= TAB . '}' . PHP_EOL;
        $content .= '}' . PHP_EOL;
        $this->writeToFile($directory, $filePath, $content);
    }
    private function createModelTestFile($className){
        $directory = '/vagrant/slim/tests/Api/Model';
        $testClassName = $className . 'ModelTest';
        $filePath = $testClassName . '.php';
        $this->writeToFile($directory, $filePath, '<?php
namespace QuantimodoTest\Api\Model;
use QuantimodoTest\Api\QMTestCase;
/**
 * Class '.$testClassName.'
 * @package QuantimodoTest\Api\Model
 */
class '.$testClassName.' extends QMTestCase
{
    /**
     * List of fixture files
     *
     * @var string[]
     */
    protected $fixtureFiles = [
        \'wp_usermeta\' => \'common/wp_usermeta.xml\',
        \'wp_users\' => \'common/wp_users.xml\',
    ];
    /**
     * @group Model
     */
    public function testSaveAndGet'.$className.'(){
        $implemented = false;
        if(!$implemented){
            $this->markTestSkipped("Test not yet implemented");
            return;
        }
        $'.StringHelper::camelize($className).' = new '.$className.'();
        $result = $'.StringHelper::camelize($className).'->insertOrUpdate();
        $this->assertEquals(1, $result);
        $gotten = '.$className.'::get();
        $this->assertCount(1, $gotten);
    }
}');
    }
    private function getTables()
    {
        $result = Database::select('SHOW FULL TABLES');
        $tables = [];
        foreach ($result as $key => $table) {
            $tables[$table['Tables_in_' . dbdatabase]] = $table['Table_type'];
        }
        return $tables;
    }
    public function getException()
    {
        return $this->exception;
    }
    /**
     * @param string $columnName
     * @return string
     */
    private function getPHPType(string $columnName):string {
        return $this->mapMysqlTypeWithPhpType($this->columnsInfo[$columnName]['Type']);
    }
    /**
     * @param $content
     * @return string
     */
    private function addSetterFunctions($content): string
    {
        /***********************************************************************
         * SETTERS
         ************************************************************************/
        foreach ($this->columns as $columnName) {
            $str_column = str_replace($this->str_replace_column, '', $columnName);
            $type = $this->getPHPType($str_column);
            $functionName = 'set_' . $str_column;
            $functionName = StringHelper::camelize($functionName);
            $camel = StringHelper::camelize($str_column);
            $content .= TAB . '/**'. PHP_EOL;
            $content .= TAB . "* @param $type $camel". PHP_EOL;
            $content .= TAB . "* @return $type". PHP_EOL;
            $content .= TAB . '*/'. PHP_EOL;
            $content .= TAB . 'public function ' . $functionName . '(' . $type . ' $' . $camel . ') {' . PHP_EOL;
            $content .= TAB . TAB . '$originalValue = $this->'.$camel.';'. PHP_EOL;
            $content .= TAB . TAB . 'if ($originalValue !== $' . $camel . '){' . PHP_EOL;
            $content .= TAB . TAB . TAB . '$this->modifiedFields[\'' . $str_column . '\'] = 1;' . PHP_EOL;
            $content .= TAB . TAB . '}' . PHP_EOL;
            $content .= TAB . TAB . 'return $this->' . $camel . ' = $' . $camel . ';' . PHP_EOL;
            $content .= TAB . '}' . PHP_EOL;
            if (!empty($this->foreignKeys[$columnName])) {
                $content .= TAB . 'protected function set_FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) .
                    '($pArg=\'0\') {$this->FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . '=$pArg; }' . PHP_EOL;
            }
        }
        return $content;
    }
    /**
     * @param $tableName
     * @param $content
     * @return string
     */
    private function addVariables($tableName, $content): string
    {
        /***********************************************************************
         * VARIABLES
         ************************************************************************/
        $list_columns = [];
        $foreignKeyTable = $this->getForeignKeyTable($tableName);
        $pKeys = $this->getPrimaryKeys($tableName);
        //$content .= TAB . 'public static $DATABASE_NAME = \'' . dbdatabase . '\';' . PHP_EOL;
        $content .= TAB . 'const TABLE = \'' . $tableName . '\';' . PHP_EOL;
        $and = '';
        $primary_key = '';
        foreach ($pKeys as $key => $pKey) {
            $str_column = str_replace($this->str_replace_column, '', $pKey);
            $primary_key .= $and . '\'' . $str_column . '\'=>' . '\'' . $pKey . '\'';
            $and = ',';
        }
        $content .= TAB . 'public static $PRIMARY_KEY = [' . $primary_key . '];' . PHP_EOL;
        $and = '';
        $columns_name = '';
        //$this->columns_modified = '';
        foreach ($this->columns as $key => $value) {
            $content .= TAB . "const FIELD_$value = '$value';" . PHP_EOL;
            $str_column = str_replace($this->str_replace_column, '', $value);
            $columns_name .= $and . '\'' . $str_column . '\'=>' . '\'' . $value . '\'';
            //$this->columns_modified .= $and.'\''.$value.'\'=>0';
            $and = ',';
        }
        $content .= TAB . 'public static $FIELD_NAME = [' . $columns_name . '];' . PHP_EOL;
        //$content .= TAB . 'protected $FIELD_MODIFIED = [];' . PHP_EOL;
        //$content .= TAB . 'protected $RESULT = [];' . PHP_EOL;
        $content .= TAB . 'protected static $FOREIGN_KEYS = [';
        if (!empty($foreignKeyTable)) {
            $and = '';
            foreach ($this->columns as $columnName) {
                if (!empty($foreignKeyTable[$columnName])) {
                    $content .= $and . '\'' . $columnName . '\' => [\'TABLE_NAME\'=>\'' . $foreignKeyTable[$columnName]['TABLE_NAME'] . '\', \'COLUMN_NAME\'=>\'' . $foreignKeyTable[$columnName]['COLUMN_NAME'] . '\', \'DATABASE_NAME\'=>\'' . $foreignKeyTable[$columnName]['DATABASE_NAME'] . '\']';
                    $and = ',';
                }
            }
        }
        $content .= '];'. PHP_EOL ;
        $swaggerDefinition = new SwaggerDefinition(StringHelper::camelize($tableName));
        foreach ($this->columns as $columnName) {
            $str_column = str_replace($this->str_replace_column, '', $columnName);
            $camel = StringHelper::camelize($str_column);
            $swaggerProperty = new SwaggerDefinitionProperty($camel);
            $type = $this->getPHPType($str_column);
            $swaggerProperty->setType($type);
            $description = "What do you expect?";
            if (!empty($this->columnsInfo[$columnName]['Comment'])) {
                $description = utf8_encode($this->columnsInfo[$columnName]['Comment']);
                $content .= TAB . '/**' . PHP_EOL;
                $content .= TAB . ' * @var ' . $description . PHP_EOL;
                $content .= TAB . ' */' . PHP_EOL;
            }
            $swaggerProperty->setDescription($description);
            $content .= TAB . 'public $' . $camel . ';' . PHP_EOL;
            if (!empty($this->foreignKeys[$columnName])) {
                $content .= TAB . 'protected $FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . ';' . PHP_EOL;
            }
            $swaggerDefinition->required[] = $camel;
            $swaggerProperty->unsetNullFields();
            $swaggerDefinition->properties->$camel = $swaggerProperty;
            $list_columns[] = $columnName;
        }
        $metaProperty = new SwaggerDefinitionProperty();
        $className = $this->getClassName();
        $metaProperty->setDescription("Additional ".strtolower($className)." key-value data");
        $metaProperty->setArrayItemsType("object");
        $metaProperty->unsetNullFields();
        $swaggerDefinition->properties->{'metaDataArray'} = $metaProperty;
        $swaggerJson = $this->getSwaggerJson();
        $swaggerDefinition->unsetNullFields();
        $swaggerJson->definitions->$className = $swaggerDefinition;
        $responseName = $this->getPluralTitleCaseClassName().'Response';
        $swaggerJson->definitions->$responseName = new SwaggerResponseDefinition($className);
        $pluralCamel = Pluralizer::plural(StringHelper::camelize($className));
        $pathName = '/v3/'.$pluralCamel;
        $this->addRoutes($pluralCamel);
        if(!isset($swaggerJson->paths->$pathName)){$swaggerJson->paths->$pathName = new stdClass();}
        $swaggerJson->paths->$pathName->get = new SwaggerPathMethod("get", $className);
        $swaggerJson->paths->$pathName->post = new SwaggerPathMethod("post", $className);
        return $content;
    }
    private function addRoutes(){
        $this->routeContent .= TAB . TAB . '['. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_METHOD => HttpRequest::METHOD_GET,'. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_PATH => \'/v1/'.$this->getPluralCamelCaseClassName().'\','. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_AUTH => false,'. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_AUTH_SCOPE => \'\','. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_CONTROLLER => \''.$this->getClassName().'\\\\'.$this->getControllerName('get').'\''. PHP_EOL;
        $this->routeContent .= TAB . TAB . '],'. PHP_EOL;
        $this->routeContent .= TAB . TAB . '['. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_METHOD => HttpRequest::METHOD_POST,'. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_PATH => \'/v1/'.$this->getPluralCamelCaseClassName().'\','. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_AUTH => false,'. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_AUTH_SCOPE => \'\','. PHP_EOL;
        $this->routeContent .= TAB . TAB . TAB . 'self::FIELD_CONTROLLER => \''.$this->getClassName().'\\\\'.$this->getControllerName('post').'\''. PHP_EOL;
        $this->routeContent .= TAB . TAB . '],'. PHP_EOL;
    }
    /**
     * @param $content
     * @return string
     */
    private function addGetterFunctions(string $content): string
    {
        /***********************************************************************
         * GETTERS
         ************************************************************************/
        foreach ($this->columns as $columnName) {
            $str_column = str_replace($this->str_replace_column, '', $columnName);
            $functionName = 'get_' . $str_column;
            $type = $this->getPHPType($columnName);
            $camel = StringHelper::camelize($str_column);
            $functionName = StringHelper::camelize($functionName);
            $content .= TAB . '/**'. PHP_EOL;
            $content .= TAB . "* @return $type". PHP_EOL;
            $content .= TAB . '*/'. PHP_EOL;
            $content .= TAB . 'public function ' . $functionName . '(): ' . $type . ' {' . PHP_EOL;
            $content .= TAB . TAB . '$'. $camel.' = $this->'.$camel.';'. PHP_EOL;
            $content .= TAB . TAB . 'return (' . $type . ') $' . $camel .';' . PHP_EOL;
            $content .= TAB . '}' . PHP_EOL;
            if (!empty($this->foreignKeys[$columnName])) {
                $content .= TAB . 'public function get_FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . '($force_get=TRUE) { ';
                $content .= 'if ($this->FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . '!== null || $force_get === FALSE) { return $this->FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . '; } else {';
                $content .= '$this->FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . ' = new ' . $this->foreignKeys[$columnName] . '();';
                $content .= '$this->FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . '->load(array(self::$FOREIGN_KEYS[\'' . $columnName . '\'][\'COLUMN_NAME\'] => $this->' . $columnName . '));';
                $content .= 'return $this->FK_' . $this->foreignKeys[$columnName] . str_replace($this->str_replace, '', $columnName) . '; } }' . PHP_EOL;
            }
        }
        $content .= PHP_EOL;
        $content .= '}' . PHP_EOL;
        return $content;
    }
    private function getFilePath(string $tableName, $delimiter):string {
        $nameSpace = 'Api'.$delimiter.'Model';
        if(stripos('_bp_', $tableName) !== false){
            $nameSpace .= $delimiter.'WP'.$delimiter.'BP';
        } elseif (stripos('_pmpro_', $tableName) !== false){
            $nameSpace .= $delimiter.'WP'.$delimiter.'PMPRO';
        } elseif (stripos('wp_', $tableName) !== false){
            $nameSpace .= $delimiter.'WP';
        }
        return $nameSpace;
    }
    /**
     * @param $tableName
     * @param $table_type
     * @return string
     */
    private function addHeader($tableName, $table_type): string {
        $className = $this->getClassName();
        $this->str_replace_column = $tableName == 'produit' ? [' ', '-'] : array(' ', 'fld_', '-');
        $content = '<?php' . PHP_EOL ;
        $nameSpace = 'namespace Quantimodo\\'.$this->getFilePath($tableName, '\\');
        $content .= $nameSpace.';' . PHP_EOL ;
        $comment = $this->getTableComment($tableName);
        if(!empty($comment)){
            $content .= '/**' . PHP_EOL;
            //$content .= ' * ' . str_replace($this->str_replace_file, '', $table) . '.class.php' . PHP_EOL;
            if(!empty($comment)){$content .= ' * ' . $comment . PHP_EOL;}
            $content .= ' **/' . PHP_EOL;
        }
        /***********************************************************************
         * CLASS
         ************************************************************************/
        $type = ($table_type == 'BASE TABLE') ? 'QMModel' : 'View';
        $content .= 'class ' . $className . ' extends ' . $this->getBaseModelName() . ' {' . PHP_EOL;
        return $content;
    }
    private function getBaseModelName():string{
        foreach ($this->columns as $columnName){
            if($columnName === 'user_id'){
                return "UserRelatedModel";
            }
            return "QMModel";
        }
    }
    /**
     * @return SwaggerJson
     */
    public function getSwaggerJson(){
        return $this->swaggerJson;
    }
    /**
     * @param string $method
     * @return string
     */
    private function getControllerName(string $method){
        return ucfirst($method).$this->getClassName().'Controller';
    }
}