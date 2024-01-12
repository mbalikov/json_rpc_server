<?php

return function (array $rpc_params) {
    if (array_key_exists("text", $rpc_params)) {
        return [
            "echo back" => $rpc_params["text"],
            "user" => $_ENV['RPC_USER'],
        ];
    } else {
        throw new Exception("Missing parameter text", 100);
    }
};
