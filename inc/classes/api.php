<?php
##
# API script
##

class Api extends Scanlab {
    function __construct() {
        //to stop creating sessions and twig we override this func
        $this->db_cli = new MongoClient(DB_SERVER);
        $this->db = $this->db_cli->selectDB(DB_NAME);
    }

    function POST($matches) {
        if ( isset($matches[1]) && !empty($matches[1])) {
            $action = (string) $matches[1];
            switch ($action) {
                    case "login":
                        $this->apiLogin();
                        break;
                    case "insert":
                        $this->doInsert();
                        break;
                    case "get_target":
                        $this->getTarget();
                        break;
                    case "upload_xml":
                        $this->uploadXML();
                        break;
                    default:
                        die("wtf r u doing");
            }
        }
    }

    function GET($matches) {
        if (isset($matches[1]) && !empty($matches[1])) {
            //check for api
            $this->checkAPI();
            $action = (string) $matches[1];
            switch ($action) {
                case "iplist":
                    $q = getSearchQuery();
                    $db_query = queryFromSearchArray(queryToArray($q));
                    $limit = 2000;

                    $results = $this->db->reports->find($db_query)->limit($limit);
                    $this->output('text/plain');
                    foreach ($results as $row){
                        echo $row['report']['address']."\n";
                    }
                    break;

                case "xml_export":
                    $q = getSearchQuery();
                    $db_query = queryFromSearchArray(queryToArray($q));
                    $limit = 2000;

                    $results = $this->db->reports->find($db_query)->limit($limit);
                    $this->output('application/octet-stream');
                    header('Content-Disposition: attachment; filename="reports.xml"'); 
                    echo genXML($results, true);
                    break;

                case "delete":
                    $q = getSearchQuery();
                    $search_array = queryToArray($q);
                    $search_array["user"] = $this->checkLogin();
                    $db_query = queryFromSearchArray($search_array);
                    $this->db->reports->remove($db_query);
                    header('Location: '.$_SERVER['HTTP_REFERER']); 
                    break;

                case "xml_id":
                    if (isset($_GET['id']) && !empty($_GET['id'])) {
                        $id = (string) $_GET['id'];
                        $result = $this->checkReportId($id);
                        $this->output('application/octet-stream');
                        header('Content-Disposition: attachment; filename="'.$id.'.xml"'); 
                        echo genXML($result, false);
                    } else {
                        showError('fail');
                    }
                    break;

                default:
                    die('wrong mode');
            }
        } 
    }

    // insert in db
    function doInsert() {
        if (DISABLE_INSERT === true) exit("Report inserting disabled");
        if (
            $this->_post('code') && $this->_post('user') && $this->_post('reports')
        ) {
            $user = (string) $_POST['user'];
            $hash = (string) $_POST['code'];
            $reports = (string) $_POST['reports'];
            $user_row = $this->checkAuth($user, $hash, true);

            if ($user_row === false) die("0");
            if ($user_row['api_key'] !== 1) die("2");
            if ($user_row['reports_count'] >= $user_row['account_limit']) die("3");

            $insert_data = prepareData($reports, $user);
            foreach ($insert_data as $data) {
                try {
                    $this->db->reports->insert($data);
                } catch (Exception $e) {
                    die("Something awful happened");
                }
            }

            try {
                $this->db->users->update(array("username" => $user), array(
                    '$set' => array("reports_count" => $user_row["reports_count"] + count($insert_data))
                ));
                die("1");
            } catch (Exception $e) {
                die('AWWW');
            }
        } else {
            die('user, code or reports not set');
        }
    } 

    //get target from db
    private function getTarget() {
        $this->output('text/plain');
        if ($this->_post('code') && $this->_post('user')) {
            $hash = (string) $_POST['code'];
            $user = (string) $_POST['user'];
            $user_row = $this->checkAuth($user, $hash, true);
            if (!$user_row || $user_row["api_key"] != "1") die("2"); // 2 is invalid auth details or api not enabled

            $targets = explode("\n", trim($user_row["targets"]));
            if (empty($targets['0'])) die("0"); // 0 is no targets left, wait
            // delete one target from TARGETS and save to db
            $target = array_pop($targets);
            try {
                $this->db->users->update(array("username" => $user),
                    array('$set'=>array('targets'=> implode("\n", $targets))));
            } catch (Exception $e) {
                die("3"); // database error
            }
            echo escapeshellcmd(trim($target));
        } else {
            die("code or user is not set");
        }
    }

    // check if user can use API
    private function checkAPI() {
        session_start();
        if (!$this->checkLogin()) showError('Unauthorized', false, 401);
        if (!isset($_SESSION['api_key']) || $_SESSION["api_key"] != "true")
            showError('API functions are disabled for you', false, 401);
    }

    // user uploads XML report
    private function uploadXML() {
        session_start();
        $user = $this->checkLogin();
        if (!$user) showError('Unauthorized', false, 401);
        $user_row = $this->db->users->findOne(array("username" => $user));

        if ($user_row['api_key'] !== 1) showError('API is disabled for you!', false, 401);
        if ($user_row['reports_count'] >= $user_row['account_limit']) showError("Account limit reached");

        if (isset($_FILES["userfile"]["tmp_name"]) && !empty($_FILES["userfile"]["tmp_name"])) {
            if ($_FILES["userfile"]["size"] > 10024000) showError("Uploaded file is too large");
            libxml_use_internal_errors(true);
            libxml_disable_entity_loader(true);
            $xml_string = file_get_contents($_FILES["userfile"]["tmp_name"]);
            if (!$xml_string) showError('Cant read file');
            if (preg_match('/<!DOCTYPE/i', $xml_string)) 
                $xml_string = preg_replace('/<!DOCTYPE(|[^>]+)>/i', '', $xml_string);

            $xml = simplexml_load_string($xml_string);
            if ($xml) {
                parseUpload($xml, $user, $this->db);
                updateUserReportCount($user, $this->db);
                redirect(REL_URL.'user/panel');
            } else {
                showError("You are doing it wrong!");
            }
        } else {
            redirect(REL_URL.'user/panel');
        }
    }

    // returns session id for API clients
    private function apiLogin() {
        session_start();

        if ($this->_post('user') && $this->_post('code')) {
            $user = (string) $_POST['user'];
            $code = (string) $_POST['code'];
            $curr_user = $this->checkAuth($user, $code, true);

            if ($curr_user && $curr_user["api_key"] == 1) {
                $this->sessionSetLogin($curr_user, false);
                $this->output("text/plain");
                echo session_id();
            } else {
                showError("Unauthorized", false, 401);
            }
        }

    }

}

?>
