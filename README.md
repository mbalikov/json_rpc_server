# json_rpc_server
### Simple PHP implementation of JSON-RPC 2.0 server

A very simple PHP implementation of JSON-RPC 2.0 server that allows modular
handlers. Works with singular and batch mode.

Two types of handlers:
1. PHP functions -> check php_methods/echo.php
2. Shell script (and not only) -> check exec_methods/echo 

Also, there is a possibility for plugins that process each request.  
Check plugins/100.authorization.php for an example.

Config parameters:
* JSON_RPC_DEBUG_FILE -> location for transaction log (default: none)
* JSON_RPC_PHP_METHODS_FOLDER -> location to PHP handlers (default: ./php_methods)
* JSON_RPC_EXEC_METHODS_FOLDER -> location to exec/shell handlers (default: ./exec_methods)
* JSON_RPC_PLUGINGS_FOLDER -> location to php plugins (default: ./plugins)

These config parameters are set via fastcgi params or environment.
Check nginx.conf for an example.

When executing a shell method, the environment will be populated with request
parameters. For example:
```
{"jsonrpc":"2.0", "method":"echo", "params": {"text": "test test test"}, "id":1}
```
will create environment variable RPC_PARAM_TEXT="test test test".

Also, RPC_ID, RPC_METHOD, RPC_PARAMS will be exposed to the environment.
If any of the plugins populate php $_ENV[], it will also be available to the
php/exec methods.

```
time curl -s -H 'Authorization: Basic admin:123456' -H 'Host: jsonrpc' -H 'Content-Type: application/json' http://localhost:8080/jsonrpc -d '{"jsonrpc":"2.0", "method":"php-echo", "params": {"text": "test test test"}, "id":1}'

time curl -s -H 'Host: jsonrpc' -H 'Content-Type: application/json' http://localhost:8080/jsonrpc -d '{"jsonrpc":"2.0", "method":"login", "params": {"user": "admin", "pass": "123456"}, "id":1}'

time curl -s -H 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyIjoiYWRtaW4iLCJpYXQiOjE3MDUwNzQ3NDMsImV4cCI6MTcwNTA3NTA0M30.jxh-1bCWRUNfyQPUscPu8_8GfH3KlBOLhhrrIMKCTro' -H 'Host: jsonrpc' -H 'Content-Type: application/json' http://localhost:8080/jsonrpc -d '{"jsonrpc":"2.0", "method":"echo", "params": {"text": "test test test"}, "id":1}'
```
