<?php

error_reporting(E_ALL);
ini_set('display_errors', "off");
ini_set('max_execution_time', 0);
ini_set('implicit_flush', 1);
@set_time_limit(0);

$RPC_DEBUG_OUTPUT = '';
register_shutdown_function('shutdown_function');

initialize();

// ========================================================
// PROCESS THE REQUEST
//

header("Content-Type: application/json\r\n");

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
    if ($output === true)
        exit;
    json_rpc_debug_output($output);
    echo $output;
    exit;
}
// try to process it as a batch
$batch_output = [];
foreach($JSON_RPC_REQUEST as $idx => $json_rcp) {
    if (!is_numeric($idx) || !is_array($json_rpc) || !array_key_exists('method', $json_rpc)) {
        $output = json_rpc_error($json_rpc['id'] ?? null, -32600, "Invalid Request");
        if ($output === true)
            continue;
        $batch_output[] = $output;
    }
    else {
        $output = json_rpc_call($json_rcp['id'] ?? null, $json_rpc['method'], $json_rpc['params'] ?? null);
        if ($output === true)
            continue;
        $batch_output[] = $output;
    }
}

if (empty($batch_output))
    exit;

// output JSON array of the responses
$output = '[' . implode(", ", $batch_output) . ']';
json_rpc_debug_output($batch_output);
echo $output;
exit;

// ========================================================

/*
 * JSON RPC handlers
 */
function json_rpc_call($rpc_id, string $rpc_method, $rpc_params)
{
    $rpc_method = trim($rpc_method);
    if (empty($rpc_method))
        return json_rpc_error($rpc_id, -32600, "Invalid Request");

    if ($rpc_method[0] == '/')
        return json_rpc_error($rpc_id, 403, "Access Deny: method must not contain \"../\"");

    if (strstr($rpc_method, '../') !== false)
        return json_rpc_error($rpc_id, 403, "Access Deny: method must not contain \"../\"");

    $output = json_rpc_plugins($rpc_id, $rpc_method, $rpc_params);
    if (!empty($output))
        return $output;

    $_ENV['RPC_ID'] = $rpc_id ?? '';
    $_ENV['RPC_METHOD'] = $rpc_method;
    $_ENV['RPC_PARAMS'] = json_encode($rpc_params);

    $output = do_php_handler($rpc_id, $rpc_method, $rpc_params);
    if (!empty($output))
        return $output;

    $output = do_exec_handler($rpc_id, $rpc_method, $rpc_params);
    if (!empty($output))
        return $output;

    return json_rpc_error($rpc_id, -32601, "Method not found");
}

function json_rpc_result($id, $result)
{
    if (empty($id))
        throw new Exception("id is empty");

    return '{"jsonrpc": "2.0", "result": '. json_stringify($result) .', "id": '. json_encode($id) .'}';
}

function json_rpc_error($id, $error_code, $error_message)
{
    return
        '{' .
            '"jsonrpc": "2.0", ' .
            '"error": {"code": '. $error_code .', "message": '. json_stringify($error_message) .'}, ' .
            '"id": '. (empty($id) ? 'null' : json_stringify($id)) .
        '}';
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
function do_php_handler($rpc_id, string $rpc_method, $rpc_params)
{
    $php_function = get_php_handler($rpc_method);
    if (!$php_function)
        return null;

    try {    
        $result = $php_function($rpc_params);
        if (empty($rpc_id)) // this is notification, no reply is expected
            return true;

        return json_rpc_result($rpc_id, $result);
    }
    catch (Throwable $e) {
        error_log("JSON_RPC: do_php_handler() failed:"
            . " id=\"{$rpc_id}\","
            . " method=\"{$rpc_method}\","
            . " params=". json_encode($rpc_params)
            . " ERROR({$e->getCode()}) : {$e->getMessage()}");

        return json_rpc_error($rpc_id, $e->getCode(), $e->getMessage());
    }
}

function get_php_handler(string $rpc_method)
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
function do_exec_handler($rpc_id, string $rpc_method, $rpc_params)
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
            if (!may_environment_key($k))
                continue;
            if (is_array($v))
                continue;
            $environment[env_param_name($k)] = empty($v) ? true : $v;
        };
    }
    elseif (may_environment_key($rcp_params)) {
        $environment[env_param_name($rpc_params)] = true;
    }

    // Options for proc_open
    $descriptors = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];
    $pipes = [];

    $process = proc_open($exec_file, $descriptors, $pipes, '/tmp/', $environment);
    if (!is_resource($process)) {
        error_log("JSON_RPC: do_exec_handler({$exec_file}) proc_open failed :"
            . " id=\"{$rpc_id}\","
            . " method=\"{$rpc_method}\","
            . " params=". json_encode($rpc_params));

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

    if (empty($rpc_id)) // this is notification, no reply is expected
        return true;

    $json_data = @json_decode($output, true);
    if (is_array($json_data)) {
        $output = $json_data;
    }
    return json_rpc_result($rpc_id, $output);
}

function env_param_name(string $param_name)
{
    return 'RPC_PARAM_' . strtoupper(str_replace(' ', '_', $param_name));
}

// ========================================================
function initialize() 
{
    define('JSON_RPC_DEBUG_FILE', $_SERVER['JSON_RPC_DEBUG_FILE']
        ?? getenv('JSON_RPC_DEBUG_FILE')  ?: false);
    define('JSON_RPC_PHP_METHODS_FOLDER',  $_SERVER['JSON_RPC_PHP_METHODS_FOLDER']
        ?? getenv('JSON_RPC_PHP_METHODS_FOLDER')  ?:  __DIR__ . DIRECTORY_SEPARATOR . 'php_methods');
    define('JSON_RPC_EXEC_METHODS_FOLDER', $_SERVER['JSON_RPC_EXEC_METHODS_FOLDER']
        ?? getenv('JSON_RPC_EXEC_METHODS_FOLDER') ?:  __DIR__ . DIRECTORY_SEPARATOR . 'exec_methods');
    define('JSON_RPC_PLUGINGS_FOLDER', $_SERVER['JSON_RPC_PLUGINS_FOLDER']
        ?? getenv('JSON_RPC_PLUGINS_FOLDER') ?:  __DIR__ . DIRECTORY_SEPARATOR . 'plugins');
    define('JSON_RPC_ENVIRONMENT_FILE', $_SERVER['JSON_RPC_ENVIRONMENT_FILE']
        ?? getenv('JSON_RPC_ENVIRONMENT_FILE') ?:  __DIR__ . DIRECTORY_SEPARATOR . '.env');

    // populate $_ENV[] with environment parameters from config file
    if (file_exists(JSON_RPC_ENVIRONMENT_FILE))
    {
        $lines = file(JSON_RPC_ENVIRONMENT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach($lines as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] == '#')
                    continue;

                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (empty($key) || empty($value))
                    continue;

                if (!may_environment_key($key)) {
                    error_log("Invalid default environment key: {$key}");
                    continue;
                }
        
                $_ENV[strtoupper($key)] = $value;
            }
        }
    }
}

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

function may_environment_key($key) {
    if (!is_string($key) || empty($key))
        return false;

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $key))
        return false;

    // Check if the string doesn't start with a number
    if (ctype_digit($key[0]))
        return false;

    // Additional checks if needed...

    // it's ok
    return true;
}


function json_stringify($json_val)
{
    $out = json_encode($json_val);
    return addslashes($out);
}
