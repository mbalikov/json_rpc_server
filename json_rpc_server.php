<?php

define('JSON_RPC_DEBUG_FILE', $_SERVER['JSON_RPC_DEBUG_FILE']
    ?? getenv('JSON_RPC_DEBUG_FILE')  ?: false);
define('JSON_RPC_PHP_METHODS_FOLDER',  $_SERVER['JSON_RPC_PHP_METHODS_FOLDER']
    ?? getenv('JSON_RPC_PHP_METHODS_FOLDER')  ?:  __DIR__ . DIRECTORY_SEPARATOR . 'php_methods');
define('JSON_RPC_EXEC_METHODS_FOLDER', $_SERVER['JSON_RPC_EXEC_METHODS_FOLDER']
    ?? getenv('JSON_RPC_EXEC_METHODS_FOLDER') ?:  __DIR__ . DIRECTORY_SEPARATOR . 'exec_methods');
define('JSON_RPC_PLUGINGS_FOLDER', $_SERVER['JSON_RPC_PLUGINS_FOLDER']
    ?? getenv('JSON_RPC_PLUGINS_FOLDER') ?:  __DIR__ . DIRECTORY_SEPARATOR . 'plugins');

error_reporting(E_ALL);
ini_set('display_errors', "off");
ini_set('max_execution_time', 0);
ini_set('implicit_flush', 1);
@set_time_limit(0);

header("Content-Type: application/json\r\n");

$RPC_DEBUG_OUTPUT = '';

register_shutdown_function('shutdown_function');

// ========================================================
// PROCESS THE REQUEST
//

// read request
$JSON_RPC_REQUEST = receive_json_post();
if (empty($JSON_RPC_REQUEST) || !is_array($JSON_RPC_REQUEST)) {
    $output = json_rpc_error(null, -32600, "Invalid Request");
    json_rpc_debug_output($output);
    echo $output;
    exit;
}
ignore_user_abort(true);

// Process JSON RPC request
if (array_key_exists('method', $JSON_RPC_REQUEST)) {
    $output = json_rpc_call($JSON_RPC_REQUEST['id'] ?? null, $JSON_RPC_REQUEST['method'], $JSON_RPC_REQUEST['params'] ?? null);
    json_rpc_debug_output($output);
    echo $output;
    exit;
}
// try to process it as a batch
$output = [];
foreach($JSON_RPC_REQUEST as $idx => $json_rcp) {
    if (!is_numeric($idx) || !is_array($json_rpc) || !array_key_exists('method', $json_rpc)) {
        $output[] = json_rpc_error($json_rpc['id'] ?? null, -32600, "Invalid Request");
    }
    else {
        $output[] = json_rpc_call($json_rcp['id'] ?? null, $json_rpc['method'], $json_rpc['params'] ?? null);
    }
}

// output JSON array of the responses
$output = '[' . implode(", ", $output) . ']';
json_rpc_debug_output($output);
echo $output;
exit;

// ========================================================

/*
 * JSON RPC handlers
 */
function json_rpc_call($rpc_id, string $rpc_method, $rpc_params)
{
    if (empty($rpc_method))
        return json_rpc_error($rpc_id, -32600, "Invalid Request");

    $output = json_rpc_plugins($rpc_id, $rpc_method, $rpc_params);
    if (!empty($output))
        return $output;

    $_ENV['RPC_ID'] = $rpc_id ?? '';
    $_ENV['RPC_METHOD'] = $rpc_method;
    $_ENV['RPC_PARAMS'] = json_encode($rpc_params);

    $output = do_php_method($rpc_id, $rpc_method, $rpc_params);
    if (!empty($output))
        return $output;

    $output = do_exec_method($rpc_id, $rpc_method, $rpc_params);
    if (!empty($output))
        return $output;

    return json_rpc_error($rpc_id, -32601, "Method not found");
}

function json_rpc_result($id, $result) {
    if (empty($id))
        throw new Exception("id is empty");

    return '{"jsonrpc": "2.0", "result": '. json_encode($result) .', "id": '. json_encode($id) .'}';
}

function json_rpc_error($id, $error_code, $error_message)
{
    return '{"jsonrpc": "2.0", "error": '
        . '{"code": '. $error_code .', "message": '. json_encode($error_message) .'}, '
        . '"id": '. (empty($id) ? 'null' : json_encode($id))
        . '}';
}

/*
 *
 */
function json_rpc_plugins($rpc_id, $rpc_method, $rpc_params)
{
    static $PLUGINS_FUNCTIONS = null;

    // load plugins
    if (!is_array($PLUGINS_FUNCTIONS)) {
        $plugin_files = glob(JSON_RPC_PLUGINGS_FOLDER . DIRECTORY_SEPARATOR . '*.php');
        foreach ($plugin_files as $f)
        {
            if (!file_exists($f) || !filesize($f))
                continue;

            try {
                $PLUGINS_FUNCTIONS[] = require_once($f);
            }
            catch (Throwable $e) {
                error_log('JSON_RPC: failed to load plugin: ' . $f . ' error=' . $e->GetMessage());
                $PLUGINS_FUNCTIONS = null;
                return json_rpc_error($rpc_id, -32603, "Internal JSON-RPC error.");
            }
        }
    }

    // execute plugins
    if (!empty($PLUGINS_FUNCTIONS)) {
        foreach ($PLUGINS_FUNCTIONS as $idx => $func) {
            try {
                $result = $func($rpc_id, $rpc_method, $rpc_params);
                if (!empty($result))
                    return json_rpc_result($rpc_id, $result);
            }
            catch (Throwable $e) {
                return json_rpc_error($rpc_id, $e->GetCode(), $e->GetMessage());
            }
        }    
    }

    return null;
}

/*
 * Execute PHP Function from JSON_RPC_PHP_METHODS_FOLDER folder. Example:
 *
 * JSON_RPC_PHP_METHODS_FOLDER/echo.php
 *  return function (array $params) {
 *      if (array_key_exists("text", $params)) {
 *          return $params;
 *      } else {
 *          throw new Execption("Missing parameter text", 100);
 *      }
 *  }
 */
function do_php_method(string $rpc_id, string $rpc_method, $rpc_params)
{
    $php_function = get_php_method($rpc_method);
    if (!$php_function)
        return null;

    try {
        $result = $php_function($rpc_params);
        return json_rpc_result($rpc_id, $result);
    }
    catch (Throwable $e) {
        error_log("JSON_RPC: php method failed: id=\"{$rpc_id}\", method=\"{$rpc_method}\", params=". json_encode($rpc_params));
        return json_rpc_error($rpc_id, $e->getCode(), $e->getMessage());
    }
}

function get_php_method(string $rpc_method)
{
    static $PHP_FUNCTIONS = [];
    if (array_key_exists($rpc_method, $PHP_FUNCTIONS))
        return $PHP_FUNCTIONS[$rpc_method];

    try {
        $php_file = JSON_RPC_PHP_METHODS_FOLDER . DIRECTORY_SEPARATOR . $rpc_method . '.php';
        if (!file_exists($php_file))
            return null;

        $method_function = require_once($php_file);
    }
    catch(Throwable $e) {
        error_log("JSON_RPC: require_once({$php_file}) failed: " . $e->getMessage()
            . "\nStack: " . $e->getTraceAsString());
        return null;
    }

    $PHP_FUNCTIONS[$rpc_method] = $method_function;
    return $method_function;
}

/*
 * Execute external program from JSON_RPC_EXEC_METHODS_FOLDER
 * rpc_params will be passed as environment parameters
 * PARAM_key = value
 */
function do_exec_method(string $rpc_id, string $rpc_method, $rpc_params)
{
    $exec_file = JSON_RPC_EXEC_METHODS_FOLDER . DIRECTORY_SEPARATOR . $rpc_method;
    if (!file_exists($exec_file) || !is_executable($exec_file))
        return null;

    // Custom environment variables
    $environment = [];
    $environment = array_merge($environment, $_ENV);

    if (empty($rpc_params)) {
        // noop
    }
    elseif (is_array($rpc_params)) {
        foreach ($rpc_params as $k => $v) {
            $environment[env_param_name($k)] = $v;
        };
    }
    elseif (is_string($rcp_params)) {
        $environment[env_param_name($rpc_params)] = true;
    }
    else {
        error_log("JSON_RPC: exec method : invalid params : id=\"{$rpc_id}\", method=\"{$rpc_method}\", params=". json_encode($rpc_params));
        return json_rpc_error($rpc_id, -32602, "Invalid params");
    }

    // Options for proc_open
    $descriptors = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];
    $pipes = [];

    $process = proc_open($exec_file, $descriptors, $pipes, '/tmp/', $environment);
    if (!is_resource($process)) {
        error_log("JSON_RPC: exec method : failed to execute {$exec_file} : id=\"{$rpc_id}\", method=\"{$rpc_method}\", params=". json_encode($rpc_params));
        return json_rpc_error($rpc_id, -32603, "Internal error");
    }

    // Read output and error streams
    $output = stream_get_contents($pipes[1]);
    $error  = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (!empty($error)) {
        error_log("JSON_RPC: execute {$exec_file} error : {$error}");
    }

    // Close the process
    $exit_code = proc_close($process);
    if ($exit_code != 0) {
        return json_rpc_error($rpc_id, $exit_code, $error);
    }

    $json_data = @json_decode($output);
    if (!empty($json_data)) {
        $output = $json_data;
    }

    return json_rpc_result($rpc_id, $output);
}

function env_param_name(string $param_name)
{
    return 'RPC_PARAM_' . strtoupper(str_replace(' ', '_', $param_name));
}
// ========================================================

function receive_json_post()
{
    $http_request_method = $_SERVER['REQUEST_METHOD'] ?? '';
    $http_content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($http_request_method !== 'POST'
        || ($http_content_type !== 'application/json' &&
            $http_content_type !== 'text/json'))
    {
        return null;
    }

    $raw_data = file_get_contents('php://input');
    if (empty($raw_data)) {
        return null;
    }

    json_rpc_debug_input($raw_data);

    $json_data = json_decode($raw_data, true);
    if (!is_array($json_data)) {
        return null;
    }

    return $json_data;
}

function json_rpc_debug_input(string &$input)
{
    if (empty(JSON_RPC_DEBUG_FILE))
        return;

    $now = new DateTime('now');
    $now_formated = $now->format('[Y-m-d H:i:s.v]');
    
    global $RPC_DEBUG_LOG;
    $RPC_DEBUG_LOG[] = $now_formated . ' > ' . $input . "\n";
}

function json_rpc_debug_output(string &$output)
{
    if (empty(JSON_RPC_DEBUG_FILE))
        return;

    $now = new DateTime('now');
    $now_formated = $now->format('[Y-m-d H:i:s.v]');
    
    global $RPC_DEBUG_LOG;
    $RPC_DEBUG_LOG[] = $now_formated . ' < ' . $output . "\n";
}

function shutdown_function()
{
    global $RPC_DEBUG_LOG;
    if (empty(JSON_RPC_DEBUG_FILE) || empty($RPC_DEBUG_LOG))
        return;

    file_put_contents(JSON_RPC_DEBUG_FILE, implode("", $RPC_DEBUG_LOG), FILE_APPEND);
}
