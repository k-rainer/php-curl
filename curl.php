<?php

namespace PhpCurl;

use CurlHandle;
use CurlShareHandle;

use Error;
use function
    curl_init,
    curl_setopt,
    curl_share_init,
    curl_share_setopt,
    curl_exec,
    curl_close,
    curl_share_close,
    mb_split,
    array_map,
    array_combine,
    array_column,
    array_filter;

class Curl {

    private CurlHandle $handle;
    private ?CurlShareHandle $shareHandle = null;
    
    /** @var string[] $headers */
    private array $headers = [];
    
    public function __construct(?CurlHandle $handle = null, ?CurlShareHandle $shareHandle = null) {
        if(!empty($handle)) {
            $this->handle = $handle;
        }
        if(!empty($shareHandle)) {
            $this->shareHandle = $shareHandle;
        }
    }
    
    public function initialize(bool $share = true):self {
        
        if($share && empty($this->shareHandle)) {
            $this->shareHandle = curl_share_init();
        
            curl_share_setopt($this->shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
            curl_share_setopt($this->shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt($this->shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        }
        
        if(!empty($this->handle)) {
            curl_close($this->handle);
        }
        
        $this->handle = curl_init();
        
        if($share) {
            curl_setopt($this->handle, CURLOPT_COOKIEJAR, __DIR__ . '/cookie_jar.txt');
            curl_setopt($this->handle, CURLOPT_SHARE, $this->shareHandle);
            
            curl_setopt($this->handle, CURLOPT_FORBID_REUSE, false);
        }
        
        curl_setopt($this->handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($this->handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($this->handle, CURLOPT_DEFAULT_PROTOCOL, CURLPROTO_HTTPS);
        
        curl_setopt($this->handle, CURLOPT_FAILONERROR, true);
        
        curl_setopt($this->handle, CURLOPT_TCP_FASTOPEN, true);
        curl_setopt($this->handle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($this->handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_MAX_DEFAULT );
        curl_setopt($this->handle, CURLOPT_SSL_VERIFYSTATUS, false);
        curl_setopt($this->handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($this->handle, CURLOPT_SSL_FALSESTART, true);
        curl_setopt($this->handle, CURLOPT_DNS_SHUFFLE_ADDRESSES, true);
        
        curl_setopt($this->handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->handle, CURLOPT_MAXREDIRS, 20);
        curl_setopt($this->handle, CURLOPT_AUTOREFERER, true);
        
        curl_setopt($this->handle, CURLOPT_HEADER, true);
        curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->handle, CURLOPT_TIMEOUT, 25);
        
        return $this;
        
    }
    
    public function basicAuth(string $username, string $password):self {
        curl_setopt($this->handle, CURLOPT_USERNAME, $username);
        curl_setopt($this->handle, CURLOPT_PASSWORD, $password);
        
        return $this;
    }
    
    public function setHeader(string $key, ?string $value = null):self {
        if(!empty($value)) {
            $this->headers[$key] = $value;
        }
        else if(!empty($this->headers[$key])) {
            unset($this->headers[$key]);
        }
        
        return $this;
    }
    
    public function getHeader(string $key, mixed $fallback = null):mixed {
        if(empty($this->headers[$key])) {
            return $fallback;
        }
        
        return $this->headers[$key];
    }
    
    public function headers() {
        return array_filter($this->headers);
    }
    
    public function clearHeaders():self {
        $this->headers = [];
        return $this;
    }
    
    /**
     * @param string $headers
     * @return string[]
     */
    private function processResponseHeaders(string $rawHeaders):array {
    
        $headers = array_map(function(string $headerLine):array {
            return array_map(function($headerPart, $num) {
                $headerPart = trim($headerPart);
                if($num == 1) {
                    $headerPart = ucwords($headerPart, '-');
                }
                return $headerPart;
            }, mb_split(':|\s', $headerLine, 2), range(1, 2));
        }, mb_split('\r\n', $rawHeaders));
        
        return array_combine(array_column($headers, 0), array_column($headers, 1));
        
    }
    
    private function send(string $url): array {
        if(!empty($this->headers)) {
            curl_setopt($this->handle, CURLOPT_HEADER, $this->headers());
        }
        
        curl_setopt($this->handle, CURLOPT_URL, $url);
        
        $return = curl_exec($this->handle);
        
        if(($errorNo = curl_errno($this->handle)) != 0) {
            $error = curl_error($this->handle);
            
            throw new Error($error, $errorNo);
        }
        
        $info = [];
        $info['url'] = curl_getinfo($this->handle, CURLINFO_EFFECTIVE_URL);
        $info['time'] = curl_getinfo($this->handle, CURLINFO_TOTAL_TIME);
        $info['response_code'] = curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
        $info['http_code'] = curl_getinfo($this->handle, CURLINFO_HTTP_CODE);
        $info['http_method'] = curl_getinfo($this->handle, CURLINFO_PRIVATE);
        
        [$head, $body] = mb_split('\r\n\r\n', $return, 2);
        
        $headers = $this->processResponseHeaders($head);
        
        if(!empty($headers['Content-Type']) && mb_substr($headers['Content-Type'], 0, 16) == 'application/json') {
            $body = json_decode($body, true, 512, JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING);
        }
        
        return [
            'info' => $info,
            'head' => $headers,
            'body' => $body,
        ];
        
    }
    
    public function get(string $url):array {
        curl_setopt($this->handle, CURLOPT_PRIVATE, 'get');
        
        return $this->send($url);
    }
    
    public function post(string $url, string|array $data = []):array {
        curl_setopt($this->handle, CURLOPT_POST, true);
        curl_setopt($this->handle, CURLOPT_POSTFIELDS, (is_array($data)) ? http_build_query($data) : $data);
        curl_setopt($this->handle, CURLOPT_PRIVATE, 'post');
        
        return $this->send($url);
    }
    
    public function postJSON(string $url, array $data = []):array {
        $this->setHeader('Content-Type', 'application/json');
        
        return $this->post($url, json_encode($data));
    }
    
    public function close() {
        curl_close($this->handle);
        if(!empty($this->shareHandle)) {
            curl_share_close($this->shareHandle);
        }
    }
    
    public function __destruct() {
        $this->close();
    }
    
}
