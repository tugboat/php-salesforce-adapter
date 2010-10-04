<?php
define("SOAP_CLIENT_BASEDIR", $soapclient_path );
require_once (SOAP_CLIENT_BASEDIR.'/SforcePartnerClient.php');

abstract class SalesforceModel {
    private $metadata = array();   

    function set($name, $value) {
        $this->metadata[$name] = $value;
    }
     
    function __call($method, $params) {
        // Let's split the method on uppercase characters to get the details
        $list = split(",",substr(preg_replace("/([A-Z])/",',\\1',$method),0));

        // This should be the action to perform, find, get, set, etc...
        $prefix = $list[0];
    
        // This is the name of the class that called me
        $class = get_class($this);

        // And this should be what we are filtering by
        $keys = range(2, sizeOf($list) - 1);
        foreach($keys as $k){
            $values[] = $list[$k];
        }
        $property = implode("", $values);

        switch ($prefix) {
            case "find": return $this->find($class, $property, $params); break;
            case "all": return $this->find($class, $property = null, $params); break;
            default: $this->catch_error($method . " method not implemented");
        }
    }
    
    function find($class, $property, $params) {
        try {

            // This get's the list of properties defined on the calling class, and makes a comma seperated list of them
            // which we will use to query salesforce for the right properties.
            $fields = array_keys(get_class_vars($class));
            $fields_string = implode(",", $fields);

            if (isset($this->metadata['tableName'])) {
                $table = $this->metadata['tableName'];
            } else {
                // If the class in the standard form of SFDC_SomeObjectName, then use SomeObjectName so we don't have to use the metadata all the time:
                $table = str_replace('SFDC_', '', $class);
            }

            if ($property) {
              $query = "SELECT Id, " . $fields_string . " from " . $table. " where " . $property . " = '" . $params[0] . "'";
            } else {
              $conditions = "";
              $parm_array = $params['conditions'];
       
              if(sizeOf($parm_array) > 1) {
                $conditions .= "WHERE " . array_pop($parm_array);
                foreach($parm_array as $p){
                    $conditions .= " AND " . $p;
                }
              } elseif(sizeOf($parm_array) == 1) {
                $conditions .= "WHERE " . array_pop($parm_array);
              }

              $query = "SELECT Id, " . $fields_string . " from " . $table . " " . $conditions;
            }
            //echo $query;
            //die;

            $conn = $this->connect();
            $response = $conn->query($query);
            $result = new QueryResult($response);

            if ($result->records > 0) {
                foreach ($result->records as $record) {
                    $records[] = $this->build_object($class, $fields, $record);
                }
                return $records;
                
            } else {
                return "Some Error";
            }
            
        } catch (Exception $e) {
            return $e;
        }
    }

    function save() {

        $class = get_class($this);

        if (isset($this->metadata['tableName'])) {
            $table = $this->metadata['tableName'];
        } else {
            $table = $class;
        }

        $sObject = new stdclass();
        foreach (array_keys(get_class_vars($class)) as $var) {
            if ($this->$var != '') { 
                $sObject->fields[$var] = $this->$var;
            }
        }
        $sObject->type = $table;

        $conn = $this->connect();
        $result = $conn->upsert("Id", array($sObject));
        return $result;
    }

    function build_object($class, $fields, $record) {
        $obj = clone $this;
        foreach ($fields as $field) {
            $obj->$field = $record->fields->$field;
        }
        // Now add the ID and type to the object (these fields are not part of the "fields" set above)
        $obj->Id = $record->Id;
        $obj->type = $record->type;
        //print_r($obj);
        //die; 
        return $obj; 
    }

    function connect() {
        $mySforceConnection = new SforcePartnerClient();
        $mySoapClient = $mySforceConnection->createConnection(SOAP_CLIENT_BASEDIR.'/partner.wsdl.xml');
        $mylogin = $mySforceConnection->login($salesforce_user, $salesforce_password);

        return $mySforceConnection;
    }
 
    function catch_error($msg = null) {
        if (!$msg == null) {
            echo "Error: " . $msg;
        } else {
            echo "There was an error";
        }
    }
}

?>
