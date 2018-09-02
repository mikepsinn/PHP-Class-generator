<?php
require_once(__DIR__ . '/../../../slim/vendor/autoload.php');
/**********************************************************************
 * ClassGenerator.class.php
 **********************************************************************/
use Quantimodo\Api\Model\StringHelper;
define('PERMISSION_EXCEPTION', 'Permission error : No permission to write on ' . CLASSGENERATOR_DIR . '.');
define('SERVER_EXCEPTION', 'Host error : Enter a valid host.');
define('BASE_EXCEPTION', 'Database error : Enter a valid database.');
define('AUTH_EXCEPTION', 'Authentication error : Enter a valid user name and password.');
class ClassGenerator
{
    private $exception;
    private $str_replace = array('-');
    private $str_replace_file = array();
    private $str_replace_column = array(' ', '-');
    private $skip_table = array();
    private $columnsInfo;
    private $columns;
    private $foreignKeys;   
    public function ClassGenerator(){
        $this->generateClasses($this->getTables());
    }
    private function generateClasses($tables)
    {
        foreach ($tables as $table => $table_type) {
            if (!in_array($table, $this->skip_table)) {
                $prefix = ($table_type == 'BASE TABLE') ? '' : 'V_';
                $content = $this->addHeader($table, $table_type);
                $this->setColumns($table);
                $this->setColumnsInfo($table);
                $this->setForeignKeys($table);
                $content = $this->addVariables($table, $content);
                //$content .= TAB.'public function __construct($array = array()) {'.NL;
                //$content .= TAB.TAB.'if (!empty($array)) { $this = '.$class.'::readArray($array); }'.NL;
                //$content .= TAB.'}'.NL.NL;
                $content = $this->addSetterFunctions($content);
                $content .= NL;
                $content = $this->addGetterFunctions($content);
                $this->createClassFile($prefix . str_replace($this->str_replace_file, '', $table), $content);  // Write file
            }
        }
    }
    private function getTableComment($table)
    {
        $result = Database::select('SHOW TABLE STATUS WHERE NAME="' . $table . '"');
        foreach ($result as $key => $column) {
            if (!empty($column['Comment'])) {
                return $column['Comment'];
            } else
                return '';
        }
        return '';
    }
    private function setColumns($table)
    {
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
    private function setForeignKeys($table)
    {
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
    public function getPrimaryKeys($table)
    {
        $result = Database::select('SHOW COLUMNS FROM `' . $table . '`');
        $pKeys = array();
        foreach ($result as $key => $column) {
            if ($column['Key'] == 'PRI') {
                $pKeys[$key] = $column['Field'];
            }
        }
        return $pKeys;
    }
    private function mapMysqlTypeWithPhpType($type)
    {
        if (strpos($type, 'int') !== FALSE) {
            return 'integer';
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
    private function createClassFile($file_to_save, $text_to_save)
    {
        $file = CLASSGENERATOR_DIR . $file_to_save . '.class.php';
        chmod(CLASSGENERATOR_DIR, 0777);
        if (!file_exists($file))
            if (!touch($file))
                $this->exception = PERMISSION_EXCEPTION;
            else
                chmod($file, 0777);
        $fp = fopen($file, 'w');
        fwrite($fp, $text_to_save);
        fclose($fp);
    }
    private function getTables()
    {
        $result = Database::select('SHOW FULL TABLES');
        $tables = array();
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
     * @param $content
     * @return string
     */
    private function addSetterFunctions($content): string 
    {
        /***********************************************************************
         * SETTERS
         ************************************************************************/
        foreach ($this->columns as $column) {
            $str_column = str_replace($this->str_replace_column, '', $column);
            $type = $this->mapMysqlTypeWithPhpType($this->columnsInfo[$column]['Type']);
            $functionName = 'set_' . $str_column;
            $functionName = StringHelper::camelize($functionName);
            $camel = StringHelper::camelize($str_column);
            $content .= TAB . '/**'. NL;
            $content .= TAB . "* @param $type $camel". NL;
            $content .= TAB . "* @return $type". NL;
            $content .= TAB . '*/'. NL;
            $content .= TAB . 'public function ' . $functionName . '(' . $type . ' $' . $camel . ') {' . NL;
            $content .= TAB . TAB . '$originalValue = $this->'.$camel.';'. NL;
            $content .= TAB . TAB . 'if ($originalValue !== $' . $camel . '){' . NL;
            $content .= TAB . TAB . TAB . '$this->modifiedFields[\'' . $str_column . '\'] = 1;' . NL;
            $content .= TAB . TAB . '}' . NL;
            $content .= TAB . TAB . 'return $this->' . $camel . ' = $' . $camel . ';' . NL;
            $content .= TAB . '}' . NL;
            if (!empty($this->foreignKeys[$column])) {
                $content .= TAB . 'protected function set_FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) .
                    '($pArg=\'0\') {$this->FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . '=$pArg; }' . NL;
            }
        }
        return $content;
    }
    /**
     * @param $table
     * @param $content
     * @return string
     */
    private function addVariables($table, $content): string
    {
        /***********************************************************************
         * VARIABLES
         ************************************************************************/
        $list_columns = [];
        $foreignKeyTable = $this->getForeignKeyTable($table);
        $pKeys = $this->getPrimaryKeys($table);
        //$content .= TAB . 'public static $DATABASE_NAME = \'' . dbdatabase . '\';' . NL;
        $content .= TAB . 'const TABLE = \'' . $table . '\';' . NL;
        $and = '';
        $primary_key = '';
        foreach ($pKeys as $key => $pKey) {
            $str_column = str_replace($this->str_replace_column, '', $pKey);
            $primary_key .= $and . '\'' . $str_column . '\'=>' . '\'' . $pKey . '\'';
            $and = ',';
        }
        $content .= TAB . 'public static $PRIMARY_KEY = [' . $primary_key . '];' . NL;
        $and = '';
        $columns_name = '';
        //$this->columns_modified = '';
        foreach ($this->columns as $key => $value) {
            $content .= TAB . "const FIELD_$value = '$value'" . NL;
            $str_column = str_replace($this->str_replace_column, '', $value);
            $columns_name .= $and . '\'' . $str_column . '\'=>' . '\'' . $value . '\'';
            //$this->columns_modified .= $and.'\''.$value.'\'=>0';
            $and = ',';
        }
        $content .= TAB . 'public static $FIELD_NAME = [' . $columns_name . '];' . NL;
        //$content .= TAB . 'protected $FIELD_MODIFIED = array();' . NL;
        //$content .= TAB . 'protected $RESULT = array();' . NL;
        $content .= TAB . 'protected static $FOREIGN_KEYS = [';
        if (!empty($foreignKeyTable)) {
            $and = '';
            foreach ($this->columns as $column) {
                if (!empty($foreignKeyTable[$column])) {
                    $content .= $and . '\'' . $column . '\'=>array(\'TABLE_NAME\'=>\'' . $foreignKeyTable[$column]['TABLE_NAME'] . '\', \'COLUMN_NAME\'=>\'' . $foreignKeyTable[$column]['COLUMN_NAME'] . '\', \'DATABASE_NAME\'=>\'' . $foreignKeyTable[$column]['DATABASE_NAME'] . '\']';
                    $and = ',';
                }
            }
        }
        $content .= '];'. NL ;
        foreach ($this->columns as $column) {
            if (!empty($this->columnsInfo[$column]['Comment'])) {
                $content .= TAB . '/**' . NL;
                $content .= TAB . ' * @var ' . utf8_encode($this->columnsInfo[$column]['Comment']) . NL;
                $content .= TAB . ' */' . NL;
            }
            $str_column = str_replace($this->str_replace_column, '', $column);
            $camel = StringHelper::camelize($str_column);
            $content .= TAB . 'public $' . $camel . ';' . NL;
            if (!empty($this->foreignKeys[$column])) {
                $content .= TAB . 'protected $FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . ';' . NL;
            }
            $list_columns[] = $column;
        }
        return $content;
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
        foreach ($this->columns as $column) {
            $str_column = str_replace($this->str_replace_column, '', $column);
            $functionName = 'get_' . $str_column;
            $type = $this->mapMysqlTypeWithPhpType($this->columnsInfo[$column]['Type']);
            $camel = StringHelper::camelize($str_column);
            $functionName = StringHelper::camelize($functionName);
            $content .= TAB . '/**'. NL;
            $content .= TAB . "* @return $type". NL;
            $content .= TAB . '*/'. NL;
            $content .= TAB . 'public function ' . $functionName . '(): ' . $type . ' {' . NL;
            $content .= TAB . TAB . '$'. $camel.' = $this->'.$camel.';'. NL;
            $content .= TAB . TAB . 'return (' . $type . ') $' . $camel .';' . NL;
            $content .= TAB . '}' . NL;
            if (!empty($this->foreignKeys[$column])) {
                $content .= TAB . 'public function get_FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . '($force_get=TRUE) { ';
                $content .= 'if ($this->FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . '!== null || $force_get === FALSE) { return $this->FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . '; } else {';
                $content .= '$this->FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . ' = new ' . $this->foreignKeys[$column] . '();';
                $content .= '$this->FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . '->load(array(self::$FOREIGN_KEYS[\'' . $column . '\'][\'COLUMN_NAME\'] => $this->' . $column . '));';
                $content .= 'return $this->FK_' . $this->foreignKeys[$column] . str_replace($this->str_replace, '', $column) . '; } }' . NL;
            }
        }
        $content .= NL;
        $content .= '}' . NL;
        return $content;
    }
    /**
     * @param $table
     * @param $table_type
     * @return string
     */
    private function addHeader($table, $table_type): string
    {
        $class = str_replace($this->str_replace, '', $table);
        $class = preg_replace('/[0-9]+/', '', $class);
        $class = StringHelper::singularize($class);
        $class = ucfirst($class);
        $this->str_replace_column = $table == 'produit' ? [' ', '-'] : array(' ', 'fld_', '-');
        $content = '<?php' . NL ;
        $comment = $this->getTableComment($table);
        if(!empty($comment)){
            $content .= '/**' . NL;
            //$content .= ' * ' . str_replace($this->str_replace_file, '', $table) . '.class.php' . NL;
            if(!empty($comment)){$content .= ' * ' . $this->getTableComment($table) . NL;}
            $content .= ' **/' . NL;
        }
        /***********************************************************************
         * CLASS
         ************************************************************************/
        $type = ($table_type == 'BASE TABLE') ? 'QMModel' : 'View';
        $content .= 'class ' . $class . ' extends ' . $type . ' {' . NL;
        return $content;
    }
}
