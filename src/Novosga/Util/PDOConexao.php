<?php

namespace Novosga\Util;
class PDOConexao
{
   public static function strConexao()
   {
      $strConn = "";
      try {
        $aDadosConexao = include( NOVOSGA_ROOT.'/config/database.php' );

        $strConn .= ( ! strpos( $aDadosConexao['driver'], 'pgsql') ? 'mysql' : 'pgsql') . ':';
        $strConn .= "host=" . $aDadosConexao['host'] . ';';
        $strConn .= "port=" . $aDadosConexao['port'] . ';';
        $strConn .= "dbname=" . $aDadosConexao['dbname'] . ';';
        $strConn .= "user=" . $aDadosConexao['user'] .';';
        $strConn .= "password=" . $aDadosConexao['password'];
      }
      catch (Exception $ex) {
        die('<h2>classe "\Novosga\Util\PDOConexao" método "strConexaoDB":</h2> <h2>' . $ex->getMessage() .'</h2>' );
      }  
      return $strConn;
   }
}
