<?php
/**
 * MySQLDiff
 * 
 * @package   
 * @author 
 * @copyright Nabeel Shahzad
 * @version 2011
 * @access public
 */
class MySQLDiff {
    
    public $xml_errors;
    
    protected $params;
    protected $db;
    protected $xml;
    
    protected $missingCols = array();
    
    
    /**
     * MySQLDiff::construct()
     * 
     * @param mixed $params
     * @return
     */
    public function __construct($params) {
        
        if(!is_array($params)) {
            return false;
        }
        
        $this->params = $params;
        
        # Connect to MySQL
        $this->db = mysql_connect(
            $this->params['dbhost'], 
            $this->params['dbuser'], 
            $this->params['dbpass'],
            true
        );
        
        if(!$this->db) {
            throw new Exception("Could not connect to {$this->params['dbuser']}@{$this->params['dbserver']}");
        }
        
        if(!mysql_select_db($this->params['dbname'], $this->db)) {
            throw new Exception("Could not select database {$this->params['dbname']}");
        }
        
        # Load the XML file
        libxml_use_internal_errors (true);
        $this->xml = simplexml_load_file($this->params['dumpxml']);
        if($this->xml === false) {
            $this->xml_errors = implode("\n", libxml_get_errors());
            throw new Exception ("Errors in XML File: {$this->xml_errors}");
        }
    }
    
    /**
     * Get a list of diffs, returns the table and fields within it
     * which are missing
     * 
     * @return array
     */
    public function getDiffs() {
        return $this->findDiffs();
    }
    
    /**
     * Return MySQL queries to add the missing columns into the table
     * 
     * @return void
     */
    public function getSQLDiffs() {
        
        $missingCols = $this->findDiffs();
        if(count($missingCols) == 0) {
            return $missingCols;
        }
        
        $sqlList = array();
        
        # Add tables...
        foreach($missingCols['tables'] as $table) {
            
            if(count($table) == 0) { # This table exists...
                continue;
            }

            $sql = array();
            $sql[] = 'CREATE TABLE `'.$table['Name'].'` (';
            
            $colList = array();
            foreach($missingCols['columns'][$table['Name']] as $column) {
                $colList[] = $this->getColumnLine($column);
            }
            
            $sql[] = implode(',', $colList);
            $sql[] = ')';
            
            $sql[] = 'ENGINE = '.$table['Engine'];
            $sql[] = 'AUTO_INCREMENT = '.$table['Auto_increment'];
            $sql[] = 'COMMENT = \''.$table['Comment'].'\'';
            $sql[] = 'COLLATE '.$table['Collation'];
            
            # Remove it from the columns list
            unset($missingCols['columns'][$table['Name']]);
            
            $sqlList[] = implode(' ',$sql).';';
        }    
        
        # Now add columns....    
        foreach($missingCols['columns'] as $tableName => $columnList) {
                
            foreach($columnList as $columnName => $column) {
                            
                $sql = array();    
                
                $sql[] = 'ALTER TABLE `'.$tableName.'` ADD';
                
                $sql[] = $this->getColumnLine($column);
                
                if($column['prevField'] === null) {
                    $sql[] = 'FIRST'; # Insert at top of table
                } else {
                    $sql[] = 'AFTER `'.$column['prevField'].'`';   
                }
                
                $sqlList[] = trim(implode(' ', $sql)).';';
            }
        }
        
        return $sqlList;
    }
    
    /**
     * MySQLDiff::getColumnLine()
     * 
     * @param mixed $column
     * @return void
     */
    protected function getColumnLine($column) {
        
        $sql = array();
        
        $sql[] = '`'.$column['Field'].'` '.$column['Type'];
                
        # Is this column null?
        if(strtolower(trim($column['Null'])) == 'no') {
            $sql[] = 'NOT NULL';
        } else {
            $sql[] = 'NULL';
        }
        
        # Is there a default value?
        if(isset($column['Default'])) {
            $sql[] = 'DEFAULT \''.$column['Default'].'\'';
        }
        
        # Any extra stuffs?
        if(isset($column['Extra'])) {
            $sql[] = strtoupper(trim($column['Extra']));
        }
        
        if(isset($column['Key'])) {
            $key = strtolower(trim($column['Key']));
            if($key == 'pri') {
                $sql[] = 'PRIMARY KEY';
            }
        }
        
        return implode(' ', $sql);
    }
    
    
    /**
     * Generate diffs from MySQL and the XML file, and then apply them
     * 
     * @return void
     */
    public function runSQLDiff() {
        
        $sqlList = $this->getSQLDiffs();
        
        foreach ($sqlList as $sql) {
            $res = mysql_query($sql, $this->db);
            if(!$res) {
                throw new Exception(mysql_errno().': '.mysql_error());
            }
        }
        
    }
    
    /**
     * MySQLDiff::findDiffs()
     * 
     * @return void
     */
    protected function findDiffs() {
        
        $this->missingCols = array();
        
        foreach($this->xml->database->table_structure as $table) {
            
            $tableName = (string) $table['name'];
            $this->missingCols['tables'] = array();
            $this->missingCols['columns'] = array();
            
            # Get a list of columns from this table...
        	$desc_result = mysql_query('DESCRIBE '.$tableName, $this->db);
            
            # Make sure table exists...
            $columns = array();
            if(mysql_errno() == 1146) {
                foreach($table->options->attributes() as $key => $value) {
  		            $this->missingCols['tables'][$tableName][$key] = (string) $value;
                }
        	} else {
                # Get list of columns
                while($column = mysql_fetch_object($desc_result)) {
                    $columns[] = strtolower(trim($column->Field));
                }
            }
            
        	
        	/* loop through all the columns returned by the above query and all the columns
        		from the fields in the xml file, and make sure they all match up, with the
        		fieldlist from the xml being the "master" outside loop which it looks up against 
             */
            $prevField = null;
        	foreach($table->field as $field) {
        	   
                $fieldName = (string) $field['Field'];
                
        		$found = false;
        		foreach($columns as $column) {  		            
        			if($column == $fieldName) {
        				$found = true;
        				break;
        			}
        		}
        		
        		if($found == false) {
        		  
  		            # Add all attributes in, but not as SimpleXML objects
  		            $this->missingCols['columns'][$tableName][$fieldName] = array();  
                  
  		            foreach($field->attributes() as $key => $value) {
  		                $this->missingCols['columns'][$tableName][$fieldName][$key] = (string) $value;
  		            }
                    
                    # Also add the previous field, so we know where to place it...
                    $this->missingCols['columns'][$tableName][$fieldName]['prevField'] = $prevField;
        		}
                
                $prevField = $fieldName;
        	}
        }
        
        return $this->missingCols;
    }
}

























