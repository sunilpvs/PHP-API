<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
require_once(__DIR__."/../../vendor/autoload.php");

class JWTHandler {
    private $secretKey; 
    private $algorithm;
    private $config;

    function __construct() 
    {
        $this->config = parse_ini_file(__DIR__ . '/../../app.ini', true);
        $this->secretKey = $this->config['jwt']['jwt_secret'];
        $this->algorithm = "HS256";
    }
    // Generate access token (Short-lived)
    function generateAccessToken(array $payload) 
    {
        return JWT::encode($payload, $this->secretKey, $this->algorithm);
    }

    // Generate refresh token (Long-lived)
    function generateRefreshToken(array $payload,) 
    {
        return JWT::encode($payload, $this->secretKey, $this->algorithm);
    }
    function verifyToken($token) {
        try {
            return JWT::decode($token, new Key($this->secretKey, $this->algorithm));
        } catch (Exception $e) {
            return false; // Token invalid/expired
        }
    }
    
    function decodeJWT($token) {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            return (array) $decoded;
        } catch (Exception $e) {
           
            http_response_code(401); 
            echo json_encode(["error" => "Invalid or expired token", "message" => $e->getMessage()]);
            exit; 
        }
    }

}