<?php
namespace VATUSA\Uls;

use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\Converter\StandardConverter;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Checker\InvalidHeaderException;
use Jose\Component\Checker\InvalidClaimException;
use Namshi\JOSE\Base64\Base64Encoder;

class Uls
{
    private $_minversion = 2;
    protected $version = 2;
    protected $jwk = [];
    private $token;

    public function __construct() {
        $this->version = config("uls.version", 2);
        if ($this->version < $this->_minversion) {
            $this->version = $this->_minversion;
            \Log::info("VATUSA\Uls: Version was set below minimum version. Assuming version of $this->_minversion instead of " . config("uls.version", 2));
        }
        $this->jwk = config("uls.jwk", []);
        $this->facility = config("uls.facility", '');
    }

    public function buildUrl($location, $queryString = "") {
        $base = "https://login.vatusa.net/uls/";
        $base .= ($this->version == 2) ? "v2/" : "";

        $url = "";

        switch ($location) {
            case "login":
                $url = $base . "login?fac=$this->facility" . (($queryString) ? "&$queryString" : "");
                break;
            case "info":
                $url = $base . "info?token=$this->token";
                break;
            default:
                throw new \Exception("Invalid location");
        }

        return $url;
    }

    public function setJwk($jwk) {
        $new_jwk = json_decode($jwk, true);
        if (!$new_jwk) {
            throw new \InvalidArgumentException("Invalid JWK");
        }
    }

    public function redirectUrl($dev = false) {
        return $this->buildUrl('login', ($dev) ? "dev" : null);
    }

    public function verifyToken($token) {
        if (!$token) { return false; }
        if (empty($this->jwk)) { throw new \Exception("Invalid JWK"); }

        // Support all algorithms for future growth
        $algorithmManager = AlgorithmManager::create([
            new HS256(), new HS384(), new HS512()
        ]);

        $jwsVerifier = new JWSVerifier($algorithmManager);
        $jwk = JWK::create($this->jwk);
        $jsonConverter = new StandardConverter();
        $serializerManager = new CompactSerializer($jsonConverter);
        $jws = $serializerManager->unserialize($token);

        $headerCheckerManager = HeaderCheckerManager::create(
            [
                new AlgorithmChecker(['HS256', 'HS384', 'HS512']), // Approved algorithms for ULSv2
            ],
            [
                new JWSTokenSupport(), // We do JWS Tokens
            ]
        );

        $claimCheckerManager = ClaimCheckerManager::create(
            [
                new Checker\IssuedAtChecker(),
                new Checker\NotBeforeChecker(),
                new Checker\ExpirationTimeChecker(),
                new Checker\AudienceChecker($this->facility)
            ]
        );

        // Can throw InvalidHeaderException
        $headerCheckerManager->check($jws, 0);

        // Can throw InvalidClaimException
        $claims = $jsonConverter->decode($jws->getPayload());
        $claimCheckerManager->check($claims);

        $return = $jwsVerifier->verifyWithKey($jws, $jwk, 0);

        $this->token = $token;

        return $return;
     }

     public function getInfo() {
        return json_decode($this->curlInfo("GET", $this->buildUrl("info", "token=" . $this->token)), true);
     }

     private function curlInfo($method, $url, $postString = "") {
         $ch = curl_init();

         curl_setopt_array($ch, [
             CURLOPT_URL            => $url,
             CURLOPT_RETURNTRANSFER => 1,
             CURLOPT_TIMEOUT        => 15
         ]);
         if ($method == "POST") {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postString
            ]);
         }
         $response = curl_exec($ch);
         if (!$response) {
             \Log::critical("Laravel-ULS/Curl: error occurred: " . curl_error($ch) . ", error number: #" . curl_errno($ch));
             return false;
         }
         return $response;
     }
}
