<?php

class MysqliStatementPDO {

    public static $LOG = false;
    public $stm = null;
    public $args = null;
    public $binds = null;

    public function __construct($statement, $arguments) {
        $this->stm = $statement;
        $this->args = $arguments;
    }

    public function __call($name, $arguments) {
        try {
            if (MiPDOStatement::$LOG && $name == 'execute') {
                file_put_contents(__DIR__ . '/' . MiPDOStatement::$LOG . MiPDO::$FLAG_SQLERROR . '.log', '[' . date("Y-m-d H:i:s") . '][' . $name . ']: ' . "\r\n START " . print_r($this->args, true) . "\r\n" . print_r($arguments, true) . "\r\n" . print_r($this->binds, true), LOCK_EX | FILE_APPEND);
            }
            $ret = call_user_func_array([$this->stm, $name], $arguments);
            if (MiPDOStatement::$LOG && $name == 'execute') {
                file_put_contents(__DIR__ . '/' . MiPDOStatement::$LOG . MiPDO::$FLAG_SQLERROR . '.log', '[' . date("Y-m-d H:i:s") . '][' . $name . ']: ' . "\r\n END \r\n", LOCK_EX | FILE_APPEND);
            }
            return $ret;
        } catch (\PDOException $ex) {
            file_put_contents(__DIR__ . '/ERRORSQL' . MiPDO::$FLAG_SQLERROR . '.log', 'ERROR[' . date("Y-m-d H:i:s") . ']: ' . $ex->getMessage() . "\r\n" . $ex->getTraceAsString() . "\r\n" . print_r($this->args, true) . "\r\n" . print_r($arguments, true) . "\r\n" . print_r($this->binds, true), LOCK_EX | FILE_APPEND);
            throw new \MiPDOException($ex->getMessage(), $ex->getCode());
        }
    }

    public static function __callStatic($name, $arguments) {
        return call_user_func_array('\PDOStatement::' . $name, $arguments);
    }

    public function bindParam($parameter, & $variable, $data_type = \PDO::PARAM_STR, $length = null, $options = null) {
        if ($this->binds == null)
            $this->binds = [];
        $this->binds[$parameter] = $variable;
        return $this->stm->bindParam($parameter, $variable, $data_type, $length, $options);
    }

    public function bindValue($parameter, $variable, $data_type = \PDO::PARAM_STR) {
        if ($this->binds == null)
            $this->binds = [];
        $this->binds[$parameter . '-' . $data_type] = $variable;
        return $this->stm->bindValue($parameter, $variable, $data_type);
    }

}
