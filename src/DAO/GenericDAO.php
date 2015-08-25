<?php
namespace PseudoORM\DAO;

use PseudoORM\Entity\EntidadeBase;
use PseudoORM\Exception;
use \PDO;

class GenericDAO implements IGenericDAO
{

    protected $type, $tableName;

    public function __construct($type)
    {
    	$classe = new \ReflectionAnnotatedClass($type);
        $this->type = $classe->getName();
        $this->setTableName();
    }

    private function setTableName(){
    	$classe = new \ReflectionAnnotatedClass($this->type);
    	if(!$classe->hasAnnotation('Table') && $classe->getAnnotation('Table') != ''){
    		$this->tableName = strtolower($classe->getAnnotation('Table')->value);
    	} else {
    		$this->tableName = strtolower($classe->getShortName());
    	}
    }
    
    public function create()
    {
        return new $this->type();
    }

    public function getById($uid)
    {
        $connection = new PDO(DB_DSN, DB_USERNAME, DB_PASSWORD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION  ));
        $sql  = " SELECT * FROM "  . SCHEMA . strtolower($this->type) ." where uid = :uid";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue(":uid", $uid, PDO::PARAM_INT);
        $stmt->setFetchMode(PDO::FETCH_CLASS, $this->type);
        $stmt->execute();
        $object = $stmt->fetch();
        $connection = null;
        return $object;
    }

    /**
     * @see IGenericDAO::getList()
     */
    public function getList($sortColumn = null, $sortOrder = 'ASC', $limit = 1000000, $offset = 0)
    {
        $connection = new PDO(DB_DSN, DB_USERNAME, DB_PASSWORD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION  ));
        $sql  = " SELECT * FROM "  .
            SCHEMA .
            $this->tableName .
            ($sortColumn != null ? " ORDER BY $sortColumn $sortOrder " : '') .
            " LIMIT :limit OFFSET :offset; ";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
        $stmt->setFetchMode(PDO::FETCH_CLASS, $this->type);
        $stmt->execute();
        $list = $stmt->fetchAll();
        $connection = null;
        return $list;
    }

    /**
     * @see IGenericDAO::insert();
     */
    public function insert(EntidadeBase $object)
    {
        $connection = new PDO(DB_DSN, DB_USERNAME, DB_PASSWORD, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION  ));
        $attributos = (array) $object;
        $parametros = array();
        foreach ($attributos as $k => $v) {
            if ($k != 'uid' && ($v != '')) {
                $parametros[] = ":$k";
            } else {
                unset($attributos[$k]);
            }
        }
        $queryParams = "(".implode(", ", array_keys($attributos)).") VALUES(". implode(', ', $parametros) ." )";
        try {
            $sql  = " INSERT INTO "  . SCHEMA . $this->tableName . " $queryParams RETURNING uid;";
            $stmt = $connection->prepare($sql);
            $this->bindArrayValue($stmt, $attributos);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $uid = $result['uid'];
            $connection = null;
        } catch (Exception $e) {
            $message = "Existem relacionamentos vinculados à esse objeto. Impossível excluir.";
            throw new RelacionamentoException($message);
            $connection = null;
        }
        return $uid;
    }


    /**
     * @see IGenericDAO::update();
     */
    public function update(EntidadeBase $object)
    {
        $connection = new PDO(DB_DSN, DB_USERNAME, DB_PASSWORD, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ));
        $attributos = (array) $object;
        $parametros = array();
        foreach ($attributos as $k => $v) {
            if ($k != 'uid' && ($v != '')) {
                $parametros[] = "$k = :$k";
            } else {
                unset($attributos[$k]);
            }
        }
        $queryParams = implode(', ', $parametros);

        try {
            $sql = "UPDATE " . SCHEMA . $this->tableName . " SET " . $queryParams . ' WHERE uid = :uid;';
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(":uid", $object->uid, PDO::PARAM_INT);
            $this->bindArrayValue($stmt, $attributos);
            $stmt->execute();
            $connection = null;
        } catch (Exception $e) {
            $message = "Existem relacionamentos vinculados à esse objeto. Impossível excluir.";
            throw new RelacionamentoException($message);
            $connection = null;
        }
    }

    /**
     * @see IGenericDAO::delete();
     */
    public function delete($uid)
    {
        if (is_null($uid)) {
            throw new Exception("Erro ao remover registro.");
        }
        try {
            $connection = new PDO(
                DB_DSN,
                DB_USERNAME,
                DB_PASSWORD,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
            );
            $sql  = " DELETE FROM "  . SCHEMA . $this->tableName . " where uid = :uid";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(":uid", $uid, PDO::PARAM_INT);
            $stmt->execute();
            $connection = null;
        } catch (Exception $e) {
            $message = "Existem relacionamentos vinculados à esse objeto. Impossível excluir.";
            throw new RelacionamentoException($message);
            $connection = null;
        }
    }


    /**
     *
     * @param string $req : the query on which link the values
     * @param array $array : associative array containing the values ​​to bind
     * @param array $typeArray : associative array with the desired value for its corresponding key in $array
     */
    private function bindArrayValue($query, $array, $typeArray = false)
    {
        if (is_object($query) && ($query instanceof \PDOStatement)) {
            foreach ($array as $key => $value) {
                if ($typeArray) {
                    $query->bindValue(":$key", $value, $typeArray[$key]);
                } else {
                    $valor = $value;
                    if (is_int($valor)) {
                        $param = PDO::PARAM_INT;
                    } elseif (is_bool($valor))
                        $param = PDO::PARAM_BOOL;
                    elseif (is_null($valor))
                        $param = PDO::PARAM_NULL;
                    elseif (is_string($valor))
                        $param = PDO::PARAM_STR;
                    else {
                        $param = false;
                    }

                    
                    if ($param) {
                        $query->bindValue(":$key", $valor, $param);
                    }
                }
            }
        }
    }
    
    
    // TODO Refactor to automatically detect the property's type
    public function generate(){
    
    	$reflectionClass = new \ReflectionAnnotatedClass($this->type);
    	
       	$tabela = $this->tableName;
    	
    	$propriedades = $reflectionClass->getProperties();
    	foreach($propriedades as $propriedade){
    		if($propriedade->hasAnnotation('Column')){
    			$getter = $propriedade->name;
    			$key = $propriedade->getAnnotation('Column')->name;
    			$params = (array) $propriedade->getAnnotation('Column');
    			foreach($params as $chave=>$valor){
    				$fields[$key][$chave] = $valor;
    			}
    		}
    		if ($propriedade->hasAnnotation('Join')){
    			$params = (array) $propriedade->getAnnotation('Join');
    			foreach($params as $chave=>$valor){
    				$fields[$key][$chave] = $valor;
    			}
    		}
    	}
    
    	$script = "DROP TABLE IF EXISTS ".SCHEMA.$tabela."; \n"
    			."CREATE TABLE ".SCHEMA.$tabela ." ( \n";
    	$uid;
    
    	foreach($fields as $key=>$value){
    		$fk;
    		if (isset($value['joinTable'])){
    			$fk = "\tCONSTRAINT ".$tabela."_".$value['joinTable']."_fk FOREIGN KEY($key)\n";
    			$fk .= "\t\tREFERENCES ".SCHEMA.$value['joinTable']."($value[joinColumn]) MATCH SIMPLE\n";
    			$fk .= "\t\tON UPDATE NO ACTION ON DELETE NO ACTION,\n";
    			$script .= "\t". $key . " integer, \n";
    		} else if($key == 'uid'){
    			$script .= "\t". $key . " serial NOT NULL, \n";
    			$uid = $key;
    		} else {
    			$script .= "\t". $key . " " . ($value['type'] == 'integer' ? 'integer' : ($value['type'] == 'timestamp' ? 'timestamp' : 'character varying')) . ", \n";
    		}
    	}
    	$script .= @$fk;
    	$script .= "\tCONSTRAINT ".$tabela."_pk PRIMARY KEY (".$uid.") \n";
    	return $script . " );\n\n -- \n";
    }
}
