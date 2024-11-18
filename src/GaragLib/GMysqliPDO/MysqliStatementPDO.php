<?php

namespace GaragLib\MysqliPDO;

class MysqliStatementPDO implements \IteratorAggregate {

    private $stmt;
    private $result;
    private $isStmt;
    private $fetchMode;
    private $classnameOrFunc;
    private $ctorargs;
    private $boundParams = [];

    public function __construct($resource, $isStmt, $fetchMode = \PDO::FETCH_ASSOC, $classnameOrFunc = null, $ctorargs = []) {
        if ($isStmt) {
            $this->stmt = $resource;
        } else {
            $this->result = $resource;
        }
        $this->isStmt = $isStmt;
        $this->fetchMode = $fetchMode;
        $this->classnameOrFunc = $classnameOrFunc;
        $this->ctorargs = $ctorargs;
    }

    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null) {
        $this->boundParams[$param] = &$var;
        return true;
    }

    public function bindValue($param, $value, $type = \PDO::PARAM_STR) {
        $this->boundParams[$param] = $value;
        return true;
    }

    public function closeCursor() {
        if ($this->isStmt) {
            $this->stmt->free_result();
        }
        return true;
    }

    public function columnCount() {
        if ($this->isStmt) {
            return $this->stmt->field_count;
        } else {
            return $this->result->field_count;
        }
    }

    public function debugDumpParams() {
        echo "Query: " . ($this->isStmt ? $this->stmt->sqlstate : $this->result->sqlstate) . "\n";
        echo "Params: ";
        print_r($this->boundParams);
        return true;
    }

    public function errorCode() {
        return $this->isStmt ? $this->stmt->errno : $this->result->errno;
    }

    public function errorInfo() {
        if ($this->isStmt) {
            return [$this->stmt->errno, $this->stmt->sqlstate, $this->stmt->error];
        } else {
            return [$this->result->errno, $this->result->sqlstate, $this->result->error];
        }
    }

    public function execute($params = null) {
        if (!$this->isStmt) {
            throw new \Exception('Execute is only applicable for prepared statements');
        }

        if ($params !== null) {
            foreach ($params as $key => $value) {
                $this->bindParam($key, $value);
            }
        }

        $types = '';
        $values = [];
        foreach ($this->boundParams as $param => $value) {
            $types .= $this->getType($value);
            $values[] = &$this->boundParams[$param];
        }

        if (!empty($values)) {
            array_unshift($values, $types);
            call_user_func_array([$this->stmt, 'bind_param'], $values);
        }

        if (!$this->stmt->execute()) {
            throw new \Exception('Execute failed: ' . $this->stmt->error);
        }

        $this->result = $this->stmt->get_result();
        return true;
    }

    private function getType($var) {
        if (is_int($var)) {
            return 'i';
        } elseif (is_double($var)) {
            return 'd';
        } elseif (is_string($var)) {
            return 's';
        } else {
            return 'b';
        }
    }

    public function fetchColumn($column = 0) {
        $row = $this->result->fetch_row();
        return $row ? $row[$column] : false;
    }

    public function fetchObject($class = "stdClass", $ctorargs = []) {
        return $this->result->fetch_object($class, $ctorargs);
    }

    public function getAttribute($name) {
        // Not applicable for mysqli_stmt
        return null;
    }

    public function getColumnMeta($column) {
        $meta = $this->result->fetch_field_direct($column);
        return $meta ? (array) $meta : false;
    }

    public function getIterator() {
        return new \ArrayIterator($this->fetchAll());
    }

    public function nextRowset() {
        // Not applicable for mysqli_stmt
        return false;
    }

    public function rowCount() {
        return $this->isStmt ? $this->stmt->affected_rows : $this->result->num_rows;
    }

    public function fetchAll($fetchMode = null, $classnameOrFunc = null, ?array $ctorargs = []) {
        if ($fetchMode !== null) {
            $this->fetchMode = $fetchMode;
        }
        if ($classnameOrFunc !== null) {
            $this->classnameOrFunc = $classnameOrFunc;
        }
        if (!empty($ctorargs)) {
            $this->ctorargs = $ctorargs;
        }

        $rows = [];
        switch ($this->fetchMode) {
            case \PDO::FETCH_ASSOC:
                $rows = $this->result->fetch_all(MYSQLI_ASSOC);
                break;
            case \PDO::FETCH_NUM:
                $rows = $this->result->fetch_all(MYSQLI_NUM);
                break;
            case \PDO::FETCH_BOTH:
                $rows = $this->result->fetch_all(MYSQLI_BOTH);
                break;
            case \PDO::FETCH_OBJ:
                while ($row = $this->result->fetch_object()) {
                    $rows[] = $row;
                }
                break;
            case \PDO::FETCH_COLUMN:
                while ($row = $this->result->fetch_row()) {
                    $rows[] = $row[$this->classnameOrFunc];
                }
                break;
            case \PDO::FETCH_CLASS:
                while ($row = $this->result->fetch_assoc()) {
                    $rows[] = $this->fetchClass($row);
                }
                break;
            case \PDO::FETCH_FUNC:
                while ($row = $this->result->fetch_assoc()) {
                    $rows[] = call_user_func($this->classnameOrFunc, $row);
                }
                break;
            default:
                throw new \Exception('Invalid fetch mode');
        }
        return $rows;
    }

    public function fetch($fetchMode = null, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0) {
        if ($fetchMode === null) {
            $fetchMode = $this->fetchMode;
        }
        switch ($fetchMode) {
            case \PDO::FETCH_ASSOC:
                return $this->result->fetch_assoc();
            case \PDO::FETCH_NUM:
                return $this->result->fetch_row();
            case \PDO::FETCH_BOTH:
                return $this->result->fetch_array(MYSQLI_BOTH);
            case \PDO::FETCH_OBJ:
                return $this->result->fetch_object();
            case \PDO::FETCH_COLUMN:
                $row = $this->result->fetch_row();
                return $row ? $row[$this->classnameOrFunc] : false;
            case \PDO::FETCH_FUNC:
                return $this->fetchFunc();
            case \PDO::FETCH_LAZY:
                return $this->fetchLazy();
            case \PDO::FETCH_BOUND:
                throw new \Exception('FETCH_BOUND is not supported');
            case \PDO::FETCH_NAMED:
                return $this->fetchNamed();
            case \PDO::FETCH_KEY_PAIR:
                return $this->fetchKeyPair();
            case \PDO::FETCH_UNIQUE:
                return $this->fetchUnique();
            case \PDO::FETCH_GROUP:
                return $this->fetchGroup();
            case \PDO::FETCH_CLASS:
                return $this->fetchClass();
            case \PDO::FETCH_CLASSTYPE:
                return $this->fetchClassType();
            case \PDO::FETCH_INTO:
                return $this->fetchInto();
            default:
                throw new \Exception('Invalid fetch mode');
        }
    }

    private function fetchClass($row = null) {
        if ($row === null) {
            $row = $this->result->fetch_assoc();
        }
        if ($row === null) {
            return false;
        }
        $reflectionClass = new \ReflectionClass($this->classnameOrFunc);
        return $reflectionClass->newInstanceArgs(array_merge([$row], $this->ctorargs));
    }

    private function fetchFunc() {
        $row = $this->result->fetch_assoc();
        if ($row === null) {
            return false;
        }
        return call_user_func($this->classnameOrFunc, $row);
    }

    private function fetchLazy() {
        $row = $this->result->fetch_assoc();
        if ($row === null) {
            return false;
        }
        return (object) $row;
    }

    private function fetchNamed() {
        $row = $this->result->fetch_assoc();
        if ($row === null) {
            return false;
        }
        return array_merge($row, $row);
    }

    private function fetchKeyPair() {
        $rows = [];
        while ($row = $this->result->fetch_row()) {
            $rows[$row[0]] = $row[1];
        }
        return $rows;
    }

    private function fetchUnique() {
        $rows = [];
        while ($row = $this->result->fetch_assoc()) {
            foreach ($row as $key => $value) {
                $rows[$key] = $value;
            }
        }
        return $rows;
    }

    private function fetchGroup() {
        $rows = [];
        while ($row = $this->result->fetch_assoc()) {
            foreach ($row as $key => $value) {
                $rows[$key][] = $value;
            }
        }
        return $rows;
    }

    private function fetchClassType() {
        $row = $this->result->fetch_assoc();
        if ($row === null) {
            return false;
        }
        $class = $row[$this->classnameOrFunc];
        $reflectionClass = new \ReflectionClass($class);
        return $reflectionClass->newInstanceArgs(array_merge([$row], $this->ctorargs));
    }

    private function fetchInto() {
        $row = $this->result->fetch_assoc();
        if ($row === null) {
            return false;
        }
        foreach ($row as $key => $value) {
            $this->classnameOrFunc->$key = $value;
        }
        return $this->classnameOrFunc;
    }
}

$mysqli = new \mysqli("localhost", "my_user", "my_password", "world");

/* comprobar la conexión */
if ($mysqli->connect_errno) {
    printf("Falló la conexión: %s\n", $mysqli->connect_error);
    exit();
}

/* Crear una tabla que no devuelve un conjunto de resultados */
if ($mysqli->query("CREATE TEMPORARY TABLE myCity LIKE City") === TRUE) {
    printf("Se creó con éxtio la tabla myCity.\n");
}

/* Consultas de selección que devuelven un conjunto de resultados */
if ($resultado = $mysqli->query("SELECT Name FROM City LIMIT 10")) {
    printf("La selección devolvió %d filas.\n", $resultado->num_rows);

    /* liberar el conjunto de resultados */
    $resultado->close();
}

// es mejor copiar los resultdos a variables php para que php libere la memoria
// sin es un resultado muy grande puede interesarnos no hacer copia
$mysqli->store_result(MYSQLI_STORE_RESULT_COPY_DATA);

/* Si se ha de recuperar una gran cantidad de datos se emplea MYSQLI_USE_RESULT */
if ($resultado = $mysqli->query("SELECT * FROM City", MYSQLI_USE_RESULT)) {

    /* Observar que no se puede ejecutar ninguna función que interactue con el
      servidor hasta que el conjunto de resultados se haya cerrado. Todas las llamadas devolverán un
      error 'out of sync' */
    if (!$mysqli->query("SET @a:='esto no funcionará'")) {
        printf("Error: %s\n", $mysqli->error);
    }
    $resultado->close();
}

$mysqli->close();
