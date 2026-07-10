<?php
/**
 * Panelica External API Client for WHMCS
 *
 * Implements HMAC-SHA256 request signing against the Panelica External API.
 *
 * Signature contract (verified against Panelica backend hmac_auth_service.go):
 *   stringToSign = METHOD + PATH + TIMESTAMP + BODY
 *   signature    = hex( HMAC-SHA256( stringToSign, API_SECRET ) )
 *
 * IMPORTANT — path nuance:
 *   The External API listens on 127.0.0.1:3002 and is exposed publicly through
 *   the panel nginx at  https://<host>:8443/api/external/v1/...
 *   nginx rewrites  /api/external/v1/x  ->  /v1/x  before proxying, so the Go
 *   server sees (and verifies the signature against) "/v1/x".
 *   Therefore we SIGN "/v1/x" but REQUEST "/api/external/v1/x".
 *
 * DELETE requests: the server excludes the body from the signature, so this
 * client never sends a body with DELETE.
 *
 * @copyright Panelica
 * @license   Proprietary
 */

if (!class_exists('PanelicaAPIException')) {
    class PanelicaAPIException extends \Exception
    {
        /** @var int HTTP status code of the failed response (0 = transport error) */
        protected $httpStatus = 0;

        /** @var string Machine-readable error code from the API, if any */
        protected $apiCode = '';

        public function __construct($message, $httpStatus = 0, $apiCode = '')
        {
            parent::__construct($message);
            $this->httpStatus = (int) $httpStatus;
            $this->apiCode = (string) $apiCode;
        }

        public function getHttpStatus()
        {
            return $this->httpStatus;
        }

        public function getApiCode()
        {
            return $this->apiCode;
        }
    }
}

if (!class_exists('PanelicaAPI')) {

    class PanelicaAPI
    {
        /** @var string e.g. "panel.example.com" (no scheme, no path) */
        protected $host;

        /** @var int Panel HTTPS port (default 8443) */
        protected $port;

        /** @var string API key: pk_live_... / pk_test_... */
        protected $apiKey;

        /** @var string API secret: sk_live_... / sk_test_... */
        protected $apiSecret;

        /** @var bool Verify TLS peer. When true and the panel uses a
         *  self-signed certificate, one automatic retry without verification
         *  is performed (panels frequently run on self-signed certs on :8443). */
        protected $verifyTls;

        /** @var bool Set to true when the last request fell back to
         *  unverified TLS, so callers can log a warning. */
        public $tlsFallbackUsed = false;

        /** @var int cURL total timeout in seconds */
        protected $timeout = 60;

        /** @var string Public prefix in front of the External API (see class doc) */
        const PUBLIC_PREFIX = '/api/external';

        public function __construct($host, $port, $apiKey, $apiSecret, $verifyTls = true)
        {
            // Normalise host: strip scheme, trailing slash and embedded port.
            $host = trim((string) $host);
            $host = preg_replace('#^https?://#i', '', $host);
            $host = rtrim($host, '/');
            if (strpos($host, '/') !== false) {
                $host = substr($host, 0, strpos($host, '/'));
            }
            if (strpos($host, ':') !== false && strpos($host, ']') === false) {
                // host:port form (not IPv6) — embedded port wins
                list($host, $embeddedPort) = explode(':', $host, 2);
                if ((int) $embeddedPort > 0) {
                    $port = (int) $embeddedPort;
                }
            }

            $this->host = $host;
            $this->port = ((int) $port > 0) ? (int) $port : 8443;
            $this->apiKey = trim((string) $apiKey);
            $this->apiSecret = trim((string) $apiSecret);
            $this->verifyTls = (bool) $verifyTls;
        }

        // -------------------------------------------------------------
        // High level endpoint helpers (return decoded "data" payloads)
        // -------------------------------------------------------------

        /** GET /v1/me — validates credentials; returns key info. */
        public function me()
        {
            return $this->request('GET', '/v1/me');
        }

        /** GET /v1/plans — list hosting plans. */
        public function listPlans()
        {
            return $this->request('GET', '/v1/plans');
        }

        /**
         * Find one plan by exact slug. Returns the plan array or null.
         */
        public function findPlanBySlug($slug)
        {
            $resp = $this->listPlans();
            $plans = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
            foreach ($plans as $plan) {
                if (isset($plan['slug']) && $plan['slug'] === $slug) {
                    return $plan;
                }
            }
            return null;
        }

        /**
         * POST /v1/plans — create a hosting plan (basic fields + feature flags).
         * Requires plans:write scope and a ROOT/ADMIN key owner.
         */
        public function createPlan(array $fields)
        {
            return $this->request('POST', '/v1/plans', $fields);
        }

        /**
         * PATCH /v1/plans/{id} — update plan columns.
         * The backend accepts a raw column map, which is how advanced fields
         * (cpu_limit_percent, memory_limit_mb, io_read_bps, process_limit,
         * max_containers, php_memory_limit_mb, ...) are set.
         */
        public function updatePlan($planId, array $columns)
        {
            return $this->request('PATCH', '/v1/plans/' . rawurlencode($planId), $columns);
        }

        /**
         * DELETE /v1/plans/{id} — delete a plan.
         * The backend refuses when accounts still use the plan.
         */
        public function deletePlan($planId)
        {
            return $this->request('DELETE', '/v1/plans/' . rawurlencode($planId));
        }

        /** GET /v1/accounts — list accounts visible to the key owner. */
        public function listAccounts()
        {
            return $this->request('GET', '/v1/accounts');
        }

        /**
         * Find one account by exact username (case-insensitive).
         * Returns the account array or null when not found.
         */
        public function findAccountByUsername($username)
        {
            $resp = $this->listAccounts();
            $accounts = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
            $needle = strtolower(trim((string) $username));
            foreach ($accounts as $account) {
                if (isset($account['username']) && strtolower($account['username']) === $needle) {
                    return $account;
                }
            }
            return null;
        }

        /** POST /v1/accounts */
        public function createAccount($username, $password, $email, $fullName, $planId)
        {
            return $this->request('POST', '/v1/accounts', array(
                'username'  => $username,
                'password'  => $password,
                'email'     => $email,
                'full_name' => $fullName,
                'plan_id'   => $planId,
            ));
        }

        /** POST /v1/accounts/{id}/suspend */
        public function suspendAccount($accountId)
        {
            return $this->request('POST', '/v1/accounts/' . rawurlencode($accountId) . '/suspend', new \stdClass());
        }

        /** POST /v1/accounts/{id}/unsuspend */
        public function unsuspendAccount($accountId)
        {
            return $this->request('POST', '/v1/accounts/' . rawurlencode($accountId) . '/unsuspend', new \stdClass());
        }

        /** DELETE /v1/accounts/{id} */
        public function deleteAccount($accountId)
        {
            return $this->request('DELETE', '/v1/accounts/' . rawurlencode($accountId));
        }

        /** POST /v1/accounts/{id}/change-password (admin override) */
        public function changeAccountPassword($accountId, $newPassword)
        {
            return $this->request('POST', '/v1/accounts/' . rawurlencode($accountId) . '/change-password', array(
                'new_password' => $newPassword,
            ));
        }

        /** PATCH /v1/accounts/{id} — used for plan changes and profile updates. */
        public function updateAccount($accountId, array $fields)
        {
            return $this->request('PATCH', '/v1/accounts/' . rawurlencode($accountId), $fields);
        }

        /** GET /v1/accounts/{id}/disk-usage -> {used_mb, quota_mb, usage_percent} */
        public function getDiskUsage($accountId)
        {
            return $this->request('GET', '/v1/accounts/' . rawurlencode($accountId) . '/disk-usage');
        }

        /**
         * GET /v1/accounts/{id}/stats
         * -> {domain_count, email_count, ftp_count, database_count, bandwidth_mb}
         */
        public function getAccountStats($accountId)
        {
            return $this->request('GET', '/v1/accounts/' . rawurlencode($accountId) . '/stats');
        }

        /**
         * POST /v1/accounts/{id}/sso-login — mint a one-time single-sign-on URL.
         * The URL (valid 5 min, single use) logs the user straight into the panel
         * with no password. Requires a *:* scoped ROOT/ADMIN key. -> { url, expires_in }
         */
        public function ssoLogin($accountId)
        {
            return $this->request('POST', '/v1/accounts/' . rawurlencode($accountId) . '/sso-login', new \stdClass());
        }

        /**
         * POST /v1/domains — create a website for an account.
         * All panel defaults apply (nginx+apache, PHP 8.3, Let's Encrypt SSL).
         */
        public function createDomain($domainName, $accountId)
        {
            return $this->request('POST', '/v1/domains', array(
                'name'    => $domainName,
                'user_id' => $accountId,
            ));
        }

        /** GET /v1/bandwidth/accounts/{id} -> {bytes_used, bytes_total, month} */
        public function getAccountBandwidth($accountId)
        {
            return $this->request('GET', '/v1/bandwidth/accounts/' . rawurlencode($accountId));
        }

        // -------------------------------------------------------------
        // Client-area self-service (email / db / ftp / subdomain)
        // -------------------------------------------------------------

        /** GET /v1/accounts/{id}/domains — websites owned by an account. */
        public function listAccountDomains($accountId)
        {
            return $this->request('GET', '/v1/accounts/' . rawurlencode($accountId) . '/domains');
        }

        /** GET /v1/accounts/{id}/emails — email accounts of an account. */
        public function listAccountEmails($accountId)
        {
            return $this->request('GET', '/v1/accounts/' . rawurlencode($accountId) . '/emails');
        }

        /** POST /v1/email-accounts */
        public function createEmail($domainId, $emailOrUser, $password, $quotaMb = 0)
        {
            $body = array('domain_id' => $domainId, 'password' => $password);
            if (strpos((string) $emailOrUser, '@') !== false) {
                $body['email'] = $emailOrUser;
            } else {
                $body['username'] = $emailOrUser;
            }
            if ((int) $quotaMb > 0) {
                $body['quota_mb'] = (int) $quotaMb;
            }
            return $this->request('POST', '/v1/email-accounts', $body);
        }

        /** DELETE /v1/email-accounts/{id} */
        public function deleteEmail($id)
        {
            return $this->request('DELETE', '/v1/email-accounts/' . rawurlencode($id));
        }

        /** GET /v1/databases?domain_id= */
        public function listDatabases($domainId = null)
        {
            $path = '/v1/databases';
            if ($domainId !== null && $domainId !== '') {
                $path .= '?domain_id=' . rawurlencode($domainId);
            }
            return $this->request('GET', $path);
        }

        /**
         * POST /v1/databases
         * The External API takes a single "username" (the panel prefixes it to
         * form both the database and its user), a password and the domain.
         */
        public function createDatabase($domainId, $username, $password)
        {
            return $this->request('POST', '/v1/databases', array(
                'domain_id' => $domainId,
                'username'  => $username,
                'password'  => $password,
            ));
        }

        /** DELETE /v1/databases/{id} */
        public function deleteDatabase($id)
        {
            return $this->request('DELETE', '/v1/databases/' . rawurlencode($id));
        }

        /** GET /v1/ftp-accounts (account-scoped by key owner) */
        public function listFtp()
        {
            return $this->request('GET', '/v1/ftp-accounts');
        }

        /** POST /v1/ftp-accounts */
        public function createFtp($userId, $domainId, $ftpUsername, $password, $directory = '')
        {
            $body = array(
                'user_id'      => $userId,
                'domain_id'    => $domainId,
                'ftp_username' => $ftpUsername,
                'password'     => $password,
            );
            if ($directory !== '') {
                $body['directory'] = $directory;
            }
            return $this->request('POST', '/v1/ftp-accounts', $body);
        }

        /** DELETE /v1/ftp-accounts/{id} */
        public function deleteFtp($id)
        {
            return $this->request('DELETE', '/v1/ftp-accounts/' . rawurlencode($id));
        }

        /** GET /v1/domains/{id}/subdomains */
        public function listSubdomains($domainId)
        {
            return $this->request('GET', '/v1/domains/' . rawurlencode($domainId) . '/subdomains');
        }

        /** POST /v1/domains/{id}/subdomains */
        public function createSubdomain($domainId, $name)
        {
            return $this->request('POST', '/v1/domains/' . rawurlencode($domainId) . '/subdomains', array(
                'name' => $name,
            ));
        }

        /** DELETE /v1/subdomains/{id} */
        public function deleteSubdomain($id)
        {
            return $this->request('DELETE', '/v1/subdomains/' . rawurlencode($id));
        }

        // -------------------------------------------------------------
        // Core signed request
        // -------------------------------------------------------------

        /**
         * Perform a signed request against the External API.
         *
         * @param string            $method GET|POST|PATCH|DELETE
         * @param string            $path   Path as the backend sees it, e.g. "/v1/plans".
         *                                  May include a query string; it is part of the signature.
         * @param array|\stdClass|null $body  JSON body (null for none)
         *
         * @return array Decoded JSON response (full envelope: status/data/message)
         * @throws PanelicaAPIException
         */
        public function request($method, $path, $body = null)
        {
            if ($this->apiKey === '' || $this->apiSecret === '') {
                throw new PanelicaAPIException('Panelica API key/secret is not configured on this server.');
            }

            $method = strtoupper($method);
            $timestamp = (string) time();

            // DELETE: server excludes body from signature — never send one.
            if ($method === 'DELETE') {
                $body = null;
            }

            $bodyString = '';
            if ($body !== null) {
                $bodyString = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($bodyString === false) {
                    throw new PanelicaAPIException('Failed to JSON-encode request body.');
                }
            }

            // Sign the path the backend will see ("/v1/..."), NOT the public path.
            $stringToSign = $method . $path . $timestamp . $bodyString;
            $signature = hash_hmac('sha256', $stringToSign, $this->apiSecret);

            $url = 'https://' . $this->host . ':' . $this->port . self::PUBLIC_PREFIX . $path;

            $headers = array(
                'X-API-Key: ' . $this->apiKey,
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
                'Accept: application/json',
            );
            if ($bodyString !== '') {
                $headers[] = 'Content-Type: application/json';
            }

            $this->tlsFallbackUsed = false;
            list($httpStatus, $raw, $curlErrNo, $curlErr) = $this->execCurl($method, $url, $headers, $bodyString, $this->verifyTls);

            // Automatic one-shot fallback for self-signed panel certificates.
            $sslErrNos = array(35, 51, 58, 60, 77, 83, 90, 91);
            if ($curlErrNo !== 0 && $this->verifyTls && in_array($curlErrNo, $sslErrNos, true)) {
                $this->tlsFallbackUsed = true;
                list($httpStatus, $raw, $curlErrNo, $curlErr) = $this->execCurl($method, $url, $headers, $bodyString, false);
            }

            if ($curlErrNo !== 0) {
                throw new PanelicaAPIException(
                    sprintf('Connection to %s:%d failed: [%d] %s', $this->host, $this->port, $curlErrNo, $curlErr)
                );
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $snippet = trim(substr(strip_tags((string) $raw), 0, 200));
                throw new PanelicaAPIException(
                    sprintf('Unexpected non-JSON response (HTTP %d): %s', $httpStatus, $snippet !== '' ? $snippet : '(empty body)'),
                    $httpStatus
                );
            }

            $isError = ($httpStatus < 200 || $httpStatus >= 300)
                || (isset($decoded['status']) && $decoded['status'] === 'error');

            if ($isError) {
                $message = '';
                foreach (array('error', 'message') as $k) {
                    if (!empty($decoded[$k]) && is_string($decoded[$k])) {
                        $message = $decoded[$k];
                        break;
                    }
                }
                if (!empty($decoded['details']) && is_string($decoded['details'])) {
                    $message .= ($message !== '' ? ' — ' : '') . $decoded['details'];
                }
                if ($message === '') {
                    $message = 'API request failed with HTTP ' . $httpStatus;
                }
                $apiCode = isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : '';
                throw new PanelicaAPIException($message, $httpStatus, $apiCode);
            }

            return $decoded;
        }

        /**
         * @return array [httpStatus, rawBody, curlErrNo, curlErrMsg]
         */
        protected function execCurl($method, $url, array $headers, $bodyString, $verifyTls)
        {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT      => 'Panelica-WHMCS-Module/1.0',
            ));
            if ($bodyString !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyString);
            }

            $raw = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $curlErr = curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return array($httpStatus, ($raw === false ? '' : $raw), $curlErrNo, $curlErr);
        }
    }
}
