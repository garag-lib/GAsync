<?php

class MysqliPDO {

    /**
     * @var mysqli
     */
    private $mysqli = null;
    private $dbhost = null,
            $dbport = null,
            $dbuser = null,
            $dbpass = null,
            $dbname = null,
            $maxIntentos = null,
            $maxConnections = null;

    public function __construct($dbhost, $dbport, $dbuser, $dbpass, $dbname, $maxIntentos = null, $maxConnections = null) {
        $this->dbhost = $dbhost;
        $this->dbport = $dbport;
        $this->dbuser = $dbuser;
        $this->dbpass = $dbpass;
        $this->dbname = $dbname;
        if ($maxIntentos)
            $this->maxIntentos = $maxIntentos;
        if ($maxConnections)
            $this->maxConnections = $maxConnections;
    }

    public function __destruct() {
        $this->close();
    }

    public function close() {
        if ($this->mysqli) {
            $this->mysqli->close();
            $this->mysqli = null;
            $this->unlook();
        }
    }

    private function unlook() {
        if ($this->maxConnections) {
            $clave = md5(strtoupper($this->dbhost . '-' . $this->dbport . '-' . $this->dbname));
            //MiSemafoto::liberar('PDO' . $clave);
        }
    }

    private function look() {
        if ($this->maxConnections) {
            $clave = md5(strtoupper($this->dbhost . '-' . $this->dbport . '-' . $this->dbname));
        }
    }

    public function connect() {
        //---
        $actual_fiber = \Fiber::getCurrent();
        //---        
        if (!is_null($this->mysqli))
            return null;
        $intentoActual = 0;
        do {
            try {
                //---
                $dbhost = $this->dbhost;
                $dbport = $this->dbport;
                $dbuser = $this->dbuser;
                $dbpass = $this->dbpass;
                $dbname = $this->dbname;
                //---
                $this->look();
                //---
                $this->mysqli = mysqli_init();
                $this->mysqli->options(MYSQLI_REPORT_ALL, 1);
                //$this->mysqli->options(MYSQLI_REPORT_STRICT, 1);
                //$connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
                //$connection->options(MYSQLI_OPT_READ_TIMEOUT, 3);
                //$connection->options(MYSQLI_INIT_COMMAND, '');
                $this->mysqli->real_connect($this->dbhost, $this->dbuser, $this->dbpass, $this->dbname, $this->dbport);
                //$this->mysqli->set_charset("utf8mb4");
                //---
            } catch (\Exception $e) {
                $this->mysqli = null;
                if ($this->maxIntentos) {
                    $intentoActual++;
                    if ($intentoActual >= $this->maxIntentos) {
                        if ($actual_fiber) {
                            $actual_fiber->throw($e);
                        } else {
                            throw $e;
                        }
                    }
                    if ($actual_fiber) {
                        \Fiber::suspend();
                    } else {
                        sleep(1);
                    }
                } else {
                    if ($actual_fiber) {
                        $actual_fiber->throw($e);
                    } else {
                        throw $e;
                    }
                }
            }
        } while (is_null($this->mysqli));
    }

    public function prepare($sql) {
        $this->connect();
        $result = $this->mysqli->prepare($sql);
        return new \MiPDOStatement($result);
    }

    public function query($sql, int $fetchMode = null, string $classname = null, array $ctorargs = null) {
        $this->connect();
        $result = $this->mysqli->query($query);
        return new \MiPDOStatement($result);
    }

    public function __call($name, $arguments) {
        if (substr($name, 0, 6) == 'async_') {
            $method = substr($name, 6);
            return new \Fiber([$this, $method]);
        }
        /*
          $this->connect();
          switch ($name) {
          case 'beginTransaction':
          break;
          case 'commit':
          break;
          case 'rollBack':
          break;
          case 'errorCode':
          break;
          case 'errorInfo':
          break;
          case 'exec':
          break;
          case 'inTransaction':
          break;
          case 'lastInsertId ':
          break;
          case 'quote':
          break;
          }
         */
    }

    public static function __callStatic($name, $arguments) {
        switch ($name) {
            case '':
                break;
        }
    }

    /*

      public function multiExecute(...$sqls) {
      if ($this->emulatePrepares === null) {
      if ($this->pdo == null)
      $this->connect();
      $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
      $this->pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);
      $this->emulatePrepares = true;
      }
      $sql = implode(";\r\n", $sqls);
      $this->exec($sql);
      }

      public function multiUpdate($sql_update, $arrRows, $keyArray = null, $defaultArr = null) {
      if ($arrRows && count($arrRows)) {
      if ($this->emulatePrepares === null) {
      if ($this->pdo == null)
      $this->connect();
      $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
      $this->pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);
      $this->emulatePrepares = true;
      }

      $numlineas = count($arrRows);
      $numporlinea = count($arrRows[0]);
      if ($keyArray)
      $numporlinea = count($keyArray);
      $numparametros = $numporlinea * $numlineas;

      $lineasporconsulta = $numlineas;
      if ($numparametros > static::$MAXNUMPARAMS) {
      $lineasporconsulta = intval(static::$MAXNUMPARAMS / $numporlinea);
      if ($lineasporconsulta <= 0)
      $lineasporconsulta = 1;
      }

      $stmt = null;

      while (count($arrRows)) {
      $arrRowsResult = array_splice($arrRows, $lineasporconsulta);
      $sql = implode(";\r\n", array_fill(0, count($arrRows), $sql_update));
      $stmt = $this->prepare($sql);
      if ($stmt) {
      if ($keyArray === null) {
      //------------------------------------------------------
      $cont = 0;
      foreach ($arrRows as $row) {
      foreach ($row as $val) {
      if ($val === null) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_NULL);
      continue;
      }
      if (is_int($val)) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_INT);
      continue;
      }
      $stmt->bindValue(++$cont, $val);
      }
      }
      $stmt->execute();
      } else {
      $newparamarr = [];
      $paramArr = [];
      foreach ($arrRows as $row) {
      foreach ($keyArray as $index => $key) {
      if (is_array($row)) {
      $paramArr[$index] = isset($row[$key]) ? $row[$key] : ( ( is_array($defaultArr) && isset($defaultArr[$key]) ) ? $defaultArr[$key] : null );
      } else {
      $paramArr[$index] = isset($row->{$key}) ? $row->{$key} : ( ( is_array($defaultArr) && isset($defaultArr[$key]) ) ? $defaultArr[$key] : null );
      }
      }
      $newparamarr[] = $paramArr;
      }
      //------------------------------------------------------
      $cont = 0;
      foreach ($newparamarr as $row) {
      foreach ($row as $val) {
      if ($val === null) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_NULL);
      continue;
      }
      if (is_int($val)) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_INT);
      continue;
      }
      $stmt->bindValue(++$cont, $val);
      }
      }
      $stmt->execute();
      }
      }
      $arrRows = $arrRowsResult;
      }

      return true;
      }

      return true;
      }

      public function multiInsert($sql_insert, $sql_params, $arrRows, $keyArray = null) {
      return $this->multiInsertUpdate($sql_insert, $sql_params, null, $arrRows, $keyArray);
      }

      public function multiInsertUpdate($sql_insert, $sql_params, $sql_update = null, $arrRows, $keyArray = null, $defaultArr = null) {
      if ($arrRows && count($arrRows)) {
      if ($this->emulatePrepares === null) {
      if ($this->pdo == null)
      $this->connect();
      $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
      $this->emulatePrepares = true;
      }

      $numlineas = count($arrRows);
      $numporlinea = count($arrRows[0]);
      if ($keyArray)
      $numporlinea = count($keyArray);
      $numparametros = $numporlinea * $numlineas;

      $lineasporconsulta = $numlineas;
      if ($numparametros > static::$MAXNUMPARAMS) {
      $lineasporconsulta = intval(static::$MAXNUMPARAMS / $numporlinea);
      if ($lineasporconsulta <= 0)
      $lineasporconsulta = 1;
      }

      $stmt = null;

      while (count($arrRows)) {
      $arrRowsResult = array_splice($arrRows, $lineasporconsulta);
      $sql = $sql_insert . ' VALUES ' . implode(',', array_fill(0, count($arrRows), $sql_params)) . ( $sql_update ? ( ' ' . $sql_update ) : $sql_update );
      $stmt = $this->prepare($sql);
      if ($stmt) {
      if ($keyArray === null) {
      //------------------------------------------------------
      $cont = 0;
      foreach ($arrRows as $row) {
      foreach ($row as $val) {
      if ($val === null) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_NULL);
      continue;
      }
      if (is_int($val)) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_INT);
      continue;
      }
      $stmt->bindValue(++$cont, $val);
      }
      }
      $stmt->execute();
      } else {
      $newparamarr = [];
      $paramArr = [];
      foreach ($arrRows as $row) {
      foreach ($keyArray as $index => $key) {
      if (is_array($row))
      $paramArr[$index] = isset($row[$key]) ? $row[$key] : ( ( is_array($defaultArr) && isset($defaultArr[$key]) ) ? $defaultArr[$key] : null );
      else
      $paramArr[$index] = isset($row->{$key}) ? $row->{$key} : ( ( is_array($defaultArr) && isset($defaultArr[$key]) ) ? $defaultArr[$key] : null );
      }
      $newparamarr[] = $paramArr;
      }
      //------------------------------------------------------
      $cont = 0;
      foreach ($newparamarr as $row) {
      foreach ($row as $val) {
      if ($val === null) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_NULL);
      continue;
      }
      if (is_int($val)) {
      $stmt->bindValue(++$cont, $val, \PDO::PARAM_INT);
      continue;
      }
      $stmt->bindValue(++$cont, $val);
      }
      }
      $stmt->execute();
      }
      }
      $arrRows = $arrRowsResult;
      }

      return true;
      }

      return true;
      }

      public function getCampos($campos, $coma = true) {
      if (count($campos))
      return ( $coma ? ',' : '' ) . implode(',', $campos);
      return '';
      }

      public function getParams($campos, $coma = true) {
      if (count($campos))
      return ( $coma ? ',' : '' ) . implode(',', array_fill(0, count($campos), '?'));
      return '';
      }

      public function getUpdates($campos, $coma = true, $exclude = null) {
      if (count($campos)) {
      $updates = [];
      foreach ($campos as $name) {
      if ($exclude != null && in_array($name, $exclude))
      continue;
      $updates[] = "$name = VALUES($name)";
      }
      return ( $coma ? ',' : '' ) . implode(',', $updates);
      }
      return '';
      }

      public function getMemoryUssage($type = true) {
      $size = memory_get_usage($type);
      $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
      $amount = @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
      $size = memory_get_peak_usage($type);
      $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
      $peak = @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
      return 'amount = ' . $amount . ' peak = ' . $peak;
      }


     */
}
