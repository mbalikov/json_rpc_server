# json_rpc_server
Simple PHP implementation of JSON-RPC 2.0 server

Very simple PHP implementation of JSON-RPC 2.0 server that allows modular
handlers. Works with singular and batch mode.

Two types of handlers:
1. PHP functions -> check php_methods/echo.php for example
2. Shell script (and not only) -> check exec_methods/echo 

Also, there is a possibility for plugins that process each request. 
Check plugins/100.authorization.php for example

Config parameters:
JSON_RPC_DEBUG_FILE -> location for transaction log (default: none)
JSON_RPC_PHP_METHODS_FOLDER -> location to php handlers (default: ./php_methods)
JSON_RPC_EXEC_METHODS_FOLDER -> location to exec/shell handlers (default: ./exec_methods)
JSON_RPC_PLUGINGS_FOLDER -> location to php plugins (default: ./plugins)

these config parameters are set via either by fastcgi param or environment.
Check nginx.conf for example.

When executing shell method, environment will be populated with request
parameters. For example
{"jsonrpc":"2.0", "method":"echo", "params": {"text": "test test test"}, "id":1}

will create environment variable RPC_PARAM_TEXT="test test test".

Also RPC_ID, RPC_METHOD, RPC_PARAMS will be exposed to environment.
If any of plugins populate php $_ENV[], it will be also available to the
php/exec methods.

