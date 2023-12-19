<?php 
namespace App\Common;
use PDO;


class Database {
  private static? Database $instance=null;
  private PDO $pdo;
  
  private function __construct(){
    $this->pdo= new PDO( "mysql:host=db;dbname=tp;charset=utf8mb4", "root", "root" );

  }

  public static function getInstance () : static {
    // Si on n'a pas d'instance initialisée, on en instancie une
    if ( is_null( self::$instance ) )
      self::$instance = new Database();
    
    return self::$instance;
  }
  
  public function getPDO () : PDO {
    return $this->pdo;
  }
  
  

}