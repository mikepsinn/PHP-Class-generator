<?php
/**********************************************************************
 * _database.class.php
 **********************************************************************/
define('TAB', chr(9));
define('RET', ' ' . chr(10) . chr(13));
define('NL', chr(13));
class Database
{
    public static $FONCTIONS_STATISTIQUES = ['AVG', 'COUNT', 'MAX', 'MIN', 'SUM', 'CONCAT', 'GROUP_CONCAT', 'DATE_FORMAT', 'ROUND'];
    public static $JOIN = 'JOIN';
    public static $HAVING = 'HAVING';
    public static $LIMIT = 'LIMIT';
    public static $OFFSET = 'OFFSET';
    public static $GROUP_BY = 'GROUP_BY';
    public static $ORDER = 'ORDER';
    public function __construct()
    {
        $this->_conn = SPDO::getInstance();
    }
    static function setParam($valeur, $operateur = '=', $table = '')
    {
        return array('valeur' => $valeur, 'operateur' => $operateur, 'table' => $table);
    }
    static function getParamValue($array = array())
    {
        return (is_array($array) ? (isset($array['valeur']) ? (is_array($array['valeur']) ? '\'' . implode('\',\'', $array['valeur']) . '\'' : $array['valeur']) : null) : $array);
    }
    static function getParamOperator($array = array())
    {
        return (is_array($array) ? (isset($array['operateur']) ? $array['operateur'] : '=') : '=');
    }
    static function getParamTable($array = array())
    {
        return (is_array($array) ? ((isset($array['table']) && $array['table'] != '') ? $array['table'] . '.' : '') : '');
    }
    static public function select($pQry, $bind_param = array(), $debug = FALSE, $pdo_param = PDO::FETCH_ASSOC)
    {
        $statement = Database::prepare($pQry, $bind_param, $debug);
        $result = $statement->execute();
        $row = array();
        if ($result === TRUE) {
            $row = $statement->fetchAll($pdo_param); //, PDO::FETCH_UNIQUE);
        }
        return $row;
    }
    public static function prepare($pQry = '', $bind_param = array(), $debug = FALSE)
    {
        $pdo = SPDO::getInstance();
        $statement = $pdo->prepare($pQry);
        if (!empty($bind_param)) {
            $debug_bindParam = Database::bindValues($statement, $bind_param, $debug);
        }
        if ($debug === TRUE) {
            ob_start();
            var_dump($statement);
            if (isset($debug_bindParam)){
                var_dump($debug_bindParam);
            }
            $debug_content = ob_get_contents() . PHP_EOL;
            ob_end_clean();
            echo '<pre>' . $debug_content . '</pre>' . PHP_EOL;
        }
        return $statement;
    }
    public static function bindValues(&$statement, $array, $debug = FALSE)
    {
        if (is_array($array)) {
            foreach ($array as $key => $value) {
                if ($debug === TRUE) {
                    $debug_output[$key] = $value;
                }
                $statement->bindValue($key, $value);
            }
        }
        return ($debug === TRUE && isset($debug_output)) ? $debug_output : TRUE;
    }
    static public function insert($pQry, $bind_param = array(), $debug = FALSE)
    {
        $statement = Database::prepare($pQry, $bind_param, $debug);
        $pdo = SPDO::getInstance();
        $statement->execute();
        return $pdo->lastInsertId();
    }
    public static function delete($pQry, $bind_param = array(), $debug = FALSE)
    {
        $statement = Database::prepare($pQry, $bind_param, $debug);
        $statement->execute();
        return $statement->rowCount();
    }
    public static function update($pQry, $bind_param = array(), $debug = FALSE)
    {
        $statement = Database::prepare($pQry, $bind_param, $debug);
        $statement->execute();
        return $statement->rowCount();
    }
}
class SPDO
{
    private static $instance = null;
    private $PDOInstance = null;
    /*
    * __construct
    *
    */
    private function __construct()
    {
        try {
            $this->PDOInstance = new PDO(dbtype . ':host=' . dbhostname . ';dbname=' . dbdatabase, dbusername, dbpassword);
            $this->PDOInstance->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, 'SET NAMES utf8; SET collation_connection = utf8_general_ci;');
            $this->PDOInstance->query('SET character_set_results = "utf8", character_set_client = "utf8", character_set_connection = "utf8", character_set_database = "utf8", character_set_server = "utf8"');
        } catch (Exception $e) {
            echo 'Error connecting to MySQL!: ' . $e->getMessage();
            exit();
        }
    }
    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new SPDO();
        }
        return self::$instance->PDOInstance;
    }
}
/**
 * Classe View
 * Date dernière génération 2016-12-09 10:23:49
 * Classe abstraite permettant de centraliser les fonctions communes à toutes les vues
 **/
abstract class View
{
    public static $LAST_QUERY = '';
    protected static $DATABASE_NAME = '';
    protected static $TABLE_NAME = '';
    protected static $PRIMARY_KEY = array();
    protected static $FIELD_NAME = array();
    protected static $FOREIGN_KEYS = array();
    protected $FIELD_MODIFIED = array();
    protected $RESULT = array();
    public function __construct($array = array(), $createRecord = FALSE, $debug = FALSE)
    {
        /* if (is_object($array)){
            foreach ($this::$FIELD_NAME AS $object_name=> $object_db_name) {
                $function_set = 'set_'.$object_name;
                $function_get = 'get_'.$object_name;
                $this->$function_set($array->$function_get());
            }
            foreach ($this::$FOREIGN_KEYS AS $object_name=> $object_db_name) {
                $function_get = 'get_FK_'.$object_db_name['TABLE_NAME'].$object_name;
                if ($array->$function_get(FALSE) !== NULL) {
                    $function_set = 'set_FK_'.$object_db_name['TABLE_NAME'].$object_name;
                    $this->$function_set($array->$function_get());
                }
            }
            $this->set_result($array->get_result());
        }*/
        if (!empty($array)) {
            $this->load($array, $createRecord, $debug);
        }
    }
    /*
    * loadArray
    *
    */
    public function load($array = array(), $createRecord = FALSE, $debug = FALSE)
    {
        return $this->loader($array, $createRecord, 'load', $debug);
    }
    /*
    * load
    *
    * Function permettant d'ecraser l'objet en cours par un objet en base de données
    * Selon les parametres passées en argument
    *
    * @param (array) tableau de parametres, dont la clef est le nom de la table en base de données et la valeur qui l'accompagne
    * Les clés peuvent aussi etre LIMIT, OFFSET, ORDER, la valeur associée étant utilisé selon la clé
    * L'utilisation d'un INNER instanciera les objects associées aux clés etrangeres automatiquement
    * @return (boolean) retourne TRUE si l'objet a pu etre récupéré en base sinon FALSE
    */
    protected function loader($array = array(), $createRecord = FALSE, $caller = 'load', $debug = FALSE)
    {
        // Selon la fonction appelante, le résultat renvoyé est différent
        // load charge un objet alors que loadArray charge un ensemble d'objets dans un tableau
        if ($caller == 'load') {
            $this->resetObject();
        } elseif ($caller == 'loadArray') {
            $className = get_called_class();
            $class_objects = array();
        }
        $retour = FALSE;
        $qry = 'SELECT ' . RET;
        // Liste de chaque colonne selon les champs de l'objet
        $precedent = '';
        foreach (static::$FIELD_NAME as $object_field => $db_field) {
            $qry .= $precedent . static::$DATABASE_NAME . '.' . static::$TABLE_NAME . '.' . $db_field . ' AS "' . static::$TABLE_NAME . '.' . $db_field . '"';
            $precedent = ',';
        }
        $qry .= RET;
        // Si l'on utilise un INNER, on rajoute chaque colonne selon la propriete field name de l'objet correspondant à la table
        if (!empty($array['JOIN']) && !empty(static::$FOREIGN_KEYS) && !is_array($array['JOIN'])) {
            foreach (static::$FOREIGN_KEYS as $key => $value) {
                $classname = $value['TABLE_NAME'];
                $object = new $classname;
                foreach ($object::$FIELD_NAME AS $foreign_object_field => $foreign_db_field) {
                    $qry .= $precedent . 'TABLE_' . $key . '.' . $foreign_db_field . ' AS "TABLE_' . $key . '.' . $foreign_object_field . '"';
                }
                $qry .= RET;
            }
        } elseif (!empty($array['JOIN']) && is_array($array['JOIN'])) {
            foreach ($array['JOIN'] as $TABLE_INFO) {
                if (!empty($TABLE_INFO['TABLE_NAME']) && !empty($TABLE_INFO['JOIN'])) {
                    $object = new $TABLE_INFO['TABLE_NAME'];
                    foreach ($object::$FOREIGN_KEYS as $key => $value) {
                        if ($value['TABLE_NAME'] == static::$TABLE_NAME) {
                            foreach ($object::$FIELD_NAME AS $foreign_object_field => $foreign_db_field) {
                                $qry .= $precedent . 'TABLE_' . $TABLE_INFO['TABLE_NAME'] . '.' . $foreign_db_field . ' AS "TABLE_' . $TABLE_INFO['TABLE_NAME'] . '.' . $foreign_object_field . '"';
                            }
                            $qry .= RET;
                        }
                    }
                }
            }
        }
        foreach (Database::$FONCTIONS_STATISTIQUES AS $fonction_statistique) {
            if (!empty($array[$fonction_statistique])) {
                $array[$fonction_statistique] = is_array($array[$fonction_statistique]) ? $array[$fonction_statistique] : array($array[$fonction_statistique] => $array[$fonction_statistique]);
                foreach ($array[$fonction_statistique] as $key => $value) {
                    $qry .= $precedent . $fonction_statistique . '(' . $value . ') AS "' . $fonction_statistique . '_' . $key . '" ';
                }
            }
        }
        $qry .= 'FROM ' . static::$DATABASE_NAME . '.' . static::$TABLE_NAME . RET;
        if (!empty($array['JOIN']) && !empty(static::$FOREIGN_KEYS) && !is_array($array['JOIN'])) {
            foreach (static::$FOREIGN_KEYS as $key => $value) {
                $qry .= $array['JOIN'] . ' JOIN ' . $value['DATABASE_NAME'] . '.' . $value['TABLE_NAME'] . ' AS TABLE_' . $key . ' ON (' . static::$TABLE_NAME . '.' . $key . '=TABLE_' . $key . '.' . $value['COLUMN_NAME'] . ')' . RET;
            }
        } elseif (!empty($array['JOIN']) && is_array($array['JOIN'])) {
            foreach ($array['JOIN'] as $TABLE_INFO) {
                if (!empty($TABLE_INFO['TABLE_NAME']) && !empty($TABLE_INFO['JOIN'])) {
                    $object = new $TABLE_INFO['TABLE_NAME'];
                    foreach ($object::$FOREIGN_KEYS as $key => $value) {
                        if ($value['TABLE_NAME'] == static::$TABLE_NAME) {
                            $qry .= $TABLE_INFO['JOIN'] . ' JOIN ' . $object::$DATABASE_NAME . '.' . $TABLE_INFO['TABLE_NAME'] . ' AS TABLE_' . $TABLE_INFO['TABLE_NAME'] . ' ON (' . $value['TABLE_NAME'] . '.' . $value['COLUMN_NAME'] . '=TABLE_' . $TABLE_INFO['TABLE_NAME'] . '.' . $key . ')' . RET;
                        }
                    }
                }
            }
        }
        $count_where = 0;
        $genere_where = self::genereWhere($array, $count_where);
        $qry .= $genere_where['where'];
        if (isset ($array['GROUP_BY'])) {
            $qry .= 'GROUP BY ' . $array['GROUP_BY'] . RET;
        }
        if (isset ($array['HAVING'])) {
            $qry .= 'HAVING ' . $array['GROUP_BY'] . RET;
        }
        if (isset ($array['ORDER'])) {
            $qry .= 'ORDER BY ' . $array['ORDER'] . RET;
        }
        if (isset ($array['LIMIT']) && isset ($array['OFFSET'])) {
            $qry .= 'LIMIT ' . $array['OFFSET'] . ',' . $array['LIMIT'] . RET;
        } elseif (isset ($array['LIMIT'])) {
            $qry .= 'LIMIT ' . $array['LIMIT'] . RET;
        } elseif (isset ($array['OFFSET'])) {
            $qry .= 'LIMIT ' . $array['OFFSET'] . ',5000' . RET;
        } elseif ($caller == 'load') {
            $qry .= 'LIMIT 1' . RET;
        }
//        echo'<pre>'.$qry.'</pre>';
        static::$LAST_QUERY = $qry;
        if (($count_where > 0) || (isset ($array['LIMIT']))) {
            $records = Database::select($qry, $genere_where['param'], $debug);
            if (is_array($records) && count($records) > 0) {
                foreach ($records as $record) {
                    if ($caller == 'load') {
                        $class_object = &$this;
                    } else {
                        $class_object = new $className();
                    }
                    foreach (static::$FIELD_NAME AS $key => $value) {
                        $function = 'set_' . $key;
                        $class_object->$function($record[static::$TABLE_NAME . '.' . $value]);
                    }
                    // Si on utilis un INNER JOIN, on instancie chaque objet correspondant a la clé etrangere
                    if (!empty($array['JOIN']) && !empty(static::$FOREIGN_KEYS) && !is_array($array['JOIN'])) {
                        foreach (static::$FOREIGN_KEYS as $foreign_key_object => $foreign_key_db) {
                            // On créé un objet correspondant au nom de la table
                            $classname = $foreign_key_db['TABLE_NAME'];
                            $object = new $classname;
                            // Pour chaque propriété de cet objet on fait un set avec la valeur récupéré du SELECT
                            foreach ($object::$FIELD_NAME AS $object_name => $object_db_name) {
                                $function = 'set_' . $object_name;
                                $object->$function($record['TABLE_' . $foreign_key_object . '.' . $object_name]);
                            }
                            // Une fois les propriétés de l'objet setté, on le set sur l'objet courant en tant que clé etrangere
                            $function = 'set_FK_' . $foreign_key_db['TABLE_NAME'] . $foreign_key_object;
                            $class_object->$function($object);
                        }
                    } elseif (!empty($array['JOIN']) && is_array($array['JOIN'])) {
                        foreach ($array['JOIN'] as $TABLE_INFO) {
                            if (!empty($TABLE_INFO['TABLE_NAME']) && !empty($TABLE_INFO['JOIN'])) {
                                $object = new $TABLE_INFO['TABLE_NAME'];
                                foreach ($object::$FOREIGN_KEYS as $key => $value) {
                                    if ($value['TABLE_NAME'] == static::$TABLE_NAME) {
                                        // Pour chaque propriété de cet objet on fait un set avec la valeur récupéré du SELECT
                                        foreach ($object::$FIELD_NAME AS $object_name => $object_db_name) {
                                            $function = 'set_' . $object_name;
                                            $object->$function($record['TABLE_' . $TABLE_INFO['TABLE_NAME'] . '.' . $object_name]);
                                        }
                                        // Une fois les propriétés de l'objet setté, on le set sur l'objet courant en tant que clé etrangere
                                        //$function = 'set_FK_'.$foreign_key_db['TABLE_NAME'].$foreign_key_object;
                                        $class_object->RESULT['TABLE_' . $TABLE_INFO['TABLE_NAME']] = $object;
                                    }
                                }
                            }
                        }
                    }
                    foreach (Database::$FONCTIONS_STATISTIQUES AS $fonction_statistique) {
                        if (!empty($array[$fonction_statistique])) {
                            foreach ($array[$fonction_statistique] as $key => $value) {
                                $class_object->RESULT[$fonction_statistique][$key] = $record[$fonction_statistique . '_' . $key];
                            }
                        }
                    }
                    $class_object->resetModifiedFields();
                    $retour = TRUE;
                    if ($caller == 'load') {
                        break;
                    } elseif ($caller == 'loadArray') {
                        // on fabrique une clé primaire en plusieurs id si la primary key n'est pas unique typiquement pour table de mapping
                        $primary_index = [];
                        foreach (static::$PRIMARY_KEY as $key => $value) {
                            $function = 'get_' . $key;
                            $primary_index[] = $class_object->$function();
                        }
                        $class_objects[implode('|', $primary_index)] = $class_object;
                    }
                }
            }
        }
        if ($caller == 'load' AND !$retour AND $createRecord AND is_subclass_of($this, Table)) {
            foreach (static::$FIELD_NAME AS $key => $value) {
                if ($array[$key] != '') {
                    $function = 'set_' . $key;
                    static::$function($array[$key]);
                }
            }
            $this->save();
        }
        return ($caller == 'load') ? $retour : $class_objects;
    }
    /*
    * loader
    *
    * Fonction générique utilisée par les fonctions load et loadArray
    */
    protected function resetObject()
    {
        foreach (static::$FIELD_NAME AS $key => $value) {
            $function = 'set_' . $key;
            $this->$function(NULL);
        }
        foreach (static::$FOREIGN_KEYS AS $key => $value) {
            $function = 'set_FK_' . $value['TABLE_NAME'] . $key;
            $this->$function(NULL);
        }
    }
    /*
    * resetModifiedFields
    *
    * Function permettant de reseter la liste des champs modifiés
    *
    */
    protected static function genereWhere($where = array(), &$count_where)
    {
        $result['where'] = '';
        $result['param'] = array();
        $condition = array();
        if (!empty($where)) {
            $i = 1;
            foreach ($where AS $value => $key) {
                if (Database::getParamValue($key) != '' && $value != 'JOIN' && $value != 'LIMIT' && $value != 'OFFSET' && $value != 'ORDER') {
                    if (array_key_exists($value, static::$FIELD_NAME)) {
                        $value = static::$FIELD_NAME[$value];
                    }
                    $param_key = str_replace('.', '', Database::getParamTable($key) . $value);
                    if (in_array(Database::getParamOperator($key), ['IN', 'NOT IN'])) {
                        $in_value = array();
                        foreach (Database::getParamValue($key) as $value) {
                            $result['param'][':' . $param_key . '_' . $i] = $value;
                            $in_value[] = ':' . $param_key . '_' . $i;
                            $i++;
                        }
                        $condition[] = ' ' . Database::getParamTable($key) . $value . ' ' . Database::getParamOperator($key) . ' (' . implode(',', $in_value) . ')' . RET;
                    } else {
                        $condition[] = ' ' . Database::getParamTable($key) . $value . ' ' . Database::getParamOperator($key) . ':' . $param_key . '_' . $i . RET;
                        $result['param'][':' . $param_key . '_' . $i] = Database::getParamValue($key);
                        $i++;
                    }
                    $count_where++;
                }
            }
            if (!empty($condition)) {
                $result['where'] .= 'WHERE' . RET . implode(' AND ', $condition);
            }
        }
        return $result;
    }
    /*
    * resetObject
    *
    * Function permettant de reseter la liste des champs modifiés
    *
    */
    protected function resetModifiedFields()
    {
        $this->FIELD_MODIFIED = array();
    }
    public static function loadArray($array = array(), $debug = FALSE)
    {
        $className = get_called_class();
        $class_object = new $className();
        return $class_object->loader($array, FALSE, 'loadArray', $debug);
    }
    /*
    * get_result
    *
    * Function permettant de récupérer les informations de résultats (COUNT, MAX, MIN..)
    *
    */
    public function get_result($string = '')
    {
        if ($string != '')
            return $this->RESULT[$string];
        else
            return $this->RESULT;
    }
    /*
    * set_result
    *
    * Function permettant de setter les informations de résultats (COUNT, MAX, MIN..)
    *
    */
    public function set_result($array)
    {
        $this->RESULT = $array;
    }
    public function display()
    {
        ob_start();
        var_dump($this);
        $content = ob_get_contents();
        ob_end_clean();
        return $content;
    }
}
/**
 * Classe Table
 * Date dernière génération 2016-12-09 10:23:49
 * Classe abstraite permettant de centraliser les fonctions communes à toutes les tables
 **/
class Table extends View
{
    const SAVE_REPLACE = 0;
    const SAVE_UPDATE = 1;
    const SAVE_INSERT = 2;
    /*
    * save
    *
    * Function permettant de sauvegarder l'objet courant en base de données. La/les clef(s) primaire(s) sont utilisées dans le WHERE du UPDATE et comme condition pour savoir si la ligne existe en base de données, s'ils existent on fait un UPDATE sinon un INSERT
    *
    * @param (constant SAVE_INSERT SAVE_REPLACE SAVE_UPDATE) parametre optionnel, permet de forcer l'utilisation d'un UPDATE au lieu du INSERT. Par default ce champs est a FALSE
    */
    public static function delete($array = array(), $debug = FALSE)
    {
        if (!empty($array)) {
            $qry = 'DELETE' . RET . 'FROM ' . static::$DATABASE_NAME . '.' . static::$TABLE_NAME . RET;
            $count_where = 0;
            $genere_where = self::genereWhere($array, $count_where);
            $qry .= $genere_where['where'];
            if (isset ($array['LIMIT'])) {
                $qry .= 'LIMIT ' . Database::getParamValue($array['LIMIT']) . RET;
            }
            if ($count_where > 0 || isset ($array['LIMIT'])) {
                Database::delete($qry, $genere_where['param'], $debug);
                return TRUE;
            } else {
                return FALSE;
            }
        } else {
            return FALSE;
        }
    }
    /*
    * delete
    *
    * Function statique permettant de supprimer en base de données
    * des lignes selon les parametres passées en argument
    *
    * @param (array) tableau de parametres, dont la clef est le nom de la table en base de données et la valeur qui l'accompagne
    * Les clés peuvent aussi etre LIMIT, OFFSET, ORDER, la valeur associée étant utilisé selon la clé
    * L'utilisation d'un INNER instanciera les objects associées aux clés etrangeres automatiquement
    */
    public static function update($where = array(), $values = array(), $debug = FALSE)
    {
        if (!empty($where) && !empty($values)) {
            $qry = 'UPDATE' . RET . static::$DATABASE_NAME . '.' . static::$TABLE_NAME . RET;
            if (!empty($where['JOIN']) && !empty(static::$FOREIGN_KEYS) && !is_array($where['JOIN'])) {
                foreach (static::$FOREIGN_KEYS as $key => $value) {
                    $qry .= $where['JOIN'] . ' JOIN ' . $value['DATABASE_NAME'] . '' . $value['TABLE_NAME'] . ' AS TABLE_' . $key . ' ON (' . static::$TABLE_NAME . '.' . $key . '= TABLE_' . $key . '.' . $value['COLUMN_NAME'] . ')' . RET;
                }
            } elseif (!empty($where['JOIN']) && is_array($where['JOIN'])) {
                foreach ($where['JOIN'] as $TABLE_INFO) {
                    if (!empty($TABLE_INFO['TABLE_NAME']) && !empty($TABLE_INFO['JOIN'])) {
                        $object = new $TABLE_INFO['TABLE_NAME'];
                        foreach ($object::$FOREIGN_KEYS as $key => $value) {
                            if ($value['TABLE_NAME'] == static::$TABLE_NAME) {
                                $qry .= $TABLE_INFO['JOIN'] . ' JOIN ' . $object::$DATABASE_NAME . '.' . $TABLE_INFO['TABLE_NAME'] . ' AS TABLE_' . $TABLE_INFO['TABLE_NAME'] . ' ON (' . $value['TABLE_NAME'] . '.' . $value['COLUMN_NAME'] . '=TABLE_' . $TABLE_INFO['TABLE_NAME'] . '.' . $key . ')' . RET;
                            }
                        }
                    }
                }
            }
            $qry .= 'SET' . RET;
            $and = '';
            $count_set = 0;
            foreach (static::$FIELD_NAME AS $key => $value) {
                if (isset($values[$key])) {
                    $qry .= $and . ' ' . $value . '="' . $values[$key] . '"' . RET;
                    $and = ',' . RET;
                    $count_set++;
                }
            }
            $count_where = 0;
            $genere_where = self::genereWhere($where, $count_where);
            $qry .= $genere_where['where'];
            if (isset ($where['LIMIT'])) {
                $qry .= 'LIMIT ' . Database::getParamValue($where['LIMIT']) . RET;
            }
            if ($count_where > 0 AND $count_set > 0) {
                Database::update($qry, $genere_where['param'], $debug);
                return TRUE;
            } else {
                return FALSE;
            }
        } else {
            return FALSE;
        }
    }
    /*
    * update
    *
    * Function statique permettant de mettre à jour en base de données
    * des lignes selon les parametres passées en argument
    *
    * @param (array) tableau de parametres pour le where, dont la clef est le nom de la colonne en base de données et la valeur qui l'accompagne par la fonction Database::setParam( valeur, operatueur, table)
    * @param (array) tableau de parametres contant les valeurs à ecrire, dont la clef est le nom de la colonne en base de données et la valeur qui l'accompagne
    *
    * CLT_commande::update(
    *              // WHERE
    *                    [   'JOIN' => 'INNER',
    *                        'id_client' => Database::setParam( $BDD_client['id_client'], '=', 'TABLE_id_panier'),
    *                        'id_statut' => Database::setParam( '1', '=', 'CLT_commande')
    *                    ],
    *              // SET
    *                    [ 'id_devise' =>  getIdMonnaie($ConfSite['cnf_deviseFacturation']) ]
    *                );
    */
    public function save($debug = FALSE, $update = self::SAVE_UPDATE)
    {
        // recherche si un clé primaire a été mis à jour
        $primaryKeys_setted = 0;
        $param = array();
        foreach (static::$PRIMARY_KEY AS $key => $value) {
//			$function = 'get_'.$key;
//			$primaryKeys_setted = ($this->$function() != '') ? $primaryKeys_setted : FALSE;
            $primaryKeys_setted += (array_key_exists($key, $this->FIELD_MODIFIED)) ? 1 : 0;
        }
        // si c'est le cas on force le mode INSERT
        if ($primaryKeys_setted > 0) {
            $update = self::SAVE_INSERT;
        }
        switch ($update) {
            case self::SAVE_REPLACE :
                $qry = 'REPLACE INTO ' . static::$DATABASE_NAME . '.' . static::$TABLE_NAME . ' (';
                break;
            case self::SAVE_INSERT :
                $qry = 'INSERT INTO ' . static::$DATABASE_NAME . '.' . static::$TABLE_NAME . ' (';
                break;
            default:
                $qry = 'INSERT INTO ' . static::$DATABASE_NAME . '.' . static::$TABLE_NAME . ' (';
                break;
        }
        // définition des champs de la requête
        $lst_field = array();
        foreach (static::$FIELD_NAME as $key => $field_value) {
            $function = 'get_' . $key;
            $value = static::$function();
            if ($value != '') {
                $lst_field[] = $field_value;
            }
        }
        $qry .= implode(', ', $lst_field) . ')' . RET . ' VALUES (';
        // définition des valeurs de la requête
        $lst_value = array();
        foreach (static::$FIELD_NAME as $key => $field_value) {
            $function = 'get_' . $key;
            $value = static::$function();
            if ($value != '') {
                $param[':' . $field_value] = $value;
                $lst_value[] = ':' . $field_value;
            }
        }
        $qry .= implode(', ', $lst_value) . ')' . RET;
        // gestion de la fin de la requête
        switch ($update) {
            case self::SAVE_REPLACE:
            case self::SAVE_INSERT:
                $qry .= ';';
                break;
            default:
                $lst_update = array();
                // on fait le tour des clés
                foreach (static::$FIELD_NAME as $key => $field_value) {
                    // on ne traite que les valeurs qui ne sont pas dans la primary key
                    if (!array_key_exists($key, static::$PRIMARY_KEY)) {
                        if (array_key_exists($key, $this->FIELD_MODIFIED)) {
                            $lst_update[] = ' ' . $field_value . ' = :' . $field_value;
                        }
                    }
                }
                if (count($lst_update) > 0) {
                    $qry .= ' ON DUPLICATE KEY UPDATE ' . RET;
                    $qry .= implode(', ', $lst_update);
                }
                $qry .= ';';
                break;
        }
        if (count(static::$PRIMARY_KEY) == 1) {
            reset(static::$PRIMARY_KEY);
            $function = 'set_' . key(static::$PRIMARY_KEY);
            $result_id = Database::insert($qry, $param, $debug);
            if ($result_id != 0) {
                $this->$function($result_id);
            }
        } else {
            Database::insert($qry, $param, $debug);
        }
        $this->resetModifiedFields();
        return $this;
    }
}