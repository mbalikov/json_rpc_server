<?php


return function ($rpc_id, string $rpc_method, array $rpc_params) {
    static $JWT_TOKEN_SECRET = 'asdasdnowhr238232nandswjenfownr238ruandjnfajn239';
    static $JWT_TOKEN_EXPIRE = 300;
    static $USER_PASS = 'admin:123456';
    
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        list($method, $value) = explode(" ", $_SERVER['HTTP_AUTHORIZATION'], 2);
        if (strtoupper($method) === 'BASIC') {
            if ($value !== $USER_PASS) {
                throw new Exception("Autorization failed: user/password mismatch", 403);
            }
            // autorized
            list($user, $pass) = explode(":", $value, 2);
            $_ENV['RPC_USER'] = $user;
            return null;
        }
        elseif (strtoupper($method) === 'BEARER') {
            $jwt = jwt_decode($value);
            if (!array_key_exists('user', $jwt['payload'])) {
                throw new Exception("Invalid JWT: missing user", 403);        
            }
            jwt_verify($jwt, $JWT_TOKEN_SECRET);
            
            $_ENV['RPC_USER'] = $jwt['payload']['user'];
            return null;
        }
        else {
            throw new Exception("Unsupported authorization method: {$method}", 403);
        }
    }
    elseif ($rpc_method === 'login') {
        $user = $rpc_params['user'] ?? '';
        $pass = $rpc_params['pass'] ?? '';
        if ("$user:$pass" !== $USER_PASS) {
            throw new Exception("Login failed: user/password mismatch", 403);
        }

        // return JWT token future access
        $jwt_payload = [ 'user' => $user ];
        $jwt_token = jwt_encode($jwt_payload, $JWT_TOKEN_SECRET, $JWT_TOKEN_EXPIRE);
        return [ 'token' => $jwt_token ];
    }
    else {
        throw new Exception("Need authorization", 403);
    }
};

// ========================================================

function jwt_encode(array $payload, string $key, int $expire = 0, string $key_id = '')
{
    if (empty($payload))
        throw new Exception("Empty payload");
    if (empty($key))
        throw new Exception("Empty key");

    $header = [
        "alg" => "HS256",
        "typ" => "JWT"
    ];
    if (!empty($key_id)) {
        $header['kid'] = $key_id;
    }

    $payload['iat'] = time();
    if ($expire > 0) {
        $payload['exp'] = $payload['iat'] + $expire;
    }

    $base64UrlHeader = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $base64UrlPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

    $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, $key, true);
    $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
}

function jwt_decode(string $jwt)
{
    list($header, $payload, $signature) = explode('.', $jwt);

    $decodedHeader = json_decode(
        base64_decode(str_pad(strtr($header, '-_', '+/'), strlen($header) % 4, '=', STR_PAD_RIGHT)), true);
    $decodedPayload = json_decode(
        base64_decode(str_pad(strtr($payload, '-_', '+/'), strlen($payload) % 4, '=', STR_PAD_RIGHT)), true);

    if (empty($decodedHeader) || empty($decodedPayload)) 
        throw new Exception("Invalid JWT token", 400);

    return [ 
        'header' => $decodedHeader, 
        'payload' => $decodedPayload, 
        'encoded' => $jwt ];
}

function jwt_verify(array $jwt, string $jwt_key)
{
    if (!is_array($jwt)
        || !array_key_exists('header', $jwt)
        || !array_key_exists('payload', $jwt)
        || !array_key_exists('encoded', $jwt))
    {
        throw new Exception("Invalid jwt", 400);
    }
    if (empty($jwt_key)) {
        throw new Exception("Empty key", 500);
    }

    $header  = &$jwt['header'];
    $payload = &$jwt['payload'];

    $now = time();
    if (array_key_exists('iat', $payload)) {
        if ($payload['iat'] > $now)
            throw new Exception("jwt iat is older than now", 403);
    }
    if (array_key_exists('exp', $payload)) {
        if ($payload['exp'] < $now)
            throw new Exception('jwt token expired', 403);
    }

    // Verify signature
    if (!array_key_exists('alg', $header) || $header['alg'] !== 'HS256') {
        throw new Exception('unsupported jwt signature algorith; supported is HS256', 400);
    }

    list($header, $payload, $signature) = explode('.', $jwt['encoded']);
    $decoded_signature = base64_decode(
        str_pad(strtr($signature, '-_', '+/'), strlen($signature) % 4, '=', STR_PAD_RIGHT));

    $expected_signature = hash_hmac('sha256', $header . '.' . $payload, $jwt_key, true);

    if (!hash_equals($expected_signature, $decoded_signature))
        throw new Exception("jwt invalid signature", 403);

    return true;
}