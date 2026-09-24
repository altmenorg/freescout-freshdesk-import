<?php

namespace Modules\FreshdeskImport\Services;

/**
 * Minimal Freshdesk API v2 client (curl, no dependency).
 * Rate limits: a 429 answer throws RateLimited with the Retry-After delay; the importer then pauses until that time
 * instead of sleeping inside the web or scheduler process.
 */
class FreshdeskApi
{
    protected $base;
    protected $auth;

    /** Requests left in the current rate-limit window, as reported by the last answer (null = unknown). */
    public $remaining = null;

    public function __construct($domain, $key)
    {
        $domain = strtolower(trim((string)$domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(preg_replace('#/.*$#', '', $domain), '.');
        if ($domain !== '' && strpos($domain, '.') === false) {
            $domain .= '.freshdesk.com'; // "acme" -> acme.freshdesk.com
        }
        $this->base = 'https://'.$domain.'/api/v2/';
        $this->auth = base64_encode(trim((string)$key).':X');
    }

    /** GET an API path (relative to /api/v2/) and return the decoded JSON. */
    public function get($path)
    {
        return json_decode($this->request($this->base.ltrim($path, '/'), true), true);
    }

    /** Download a file (attachment URLs are pre-signed: no API authentication). Returns null on failure. */
    public function download($url)
    {
        try {
            return $this->request($url, false);
        } catch (RateLimited $e) {
            throw $e;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function request($url, $authenticated)
    {
        $last_error = '';
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $headers_out = [];
            $ch = curl_init($url);
            $headers = ['User-Agent: FreeScout-FreshdeskImport/1.0', 'Accept: application/json'];
            if ($authenticated) {
                $headers[] = 'Authorization: Basic '.$this->auth;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers_out) {
                    $p = strpos($line, ':');
                    if ($p !== false) {
                        $headers_out[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    }
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($authenticated && isset($headers_out['x-ratelimit-remaining'])) {
                $this->remaining = (int)$headers_out['x-ratelimit-remaining'];
            }
            if ($code >= 200 && $code < 300) {
                return $body;
            }
            if ($code == 429) {
                throw new RateLimited(max(10, (int)($headers_out['retry-after'] ?? 60)));
            }
            if ($code == 401) {
                throw new \Exception('Freshdesk refused the API key (HTTP 401): check the domain and the key');
            }
            if ($code == 403) {
                throw new Forbidden('The Freshdesk agent owning this API key is not allowed to do this (HTTP 403): '.$url);
            }
            if ($code == 404) {
                throw new \Exception('Not found on Freshdesk (HTTP 404): '.$url);
            }
            // network error or 5xx: short pause, then retry
            $last_error = $code ? 'HTTP '.$code : ($err ?: 'no answer');
            sleep(2 * $attempt);
        }
        throw new \Exception('Freshdesk API error ('.$last_error.'): '.$url);
    }
}
