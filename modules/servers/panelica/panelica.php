<?php
/**
 * Panelica Server Provisioning Module for WHMCS
 *
 * Provisions and manages hosting accounts on Panelica control panel servers
 * through the Panelica External API (HMAC-SHA256 authenticated).
 *
 * Requirements:
 *   - WHMCS 8.13 / 9.0+
 *   - PHP 8.2 or 8.3
 *   - A Panelica API key with scopes:
 *       accounts:read, accounts:write, accounts:delete, domains:write,
 *       plans:read, bandwidth:read
 *
 * Server configuration mapping (Setup -> Products/Services -> Servers):
 *   Hostname     -> Panel hostname or IP (e.g. panel.example.com)
 *   Password     -> API Key    (pk_live_...)
 *   Access Hash  -> API Secret (sk_live_...)
 *   Port         -> Panel port (default 8443)
 *
 * @copyright Panelica
 * @license   Proprietary
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/PanelicaAPI.php';

/**
 * Module metadata.
 */
function panelica_MetaData()
{
    return array(
        'DisplayName'    => 'Panelica',
        'APIVersion'     => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort'    => '8443',
        'DefaultNonSSLPort' => '8443',
    );
}

/**
 * Admin area link shown on the server configuration / product pages —
 * a quick shortcut that opens the panel in a new tab.
 */
function panelica_AdminLink(array $params)
{
    $host = !empty($params['serverhostname']) ? $params['serverhostname'] : $params['serverip'];
    $port = !empty($params['serverport']) ? (int) $params['serverport'] : 8443;
    $url = 'https://' . htmlspecialchars($host, ENT_QUOTES) . ':' . $port . '/';
    return '<a href="' . $url . '" target="_blank" rel="noopener" class="btn btn-default">Open Panelica Panel</a>';
}

/**
 * Renew: called when a renewal invoice is paid. Panelica has no time-bound
 * account concept, so this simply makes sure the account is active and
 * returns success (never blocks the billing workflow).
 */
function panelica_Renew(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = $api->findAccountByUsername(isset($params['username']) ? trim($params['username']) : '');
        if ($account !== null && isset($account['status'])
            && strtolower((string) $account['status']) === 'suspended') {
            $api->unsuspendAccount($account['id']);
            panelica_log(__FUNCTION__, array('username' => $params['username']), 'reactivated on renewal');
        }
        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        // Renewal must not be blocked by a panel hiccup.
        return 'success';
    }
}

/**
 * Admin custom buttons on the product's admin service page.
 */
function panelica_AdminCustomButtonArray(array $params)
{
    return array(
        'Sync From Panel' => 'Sync',
    );
}

/**
 * Admin "Sync From Panel" action: pulls the live account status + usage from
 * the panel and writes it back into the WHMCS service row.
 */
function panelica_Sync(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);

        $row = array();
        try {
            $disk = $api->getDiskUsage($account['id']);
            if (isset($disk['data'])) {
                $row['diskusage'] = (int) $disk['data']['used_mb'];
                $row['disklimit'] = (int) $disk['data']['quota_mb'];
            }
        } catch (Exception $e) { /* skip */ }
        try {
            $bw = $api->getAccountBandwidth($account['id']);
            if (isset($bw['data']['bytes_used'])) {
                $row['bwusage'] = (int) round(((int) $bw['data']['bytes_used']) / 1048576);
            }
        } catch (Exception $e) { /* skip */ }

        if (!empty($row) && !empty($params['serviceid'])) {
            $row['lastupdate'] = date('Y-m-d H:i:s');
            Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])->update($row);
        }

        panelica_log(__FUNCTION__, array('username' => $params['username']), $row);
        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Build an API client from WHMCS module params.
 *
 * @param array $params WHMCS module params
 * @return PanelicaAPI
 */
function panelica_getApi(array $params)
{
    $host = !empty($params['serverhostname']) ? $params['serverhostname'] : $params['serverip'];
    $port = !empty($params['serverport']) ? (int) $params['serverport'] : 8443;

    // Password field = API Key, Access Hash field = API Secret.
    $apiKey    = isset($params['serverpassword']) ? trim($params['serverpassword']) : '';
    $apiSecret = isset($params['serveraccesshash']) ? trim($params['serveraccesshash']) : '';

    return new PanelicaAPI($host, $port, $apiKey, $apiSecret, true);
}

/**
 * Uniform logging helper — masks credentials.
 */
function panelica_log($action, $request, $response)
{
    $mask = array();
    if (is_array($request)) {
        foreach (array('password', 'new_password', 'serverpassword', 'serveraccesshash') as $k) {
            if (!empty($request[$k]) && is_string($request[$k])) {
                $mask[] = $request[$k];
            }
        }
    }
    logModuleCall('panelica', $action, $request, $response, $response, $mask);
}

/** Sentinel value stored in configoption1 when WHMCS manages the plan itself. */
define('PANELICA_MANAGED_PLAN', '__managed__');

/**
 * Product configuration options.
 *
 * Option 1 (configoption1): the Panelica plan — either an existing plan UUID
 * loaded live from the panel, or the "Managed by WHMCS" sentinel. In managed
 * mode the module creates and keeps a plan on the panel in sync with the
 * resource options below (options 2-14), including cgroups v2 CPU/RAM/IO
 * limits enforced by the kernel.
 */
function panelica_ConfigOptions()
{
    return array(
        'plan' => array(
            'FriendlyName' => 'Panelica Plan',
            'Type'         => 'dropdown',
            'Loader'       => 'panelica_LoaderPlans',
            'SimpleMode'   => true,
            'Description'  => 'Pick an existing panel plan, or "Managed by WHMCS" to define resources below',
        ),
        'diskquota' => array(
            'FriendlyName' => 'Disk Quota (MB)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '5120',
            'Description'  => 'Managed mode only. -1 = unlimited',
        ),
        'bandwidth' => array(
            'FriendlyName' => 'Monthly Bandwidth (MB)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '51200',
            'Description'  => 'Managed mode only. -1 = unlimited',
        ),
        'cpulimit' => array(
            'FriendlyName' => 'CPU Limit (%)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '100',
            'Description'  => 'Managed mode only. 100 = 1 core, 200 = 2 cores (cgroups v2)',
        ),
        'memlimit' => array(
            'FriendlyName' => 'Memory Limit (MB)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '1024',
            'Description'  => 'Managed mode only. RAM ceiling enforced by the kernel (cgroups v2)',
        ),
        'proclimit' => array(
            'FriendlyName' => 'Max Processes',
            'Type'         => 'text', 'Size' => '10', 'Default' => '100',
            'Description'  => 'Managed mode only. Process count limit (pids controller)',
        ),
        'iolimit' => array(
            'FriendlyName' => 'Disk I/O Limit (MB/s)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '0',
            'Description'  => 'Managed mode only. Read+write throttle, 0 = unlimited',
        ),
        'maxdomains' => array(
            'FriendlyName' => 'Max Websites',
            'Type'         => 'text', 'Size' => '10', 'Default' => '1',
            'Description'  => 'Managed mode only. -1 = unlimited',
        ),
        'maxdb' => array(
            'FriendlyName' => 'Max Databases',
            'Type'         => 'text', 'Size' => '10', 'Default' => '5',
            'Description'  => 'Managed mode only',
        ),
        'maxemail' => array(
            'FriendlyName' => 'Max Email Accounts',
            'Type'         => 'text', 'Size' => '10', 'Default' => '10',
            'Description'  => 'Managed mode only',
        ),
        'maxftp' => array(
            'FriendlyName' => 'Max FTP Accounts',
            'Type'         => 'text', 'Size' => '10', 'Default' => '5',
            'Description'  => 'Managed mode only',
        ),
        'maxcontainers' => array(
            'FriendlyName' => 'Max Docker Containers',
            'Type'         => 'text', 'Size' => '10', 'Default' => '0',
            'Description'  => 'Managed mode only. 0 = Docker disabled, -1 = unlimited',
        ),
        'phpmem' => array(
            'FriendlyName' => 'PHP Memory Limit (MB)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '256',
            'Description'  => 'Managed mode only. Default PHP memory_limit for sites',
        ),
        'sshlevel' => array(
            'FriendlyName' => 'SSH Access',
            'Type'         => 'dropdown',
            'Options'      => 'none,jailed,full',
            'Default'      => 'none',
            'Description'  => 'Managed mode only. none = off, jailed = chrooted SFTP/SSH, full = full shell',
        ),
        'inodequota' => array(
            'FriendlyName' => 'Inode Quota',
            'Type'         => 'text', 'Size' => '10', 'Default' => '-1',
            'Description'  => 'Managed mode only. Max files+dirs. -1 = unlimited',
        ),
        'maxsub' => array(
            'FriendlyName' => 'Max Subdomains',
            'Type'         => 'text', 'Size' => '10', 'Default' => '10',
            'Description'  => 'Managed mode only. -1 = unlimited',
        ),
        'netbw' => array(
            'FriendlyName' => 'Network Bandwidth (Mbit/s)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '0',
            'Description'  => 'Managed mode only. eBPF network rate limit, 0 = unlimited',
        ),
        'quotamode' => array(
            'FriendlyName' => 'Quota Mode',
            'Type'         => 'dropdown',
            'Options'      => 'strict,monitor,oversell',
            'Default'      => 'strict',
            'Description'  => 'Managed mode only. strict = block over-quota adds, monitor = warn, oversell = allow ratio',
        ),
        'waf' => array(
            'FriendlyName' => 'ModSecurity WAF',
            'Type'         => 'dropdown',
            'Options'      => 'on,off',
            'Default'      => 'on',
            'Description'  => 'Managed mode only. Web Application Firewall for sites',
        ),
        'backup' => array(
            'FriendlyName' => 'Backups Enabled',
            'Type'         => 'dropdown',
            'Options'      => 'on,off',
            'Default'      => 'on',
            'Description'  => 'Managed mode only. Allow backups for accounts',
        ),
        'maxcron' => array(
            'FriendlyName' => 'Max Cron Jobs',
            'Type'         => 'text', 'Size' => '10', 'Default' => '5',
            'Description'  => 'Managed mode only. -1 = unlimited, 0 = disabled',
        ),
        'phpexec' => array(
            'FriendlyName' => 'PHP max_execution_time (s)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '30',
            'Description'  => 'Managed mode only. Default PHP script time limit',
        ),
        'phpupload' => array(
            'FriendlyName' => 'PHP Upload/Post Max (MB)',
            'Type'         => 'text', 'Size' => '10', 'Default' => '64',
            'Description'  => 'Managed mode only. Sets both upload_max_filesize and post_max_size',
        ),
        'advanced' => array(
            'FriendlyName' => 'Advanced Overrides',
            'Type'         => 'textarea', 'Rows' => '4', 'Cols' => '40',
            'Description'  => 'Managed mode only. One key=value per line for any extra plan column, '
                . 'e.g. iops_limit=1000, git_enabled=1, max_git_repositories=5, oversell_ratio=1.2, '
                . 'php_max_children=20, php_max_input_time=120, ftp_access_enabled=1, mysql_access_enabled=1, cron_jobs_enabled=1',
        ),
    );
}

/**
 * Whitelist of plan columns settable via the "Advanced Overrides" textarea.
 * Anything outside this list is ignored (prevents setting id/slug/owner/etc.).
 */
function panelica_advancedWhitelist()
{
    return array(
        // cgroups / resource
        'iops_limit'                => 'int',
        'network_bps_limit'         => 'int',
        'inode_quota'               => 'int',
        // git
        'git_enabled'               => 'bool',
        'max_git_repositories'      => 'int',
        // quota policy
        'oversell_ratio'            => 'float',
        // php-fpm pool
        'php_max_children'          => 'int',
        'php_max_input_time'        => 'int',
        'php_upload_max_filesize_mb'=> 'int',
        'php_post_max_size_mb'      => 'int',
        // feature toggles
        'ftp_access_enabled'        => 'bool',
        'mysql_access_enabled'      => 'bool',
        'cron_jobs_enabled'         => 'bool',
        'modsecurity_enabled'       => 'bool',
        'ssl_enabled'               => 'bool',
    );
}

/**
 * Parse the "Advanced Overrides" textarea into a typed, whitelisted column map.
 */
function panelica_parseAdvanced($raw)
{
    $out = array();
    $whitelist = panelica_advancedWhitelist();
    $lines = preg_split('/[\r\n]+/', (string) $raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }
        list($key, $val) = explode('=', $line, 2);
        $key = strtolower(trim($key));
        $val = trim($val);
        if (!isset($whitelist[$key])) {
            continue;
        }
        switch ($whitelist[$key]) {
            case 'int':   $out[$key] = (int) $val; break;
            case 'float': $out[$key] = (float) $val; break;
            case 'bool':  $out[$key] = in_array(strtolower($val), array('1','true','yes','on'), true); break;
            default:      $out[$key] = $val;
        }
    }
    return $out;
}

/**
 * Dynamic dropdown loader for the plan option.
 * Called by WHMCS with server params when a Panelica server is selected.
 *
 * @return array plan_uuid => label
 * @throws Exception
 */
function panelica_LoaderPlans(array $params)
{
    // The managed option is added FIRST and unconditionally so the dropdown is
    // never empty. If the panel is briefly unreachable at render time (or the
    // server credentials are not yet saved), an empty dropdown would let an
    // admin Save wipe the stored plan selection (configoption1 -> "") and
    // silently break managed mode. Building live plans is best-effort.
    $options = array(
        PANELICA_MANAGED_PLAN => '— Managed by WHMCS (auto-create from options below) —',
    );

    try {
        $api = panelica_getApi($params);
        $resp = $api->listPlans();
        $plans = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
        foreach ($plans as $plan) {
            if (empty($plan['id'])) {
                continue;
            }
            // Hide module-managed plan versions from the manual picker.
            if (isset($plan['slug']) && strpos($plan['slug'], 'whmcs-p') === 0) {
                continue;
            }
            $label = isset($plan['name']) ? $plan['name'] : $plan['id'];
            if (isset($plan['disk_quota_mb'])) {
                $label .= sprintf(' (%s disk', panelica_formatMb((int) $plan['disk_quota_mb']));
                if (isset($plan['monthly_bandwidth_mb'])) {
                    $label .= sprintf(', %s bw', panelica_formatMb((int) $plan['monthly_bandwidth_mb']));
                }
                $label .= ')';
            }
            $options[$plan['id']] = $label;
        }
    } catch (\Throwable $e) {
        // Panel unreachable / credentials not saved yet: keep the managed
        // option so the product config still renders and never goes empty.
        panelica_log(__FUNCTION__, $params, 'plan list unavailable: ' . $e->getMessage());
    }

    return $options;
}

/** Human-friendly MB formatting for labels. */
function panelica_formatMb($mb)
{
    if ($mb <= 0) {
        return 'unlimited';
    }
    if ($mb >= 1024 && $mb % 1024 === 0) {
        return ($mb / 1024) . ' GB';
    }
    return $mb . ' MB';
}

/**
 * The set of domain names owned by this account (lowercased), from the
 * account-domains listing. Used to scope the (server-level) backup API down to
 * the client's own domains so one customer can never see, restore, or delete a
 * backup that contains another account's data.
 */
function panelica_acctDomainNames(array $dr)
{
    $out = array();
    foreach (($dr['data'] ?? array()) as $d) {
        if (!is_array($d)) { continue; }
        $dn = $d['domain_name'] ?? $d['name'] ?? $d['domain'] ?? '';
        if ($dn !== '') { $out[] = strtolower($dn); }
    }
    return $out;
}

/**
 * A server backup "belongs to" this account only when every domain it contains
 * is one of the account's own domains. Backups with unknown scope (no
 * domain_names) are treated as NOT owned — a safe default that hides
 * server-wide backups from the client.
 */
function panelica_backupOwnedByAccount(array $backup, array $acctDomains)
{
    $names = isset($backup['domain_names']) && is_array($backup['domain_names']) ? $backup['domain_names'] : array();
    if (empty($names)) { return false; }
    foreach ($names as $n) {
        if (!in_array(strtolower($n), $acctDomains, true)) { return false; }
    }
    return true;
}

/**
 * The set of item IDs the account actually owns for a given client-area tab,
 * mirroring the exact scoping the list view uses. The module authenticates with
 * a server-wide (root) API key, so the panel's own RBAC does NOT constrain a
 * delete to this account — the module must verify ownership itself before
 * deleting, otherwise a client could remove ANOTHER account's resource by
 * supplying a guessed/known UUID (IDOR).
 */
function panelica_ownedIds(PanelicaAPI $api, $tab, $aid, $did)
{
    $ids = array();
    $collect = function ($data) use (&$ids) {
        foreach ((is_array($data) ? $data : array()) as $x) {
            if (is_array($x) && !empty($x['id'])) { $ids[] = $x['id']; }
        }
    };
    switch ($tab) {
        case 'email':          $collect($api->listAccountEmails($aid)['data'] ?? array()); break;
        case 'forwarders':     if ($did !== '') { $collect($api->listForwarders($did)['data'] ?? array()); } break;
        case 'autoresponders': if ($did !== '') { $collect($api->listAutoresponders($did)['data'] ?? array()); } break;
        case 'subdomains':     if ($did !== '') { $collect($api->listSubdomains($did)['data'] ?? array()); } break;
        case 'dns':            if ($did !== '') { $collect($api->listDnsRecords($did)['data'] ?? array()); } break;
        case 'redirects':      if ($did !== '') { $collect($api->listRedirects($did)['data'] ?? array()); } break;
        case 'ftp':
            foreach (($api->listFtp()['data'] ?? array()) as $f) {
                if ((isset($f['user_id']) && $f['user_id'] === $aid) || (isset($f['domain_id']) && $f['domain_id'] === $did)) {
                    if (!empty($f['id'])) { $ids[] = $f['id']; }
                }
            }
            break;
        // Cron and MySQL used to treat an item carrying neither an owner nor a
        // domain as belonging to whoever was asking. That is the opposite of
        // the rule stated for backups a few lines up, and of what the FTP
        // branch does: a resource this account cannot be shown to own is not
        // one it may delete. Today's panel always sends both fields, so the
        // permissive branch bought nothing and would have turned any future
        // response that omitted them into a cross-account delete.
        case 'cron':
            foreach (($api->listCron()['data'] ?? array()) as $j) {
                if ((isset($j['user_id']) && $j['user_id'] === $aid) || (isset($j['domain_id']) && $j['domain_id'] === $did)) {
                    if (!empty($j['id'])) { $ids[] = $j['id']; }
                }
            }
            break;
        case 'mysql':
            foreach (($api->listMysqlUsers()['data'] ?? array()) as $u) {
                if ((isset($u['user_id']) && $u['user_id'] === $aid) || (isset($u['domain_id']) && $u['domain_id'] === $did)) {
                    if (!empty($u['id'])) { $ids[] = $u['id']; }
                }
            }
            break;
    }
    return $ids;
}

/**
 * Build the managed plan specification from product config options.
 * Returns ['basic' => ..., 'advanced' => ..., 'hash' => ...] where "basic"
 * feeds POST /v1/plans and "advanced" feeds PATCH /v1/plans/{id}
 * (raw column map: cgroups v2 + PHP defaults).
 */
function panelica_managedPlanSpec(array $params)
{
    $intOpt = function ($idx, $default) use ($params) {
        $raw = isset($params['configoption' . $idx]) ? trim((string) $params['configoption' . $idx]) : '';
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }
        return (int) $raw;
    };

    $strOpt = function ($idx, $default) use ($params) {
        $raw = isset($params['configoption' . $idx]) ? trim((string) $params['configoption' . $idx]) : '';
        return $raw !== '' ? $raw : $default;
    };

    $diskMb    = $intOpt(2, 5120);
    $bwMb      = $intOpt(3, 51200);
    $cpuPct    = $intOpt(4, 100);
    $memMb     = $intOpt(5, 1024);
    $procs     = $intOpt(6, 100);
    $ioMbs     = max(0, $intOpt(7, 0));
    $maxDom    = $intOpt(8, 1);
    $maxDb     = $intOpt(9, 5);
    $maxEmail  = $intOpt(10, 10);
    $maxFtp    = $intOpt(11, 5);
    $maxCont   = $intOpt(12, 0);
    $phpMemMb  = $intOpt(13, 256);
    $sshLevel  = $strOpt(14, 'none');
    if (!in_array($sshLevel, array('none', 'jailed', 'full'), true)) {
        $sshLevel = 'none';
    }
    $inodeQuota = $intOpt(15, -1);
    $maxSub     = $intOpt(16, 10);
    $netMbit    = max(0, $intOpt(17, 0));
    $quotaMode  = $strOpt(18, 'strict');
    if (!in_array($quotaMode, array('strict', 'monitor', 'oversell'), true)) {
        $quotaMode = 'strict';
    }
    $waf        = $strOpt(19, 'on') !== 'off';
    $backup     = $strOpt(20, 'on') !== 'off';
    $maxCron    = $intOpt(21, 5);
    $phpExec    = $intOpt(22, 30);
    $phpUpload  = $intOpt(23, 64);

    $basic = array(
        'disk_quota_mb'        => $diskMb,
        'monthly_bandwidth_mb' => $bwMb,
        'max_domains'          => $maxDom,
        'max_subdomains'       => $maxSub,
        'max_email_accounts'   => $maxEmail,
        'max_databases'        => $maxDb,
        'max_ftp_accounts'     => $maxFtp,
        'max_cron_jobs'        => $maxCron,
        'ssh_access_enabled'   => ($sshLevel !== 'none'),
        'ftp_access_enabled'   => true,
        'mysql_access_enabled' => true,
        'cron_jobs_enabled'    => ($maxCron !== 0),
        'ssl_enabled'          => true,
        'backup_enabled'       => $backup,
    );

    // Advanced columns pushed via PATCH /v1/plans/{id} (raw column map).
    // eBPF network limit stored in bytes/sec (Mbit/s * 125000).
    $advanced = array(
        'cpu_limit_percent'          => $cpuPct,
        'memory_limit_mb'            => $memMb,
        'process_limit'              => $procs,
        'io_read_bps'                => $ioMbs * 1048576,
        'io_write_bps'               => $ioMbs * 1048576,
        'network_bps_limit'          => $netMbit * 125000,
        'max_containers'             => $maxCont,
        'inode_quota'                => $inodeQuota,
        'quota_mode'                 => $quotaMode,
        'ssh_access_level'           => $sshLevel,
        'modsecurity_enabled'        => $waf,
        'php_memory_limit_mb'        => $phpMemMb,
        'php_max_execution_time'     => $phpExec,
        'php_upload_max_filesize_mb' => $phpUpload,
        'php_post_max_size_mb'       => $phpUpload,
    );

    // Merge whitelisted textarea overrides last (they win over defaults above).
    $overrides = panelica_parseAdvanced($strOpt(24, ''));
    foreach ($overrides as $k => $v) {
        // ssl_enabled belongs to the create payload; route it to basic.
        if ($k === 'ssl_enabled' || $k === 'ftp_access_enabled'
            || $k === 'mysql_access_enabled' || $k === 'cron_jobs_enabled') {
            $basic[$k] = $v;
        } else {
            $advanced[$k] = $v;
        }
    }

    // Stable content hash: same options -> same plan version (reused);
    // any change -> new slug -> real plan switch -> kernel re-applies limits.
    $hash = substr(md5(json_encode(array($basic, $advanced))), 0, 8);

    return array('basic' => $basic, 'advanced' => $advanced, 'hash' => $hash);
}

/**
 * Ensure the managed plan for this product exists on the panel with the
 * current option values, and garbage-collect superseded versions.
 *
 * Plan slug: whmcs-p{pid}-{spec-hash}. When resource options change the hash
 * changes, a fresh plan version is created and accounts are moved onto it by
 * ChangePackage — a genuine plan_id change, which makes the Panelica backend
 * rewrite the account's cgroups v2 limits in the kernel immediately.
 *
 * @return string plan UUID
 * @throws Exception
 */
function panelica_ensureManagedPlan(PanelicaAPI $api, array $params)
{
    $pid = isset($params['packageid']) ? (int) $params['packageid'] : 0;
    if ($pid <= 0) {
        throw new Exception('Cannot determine the WHMCS product id for the managed plan.');
    }

    $spec = panelica_managedPlanSpec($params);
    $slug = 'whmcs-p' . $pid . '-' . $spec['hash'];

    $existing = $api->findPlanBySlug($slug);
    if ($existing !== null && !empty($existing['id'])) {
        return $existing['id'];
    }

    $productName = !empty($params['packagename']) ? $params['packagename'] : ('WHMCS Product ' . $pid);
    // Plan names are unique per owner on the panel; suffix the version hash so
    // a resource change (new version) never collides with the previous one.
    $create = array_merge(array(
        'name'        => 'WHMCS: ' . $productName . ' [' . $spec['hash'] . ']',
        'slug'        => $slug,
        'description' => 'Managed by the WHMCS Panelica module. Do not edit in the panel; changes are overwritten from WHMCS product settings.',
    ), $spec['basic']);

    $resp = $api->createPlan($create);
    if (empty($resp['data']['id'])) {
        throw new Exception('Plan creation did not return a plan id.');
    }
    $planId = $resp['data']['id'];

    // Second call sets cgroups v2 + PHP columns the create endpoint does not accept.
    $api->updatePlan($planId, $spec['advanced']);

    // Verify the advanced limits really landed (no silent partial plan).
    $check = $api->findPlanBySlug($slug);
    foreach (array('cpu_limit_percent', 'memory_limit_mb', 'process_limit') as $col) {
        if (!isset($check[$col]) || (int) $check[$col] !== (int) $spec['advanced'][$col]) {
            throw new Exception(sprintf(
                'Managed plan verification failed: %s is %s, expected %s.',
                $col, isset($check[$col]) ? $check[$col] : 'missing', $spec['advanced'][$col]
            ));
        }
    }

    panelica_log('ensureManagedPlan', array('slug' => $slug, 'spec' => $spec), $planId);

    return $planId;
}

/**
 * Garbage-collect superseded managed plan versions of a product.
 * Called AFTER the account has been moved onto the current version — the
 * backend refuses to delete plans that still have accounts, so versions in
 * use by other services of the same product survive untouched.
 */
function panelica_gcManagedPlans(PanelicaAPI $api, array $params)
{
    $pid = isset($params['packageid']) ? (int) $params['packageid'] : 0;
    if ($pid <= 0) {
        return;
    }
    $spec = panelica_managedPlanSpec($params);
    $keepSlug = 'whmcs-p' . $pid . '-' . $spec['hash'];

    try {
        $all = $api->listPlans();
        foreach ((isset($all['data']) ? $all['data'] : array()) as $plan) {
            if (!empty($plan['slug'])
                && strpos($plan['slug'], 'whmcs-p' . $pid . '-') === 0
                && $plan['slug'] !== $keepSlug) {
                try {
                    $api->deletePlan($plan['id']);
                    panelica_log('gcManagedPlans', array('deleted_slug' => $plan['slug']), 'ok');
                } catch (Exception $e) {
                    // Still in use — fine.
                }
            }
        }
    } catch (Exception $e) {
        // GC is best-effort, never fatal.
    }
}

/**
 * Resolve the plan UUID for provisioning.
 * configoption1 holds either the managed-mode sentinel, a plan UUID selected
 * in the dropdown, or (fallback) a manually typed plan name/slug.
 *
 * @throws Exception when the plan cannot be resolved
 */
function panelica_resolvePlanId(PanelicaAPI $api, $configValue, array $params = array())
{
    $value = trim((string) $configValue);
    if ($value === '') {
        throw new Exception('No Panelica plan configured on this product. Edit the product -> Module Settings and select a plan.');
    }

    if ($value === PANELICA_MANAGED_PLAN) {
        return panelica_ensureManagedPlan($api, $params);
    }

    // UUID v4 shape -> use directly.
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
        return $value;
    }

    // Fallback: match by name or slug.
    $resp = $api->listPlans();
    $plans = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
    foreach ($plans as $plan) {
        $name = isset($plan['name']) ? strtolower($plan['name']) : '';
        $slug = isset($plan['slug']) ? strtolower($plan['slug']) : '';
        if ($name === strtolower($value) || $slug === strtolower($value)) {
            return $plan['id'];
        }
    }

    throw new Exception(sprintf('Panelica plan "%s" was not found on the server.', $value));
}

/**
 * Locate the Panelica account for a WHMCS service by username.
 *
 * @return array account data
 * @throws Exception when the account does not exist on the panel
 */
function panelica_requireAccount(PanelicaAPI $api, array $params)
{
    $username = isset($params['username']) ? trim($params['username']) : '';
    if ($username === '') {
        throw new Exception('This service has no username set in WHMCS.');
    }

    $account = $api->findAccountByUsername($username);
    if ($account === null || empty($account['id'])) {
        throw new Exception(sprintf('Account "%s" was not found on the Panelica server.', $username));
    }

    return $account;
}

/**
 * Test connection (Setup -> Servers -> Test Connection).
 */
function panelica_TestConnection(array $params)
{
    try {
        $api = panelica_getApi($params);
        $resp = $api->me();
        panelica_log(__FUNCTION__, $params, $resp);

        $success = isset($resp['status']) && $resp['status'] === 'success';
        if (!$success) {
            return array('success' => false, 'error' => 'Unexpected response from the Panelica API.');
        }

        // Warn early about missing scopes so provisioning failures are not a surprise.
        $scopes = isset($resp['data']['scopes']) && is_array($resp['data']['scopes']) ? $resp['data']['scopes'] : array();
        $missing = panelica_missingScopes($scopes);
        if (!empty($missing)) {
            return array(
                'success' => false,
                'error'   => 'Connected, but the API key is missing required scopes: ' . implode(', ', $missing)
                    . '. Edit the key in the Panelica panel (API Keys page) and add them.',
            );
        }

        return array('success' => true, 'error' => '');
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return array('success' => false, 'error' => $e->getMessage());
    }
}

/**
 * Check a scope list against everything this module needs.
 * Mirrors Panelica wildcard semantics ("accounts:*", "*:*").
 */
function panelica_missingScopes(array $granted)
{
    $required = array('accounts:read', 'accounts:write', 'accounts:delete', 'domains:write', 'plans:read', 'plans:write', 'bandwidth:read');
    $missing = array();
    foreach ($required as $need) {
        $ok = false;
        list($needRes) = explode(':', $need, 2);
        foreach ($granted as $scope) {
            if ($scope === '*:*' || $scope === $need || $scope === $needRes . ':*') {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $missing[] = $need;
        }
    }
    return $missing;
}

/**
 * Create the hosting account.
 */
function panelica_CreateAccount(array $params)
{
    try {
        $api = panelica_getApi($params);
        $planId = panelica_resolvePlanId($api, isset($params['configoption1']) ? $params['configoption1'] : '', $params);

        $username = isset($params['username']) ? trim($params['username']) : '';
        $password = isset($params['password']) ? $params['password'] : '';
        $email    = isset($params['clientsdetails']['email']) ? $params['clientsdetails']['email'] : '';
        $fullName = trim(
            (isset($params['clientsdetails']['firstname']) ? $params['clientsdetails']['firstname'] : '')
            . ' '
            . (isset($params['clientsdetails']['lastname']) ? $params['clientsdetails']['lastname'] : '')
        );

        if ($username === '') {
            throw new Exception('WHMCS did not generate a username for this service.');
        }
        if (strlen($password) < 8) {
            throw new Exception('Panelica requires a password of at least 8 characters. Regenerate the service password.');
        }
        if ($email === '') {
            throw new Exception('The client has no email address; Panelica requires one.');
        }

        // Idempotency guard: if the account already exists, fail with a clear
        // message instead of a confusing API error.
        if ($api->findAccountByUsername($username) !== null) {
            throw new Exception(sprintf('An account named "%s" already exists on the Panelica server.', $username));
        }

        $resp = $api->createAccount($username, $password, $email, $fullName, $planId);
        panelica_log(__FUNCTION__, array('username' => $username, 'email' => $email, 'plan_id' => $planId, 'password' => $password), $resp);

        $accountId = isset($resp['data']['id']) ? (string) $resp['data']['id'] : '';

        // Provision the website for the ordered domain. On failure the account
        // is rolled back so a retry from WHMCS starts from a clean slate.
        $domain = isset($params['domain']) ? strtolower(trim($params['domain'])) : '';
        if ($domain !== '' && $accountId !== '') {
            try {
                $domainResp = $api->createDomain($domain, $accountId);
                panelica_log(__FUNCTION__ . ':domain', array('domain' => $domain, 'account_id' => $accountId), $domainResp);
            } catch (Exception $domainError) {
                try {
                    $api->deleteAccount($accountId);
                } catch (Exception $rollbackError) {
                    panelica_log(__FUNCTION__ . ':rollback', array('account_id' => $accountId), $rollbackError->getMessage());
                }
                throw new Exception(sprintf('Website "%s" could not be created (account rolled back): %s', $domain, $domainError->getMessage()));
            }
        }

        if (($params['configoption1'] ?? '') === PANELICA_MANAGED_PLAN) {
            panelica_gcManagedPlans($api, $params);
        }

        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Suspend the account.
 */
function panelica_SuspendAccount(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $resp = $api->suspendAccount($account['id']);
        panelica_log(__FUNCTION__, array('username' => $params['username'], 'account_id' => $account['id']), $resp);
        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Unsuspend the account.
 */
function panelica_UnsuspendAccount(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $resp = $api->unsuspendAccount($account['id']);
        panelica_log(__FUNCTION__, array('username' => $params['username'], 'account_id' => $account['id']), $resp);
        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Terminate (delete) the account.
 */
function panelica_TerminateAccount(array $params)
{
    try {
        $api = panelica_getApi($params);

        $username = isset($params['username']) ? trim($params['username']) : '';
        $account = $api->findAccountByUsername($username);
        if ($account === null) {
            // Already gone on the panel — treat as success so WHMCS can close
            // the service instead of getting stuck forever.
            panelica_log(__FUNCTION__, array('username' => $username), 'Account not found on panel; treating termination as complete.');
            return 'success';
        }

        $resp = $api->deleteAccount($account['id']);
        panelica_log(__FUNCTION__, array('username' => $username, 'account_id' => $account['id']), $resp);
        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Change the account password.
 */
function panelica_ChangePassword(array $params)
{
    try {
        $newPassword = isset($params['password']) ? $params['password'] : '';
        if (strlen($newPassword) < 8) {
            throw new Exception('Panelica requires a password of at least 8 characters.');
        }

        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $resp = $api->changeAccountPassword($account['id'], $newPassword);
        panelica_log(__FUNCTION__, array('username' => $params['username'], 'account_id' => $account['id'], 'new_password' => $newPassword), $resp);
        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Change package (upgrade/downgrade): switches the Panelica plan.
 */
function panelica_ChangePackage(array $params)
{
    try {
        $api = panelica_getApi($params);
        $planId = panelica_resolvePlanId($api, isset($params['configoption1']) ? $params['configoption1'] : '', $params);
        $account = panelica_requireAccount($api, $params);

        $resp = $api->updateAccount($account['id'], array('plan_id' => $planId));
        panelica_log(__FUNCTION__, array('username' => $params['username'], 'account_id' => $account['id'], 'plan_id' => $planId), $resp);

        if (($params['configoption1'] ?? '') === PANELICA_MANAGED_PLAN) {
            panelica_gcManagedPlans($api, $params);
        }

        return 'success';
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Admin service tab: live account details from the panel.
 */
function panelica_AdminServicesTabFields(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);

        $fields = array(
            'Panelica Account ID' => $account['id'],
            'Panel Status'        => isset($account['status']) ? ucfirst((string) $account['status']) : 'unknown',
        );

        try {
            $disk = $api->getDiskUsage($account['id']);
            if (isset($disk['data']['used_mb'])) {
                $fields['Disk Usage'] = (int) $disk['data']['used_mb'] . ' MB / '
                    . panelica_formatMb((int) $disk['data']['quota_mb']);
            }
        } catch (Exception $e) {
            // Non-fatal: usage endpoint may lack cache yet.
        }

        return $fields;
    } catch (Exception $e) {
        return array('Panelica' => 'Error: ' . $e->getMessage());
    }
}

/**
 * Single sign-on: WHMCS opens the returned URL and the customer lands inside
 * their hosting panel already logged in (one-time token, valid 5 minutes).
 * Triggered by the admin "Login to Panel" button and the client-area button.
 *
 * Requires a *:* scoped key (SSO mints a login session — critical operation).
 */
function panelica_ServiceSingleSignOn(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $resp = $api->ssoLogin($account['id']);
        $url = isset($resp['data']['url']) ? $resp['data']['url'] : '';
        if ($url === '') {
            throw new Exception('The panel did not return a login URL.');
        }
        panelica_log(__FUNCTION__, array('username' => $params['username'], 'account_id' => $account['id']), 'sso url issued');
        return array('success' => true, 'redirectTo' => $url);
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return array('success' => false, 'errorMsg' => $e->getMessage());
    }
}

/**
 * Which self-service tabs an API key can drive, derived from its scopes
 * (returned by /v1/me). Missing scope => that tab is hidden, so a plain
 * billing_integration key still shows a clean Overview-only client area.
 */
function panelica_selfServiceCaps(array $scopes)
{
    $has = function ($need) use ($scopes) {
        list($res) = explode(':', $need, 2);
        foreach ($scopes as $s) {
            if ($s === '*:*' || $s === $need || $s === $res . ':*') {
                return true;
            }
        }
        return false;
    };
    return array(
        'email'     => $has('email:write'),
        'ftp'       => $has('ftp:write'),
        'subdomain' => $has('domains:write'),
        'dns'       => $has('dns:write'),
        'cron'      => $has('accounts:write'),
        'ssl'       => $has('domains:write'),
        'files'     => $has('files:read'),
        'backup'    => $has('backups:read'),
        'mysql'     => $has('databases:write'),
        'redirect'  => $has('domains:write'),
        'settings'  => $has('domains:write'),
        'wordpress' => $has('accounts:read'),
    );
}

/**
 * Client area: live overview + scope-aware self-service tabs
 * (email accounts, FTP accounts, subdomains).
 */
function panelica_ClientArea(array $params)
{
    $host = !empty($params['serverhostname']) ? $params['serverhostname'] : $params['serverip'];
    $port = !empty($params['serverport']) ? (int) $params['serverport'] : 8443;
    $panelUrl = 'https://' . $host . ':' . $port . '/';

    $vars = array(
        'panelUrl'    => $panelUrl,
        'hasStats'    => false,
        'error'       => '',
        'flash'       => panelica_takeFlash(),
        'serviceId'   => isset($params['serviceid']) ? (int) $params['serviceid'] : 0,
        'caps'        => array('email' => false, 'ftp' => false, 'subdomain' => false, 'dns' => false, 'cron' => false, 'ssl' => false, 'files' => false, 'backup' => false, 'mysql' => false, 'redirect' => false),
        'emails'      => array(),
        'ftpAccounts' => array(),
        'subdomains'  => array(),
        'dnsRecords'  => array(),
        'cronJobs'    => array(),
        'sslStatus'   => array(),
        'forwarders'  => array(),
        'autoresponders' => array(),
        'mysqlUsers'  => array(),
        'redirects'   => array(),
        'backups'     => array(),
        'fmFiles'     => array(),
        'fmPath'      => '',
        'fmParent'    => '',
        'fmCrumbs'    => array(),
        'fmRoot'      => '',
        'fmEditPath'  => '',
        'fmEditContent' => '',
        'primaryDomainId'   => '',
        'primaryDomainName' => '',
    );

    // Zero API calls here — the product page renders instantly. Capabilities,
    // dashboard stats and every tab's data all load afterwards via panelica_Api
    // (AJAX, pnl_op=dashboard + per-tab). Tabs render for ALL capabilities and
    // JS hides the ones the API key actually lacks.
    $vars['caps'] = array('email' => true, 'ftp' => true, 'subdomain' => true, 'dns' => true, 'cron' => true, 'ssl' => true, 'files' => true, 'backup' => true, 'mysql' => true, 'redirect' => true, 'settings' => true, 'wordpress' => true);
    $vars['hasStats'] = true;

    return array(
        'templatefile' => 'templates/overview',
        'vars'         => $vars,
    );
}

// -------------------------------------------------------------------
// Client-area self-service actions (custom module functions).
// Reached via clientarea.php?action=productdetails&id=X&modop=custom&a=FN
// -------------------------------------------------------------------

/** Whitelist of client-invocable custom functions (no visible buttons). */
function panelica_ClientAreaAllowedFunctions()
{
    return array(
        'CreateEmail', 'DeleteEmail',
        'CreateFtp', 'DeleteFtp',
        'CreateSubdomain', 'DeleteSubdomain',
        'CreateDnsRecord', 'DeleteDnsRecord',
        'CreateCron', 'DeleteCron',
        'IssueSsl',
        'CreateForwarder', 'DeleteForwarder',
        'CreateAutoresponder', 'DeleteAutoresponder',
        'CreateMysqlUser', 'DeleteMysqlUser',
        'CreateRedirect', 'DeleteRedirect',
        'CreateBackup', 'RestoreBackup', 'DeleteBackup',
        'FmMkdir', 'FmNewFile', 'FmSave', 'FmDelete', 'FmAjax',
        'Api',
    );
}

/** Simple flash message passthrough via PHP session (module-scoped). */
function panelica_setFlash($type, $msg)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['panelica_flash'] = array('type' => $type, 'msg' => $msg);
}
function panelica_takeFlash()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (!empty($_SESSION['panelica_flash'])) {
        $f = $_SESSION['panelica_flash'];
        unset($_SESSION['panelica_flash']);
        return $f;
    }
    return null;
}

/**
 * The client-area tabs whose items can be removed, and the call that removes
 * one. Shared so the two doors onto the same operation - the AJAX endpoint and
 * the form-post handlers - cannot drift apart.
 *
 * @return array<string, string> tab => PanelicaAPI method
 */
function panelica_deletableTabs()
{
    return array(
        'email' => 'deleteEmail', 'forwarders' => 'deleteForwarder', 'autoresponders' => 'deleteAutoresponder',
        'ftp' => 'deleteFtp', 'subdomains' => 'deleteSubdomain', 'dns' => 'deleteDnsRecord', 'cron' => 'deleteCron',
        'redirects' => 'deleteRedirect', 'mysql' => 'deleteMysqlUser', 'backups' => 'deleteBackup',
    );
}

/**
 * Remove one item, but only if this account owns it.
 *
 * The module signs with a server-wide key, so the panel deletes whatever id it
 * is given: nothing on the panel side narrows the call down to one customer.
 * The AJAX endpoint has always checked ownership first; the form-post handlers
 * took the id straight from the request and passed it on, which let a customer
 * holding an id from another account delete that account's mailbox, cron job,
 * database user, DNS record or FTP login. Both doors now ask the same question.
 *
 * @throws Exception when the id is empty, the tab unknown, or the item is not
 *                   this account's
 */
function panelica_deleteOwnedItem(PanelicaAPI $api, array $params, $tab, $id)
{
    $map = panelica_deletableTabs();

    if (!isset($map[$tab])) {
        throw new Exception('Unknown item type.');
    }

    $id = trim((string) $id);

    if ($id === '') {
        throw new Exception('Item not found.');
    }

    list($account, $domainId) = panelica_ctx($api, $params);

    if (!in_array($id, panelica_ownedIds($api, $tab, $account['id'], $domainId), true)) {
        // Deliberately the same wording as a missing item: whether an id exists
        // on the panel is not this customer's business.
        throw new Exception('Item not found.');
    }

    $method = $map[$tab];

    return $api->$method($id);
}

/**
 * Confirm a backup belongs to this account before restoring or deleting it.
 *
 * Backups are server-level, so the same reasoning applies as above, with the
 * rule panelica_backupOwnedByAccount states: every domain inside the archive
 * has to be one of this account's, and unknown scope counts as not ours.
 *
 * @throws Exception when the backup is not this account's
 */
function panelica_requireOwnedBackup(PanelicaAPI $api, array $params, $filename)
{
    $filename = trim((string) $filename);

    if ($filename === '') {
        throw new Exception('Backup not found.');
    }

    $account = panelica_requireAccount($api, $params);
    $acctDomains = panelica_acctDomainNames($api->listAccountDomains($account['id']));

    foreach (($api->listBackups()['data'] ?? array()) as $backup) {
        $name = isset($backup['filename']) ? $backup['filename'] : (isset($backup['name']) ? $backup['name'] : '');

        if ($name === $filename) {
            if (panelica_backupOwnedByAccount($backup, $acctDomains)) {
                return true;
            }

            break;
        }
    }

    throw new Exception('Backup not found.');
}

/** Resolve the account + its primary domain id for a self-service action. */
function panelica_ctx(PanelicaAPI $api, array $params)
{
    $account = panelica_requireAccount($api, $params);
    $dr = $api->listAccountDomains($account['id']);
    $domains = isset($dr['data']) && is_array($dr['data']) ? $dr['data'] : array();
    $primaryDomainId = !empty($domains[0]['id']) ? $domains[0]['id'] : '';
    return array($account, $primaryDomainId);
}

function panelica_CreateEmail(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') {
            throw new Exception('No website found for this account yet.');
        }
        $user = isset($_POST['email_user']) ? trim($_POST['email_user']) : '';
        $pass = isset($_POST['email_pass']) ? (string) $_POST['email_pass'] : '';
        $quota = isset($_POST['email_quota']) ? (int) $_POST['email_quota'] : 0;
        if ($user === '') {
            throw new Exception('Please enter a mailbox name.');
        }
        if (strlen($pass) < 8) {
            throw new Exception('Password must be at least 8 characters.');
        }
        $api->createEmail($domainId, $user, $pass, $quota);
        panelica_setFlash('success', 'Email account created.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

function panelica_DeleteEmail(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') {
            throw new Exception('Missing email id.');
        }
        panelica_deleteOwnedItem($api, $params, 'email', $id);
        panelica_setFlash('success', 'Email account deleted.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

function panelica_CreateFtp(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') {
            throw new Exception('No website found for this account yet.');
        }
        $user = isset($_POST['ftp_user']) ? trim($_POST['ftp_user']) : '';
        $pass = isset($_POST['ftp_pass']) ? (string) $_POST['ftp_pass'] : '';
        $dir  = isset($_POST['ftp_dir']) ? trim($_POST['ftp_dir']) : '';
        if ($user === '') {
            throw new Exception('Please enter an FTP username.');
        }
        if (strlen($pass) < 8) {
            throw new Exception('Password must be at least 8 characters.');
        }
        $api->createFtp($account['id'], $domainId, $user, $pass, $dir);
        panelica_setFlash('success', 'FTP account created.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

function panelica_DeleteFtp(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') {
            throw new Exception('Missing FTP id.');
        }
        panelica_deleteOwnedItem($api, $params, 'ftp', $id);
        panelica_setFlash('success', 'FTP account deleted.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

function panelica_CreateSubdomain(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') {
            throw new Exception('No website found for this account yet.');
        }
        $name = isset($_POST['sub_name']) ? trim($_POST['sub_name']) : '';
        if ($name === '') {
            throw new Exception('Please enter a subdomain name.');
        }
        $api->createSubdomain($domainId, $name);
        panelica_setFlash('success', 'Subdomain created.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

function panelica_DeleteSubdomain(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') {
            throw new Exception('Missing subdomain id.');
        }
        panelica_deleteOwnedItem($api, $params, 'subdomains', $id);
        panelica_setFlash('success', 'Subdomain deleted.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

/**
 * Client-area: create a DNS record on the account's primary zone.
 */
function panelica_CreateDnsRecord(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') {
            throw new Exception('No website found for this account yet.');
        }
        $type    = isset($_POST['dns_type']) ? strtoupper(trim($_POST['dns_type'])) : '';
        $name    = isset($_POST['dns_name']) ? trim($_POST['dns_name']) : '';
        $content = isset($_POST['dns_content']) ? trim($_POST['dns_content']) : '';
        $ttl     = isset($_POST['dns_ttl']) && (int) $_POST['dns_ttl'] > 0 ? (int) $_POST['dns_ttl'] : 3600;
        if ($type === '' || $name === '' || $content === '') {
            throw new Exception('Record type, name and content are all required.');
        }
        $api->createDnsRecord($domainId, $type, $name, $content, $ttl);
        panelica_setFlash('success', 'DNS record created.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

function panelica_DeleteDnsRecord(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') {
            throw new Exception('Missing DNS record id.');
        }
        panelica_deleteOwnedItem($api, $params, 'dns', $id);
        panelica_setFlash('success', 'DNS record deleted.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

/**
 * Client-area: create a cron job.
 */
function panelica_CreateCron(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') {
            throw new Exception('No website found for this account yet.');
        }
        $command = isset($_POST['cron_command']) ? trim($_POST['cron_command']) : '';
        $name    = isset($_POST['cron_name']) ? trim($_POST['cron_name']) : 'cron';
        $minute  = isset($_POST['cron_minute']) && $_POST['cron_minute'] !== '' ? trim($_POST['cron_minute']) : '*';
        $hour    = isset($_POST['cron_hour']) && $_POST['cron_hour'] !== '' ? trim($_POST['cron_hour']) : '*';
        $dom     = isset($_POST['cron_dom']) && $_POST['cron_dom'] !== '' ? trim($_POST['cron_dom']) : '*';
        $month   = isset($_POST['cron_month']) && $_POST['cron_month'] !== '' ? trim($_POST['cron_month']) : '*';
        $dow     = isset($_POST['cron_dow']) && $_POST['cron_dow'] !== '' ? trim($_POST['cron_dow']) : '*';
        if ($command === '') {
            throw new Exception('The command to run is required.');
        }
        $api->createCron($domainId, $name, $command, $minute, $hour, $dom, $month, $dow);
        panelica_setFlash('success', 'Cron job created.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

function panelica_DeleteCron(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') {
            throw new Exception('Missing cron job id.');
        }
        panelica_deleteOwnedItem($api, $params, 'cron', $id);
        panelica_setFlash('success', 'Cron job deleted.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

/**
 * Client-area: request a free Let's Encrypt certificate for the primary domain.
 */
function panelica_IssueSsl(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') {
            throw new Exception('No website found for this account yet.');
        }
        $api->issueSsl($domainId);
        panelica_setFlash('success', 'SSL certificate requested — issuance runs in the background and may take a minute.');
    } catch (Exception $e) {
        panelica_setFlash('danger', $e->getMessage());
    }
    return '';
}

/* ---------------- Email forwarders ---------------- */
function panelica_CreateForwarder(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') { throw new Exception('No website found for this account yet.'); }
        $source = isset($_POST['fwd_source']) ? trim($_POST['fwd_source']) : '';
        $dest   = isset($_POST['fwd_dest']) ? trim($_POST['fwd_dest']) : '';
        if ($source === '' || $dest === '') { throw new Exception('Both source and destination are required.'); }
        $api->createForwarder($domainId, $source, $dest);
        panelica_setFlash('success', 'Forwarder created.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_DeleteForwarder(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') { throw new Exception('Missing forwarder id.'); }
        panelica_deleteOwnedItem($api, $params, 'forwarders', $id);
        panelica_setFlash('success', 'Forwarder deleted.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}

/* ---------------- Autoresponders ---------------- */
function panelica_CreateAutoresponder(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') { throw new Exception('No website found for this account yet.'); }
        $emailId = isset($_POST['ar_email_id']) ? trim($_POST['ar_email_id']) : '';
        $subject = isset($_POST['ar_subject']) ? trim($_POST['ar_subject']) : '';
        $message = isset($_POST['ar_message']) ? trim($_POST['ar_message']) : '';
        if ($emailId === '' || $subject === '' || $message === '') { throw new Exception('Email account, subject and message are required.'); }
        $api->createAutoresponder($domainId, $emailId, $subject, $message);
        panelica_setFlash('success', 'Autoresponder created.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_DeleteAutoresponder(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') { throw new Exception('Missing autoresponder id.'); }
        panelica_deleteOwnedItem($api, $params, 'autoresponders', $id);
        panelica_setFlash('success', 'Autoresponder deleted.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}

/* ---------------- MySQL users ---------------- */
function panelica_CreateMysqlUser(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') { throw new Exception('No website found for this account yet.'); }
        $u = isset($_POST['db_user']) ? trim($_POST['db_user']) : '';
        $p = isset($_POST['db_pass']) ? (string) $_POST['db_pass'] : '';
        if ($u === '' || strlen($p) < 8) { throw new Exception('Username and a password of at least 8 characters are required.'); }
        $api->createMysqlUser($domainId, $u, $p);
        panelica_setFlash('success', 'Database user created.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_DeleteMysqlUser(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') { throw new Exception('Missing database user id.'); }
        panelica_deleteOwnedItem($api, $params, 'mysql', $id);
        panelica_setFlash('success', 'Database user deleted.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}

/* ---------------- Redirects ---------------- */
function panelica_CreateRedirect(array $params)
{
    try {
        $api = panelica_getApi($params);
        list($account, $domainId) = panelica_ctx($api, $params);
        if ($domainId === '') { throw new Exception('No website found for this account yet.'); }
        $src  = isset($_POST['rdr_source']) ? trim($_POST['rdr_source']) : '';
        $dst  = isset($_POST['rdr_dest']) ? trim($_POST['rdr_dest']) : '';
        $type = isset($_POST['rdr_type']) ? trim($_POST['rdr_type']) : '301';
        if ($src === '' || $dst === '') { throw new Exception('Source path and destination URL are required.'); }
        $api->createRedirect($domainId, $src, $dst, $type);
        panelica_setFlash('success', 'Redirect created.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_DeleteRedirect(array $params)
{
    try {
        $api = panelica_getApi($params);
        $id = isset($_POST['id']) ? trim($_POST['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if ($id === '') { throw new Exception('Missing redirect id.'); }
        panelica_deleteOwnedItem($api, $params, 'redirects', $id);
        panelica_setFlash('success', 'Redirect deleted.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}

/* ---------------- Backups ---------------- */
function panelica_CreateBackup(array $params)
{
    try {
        $api = panelica_getApi($params);
        panelica_requireAccount($api, $params);
        $name = isset($_POST['backup_name']) ? trim($_POST['backup_name']) : '';
        $api->createBackup(array(), $name);
        panelica_setFlash('success', 'Backup started — it runs in the background.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_RestoreBackup(array $params)
{
    try {
        $api = panelica_getApi($params);
        $file = isset($_POST['filename']) ? trim($_POST['filename']) : '';
        if ($file === '') { throw new Exception('Missing backup filename.'); }
        panelica_requireOwnedBackup($api, $params, $file);
        $api->restoreBackup($file);
        panelica_setFlash('success', 'Restore started — it runs in the background.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_DeleteBackup(array $params)
{
    try {
        $api = panelica_getApi($params);
        $file = isset($_POST['filename']) ? trim($_POST['filename']) : '';
        if ($file === '') { throw new Exception('Missing backup filename.'); }
        panelica_requireOwnedBackup($api, $params, $file);
        $api->deleteBackup($file);
        panelica_setFlash('success', 'Backup deleted.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}

/* ---------------- File manager ---------------- */
function panelica_FmMkdir(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $path = isset($_POST['fm_path']) ? trim($_POST['fm_path']) : '';
        $name = isset($_POST['fm_name']) ? trim($_POST['fm_name']) : '';
        if ($path === '' || $name === '') { throw new Exception('Folder name is required.'); }
        $api->createFile($account['id'], $path, $name, 'folder');
        panelica_setFlash('success', 'Folder created.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_FmNewFile(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $path = isset($_POST['fm_path']) ? trim($_POST['fm_path']) : '';
        $name = isset($_POST['fm_name']) ? trim($_POST['fm_name']) : '';
        if ($path === '' || $name === '') { throw new Exception('File name is required.'); }
        $api->createFile($account['id'], $path, $name, 'file');
        panelica_setFlash('success', 'File created.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_FmSave(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $path = isset($_POST['fm_file']) ? trim($_POST['fm_file']) : '';
        $content = isset($_POST['fm_content']) ? (string) $_POST['fm_content'] : '';
        if ($path === '') { throw new Exception('Missing file path.'); }
        $api->writeFileContent($account['id'], $path, $content);
        panelica_setFlash('success', 'File saved.');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}
function panelica_FmDelete(array $params)
{
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $path = isset($_POST['fm_target']) ? trim($_POST['fm_target']) : '';
        if ($path === '') { throw new Exception('Missing target path.'); }
        $api->deleteFiles($account['id'], array($path), false);
        panelica_setFlash('success', 'Deleted (moved to trash).');
    } catch (Exception $e) { panelica_setFlash('danger', $e->getMessage()); }
    return '';
}

/**
 * Universal client-area AJAX endpoint (list / create / delete / restore / ssl_issue).
 * Powers lazy-loaded tabs so the product page renders instantly and each tab
 * fetches its own data on demand. Returns JSON and exits. WHMCS session-protected.
 */
function panelica_Api(array $params)
{
    // Discard any page output WHMCS has buffered so our JSON is the only body
    // (WHMCS buffers the full page on POST; without this the response is HTML).
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    // Release the PHP session write-lock right away. WHMCS has already
    // authenticated the client and verified service ownership before dispatching
    // here, and this AJAX handler never writes to $_SESSION. Without this the 4+
    // parallel requests the client area fires when a tab opens serialize behind
    // the per-request session lock (each ~1-3s) — the Email pane took ~14s to
    // finish. Closing the lock lets those requests run truly in parallel.
    if (function_exists('session_write_close')) { @session_write_close(); }
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $aid = $account['id'];
        $dr = $api->listAccountDomains($aid);
        $did = !empty($dr['data'][0]['id']) ? $dr['data'][0]['id'] : '';
        $op = isset($_REQUEST['pnl_op']) ? $_REQUEST['pnl_op'] : 'list';
        $tab = isset($_REQUEST['pnl_tab']) ? $_REQUEST['pnl_tab'] : '';
        $P = function ($k, $d = '') { return isset($_POST[$k]) ? $_POST[$k] : $d; };

        if ($op === 'delete') {
            $id = $P('pnl_id'); // NOT "id" — WHMCS reads the service id from $_REQUEST['id'].
            $map = panelica_deletableTabs();
            if (!isset($map[$tab])) { throw new Exception('Unknown item type.'); }
            // Ownership guard (IDOR): the module holds a root key, so the panel
            // will happily delete ANY item by id — verify the id belongs to THIS
            // account before deleting.
            if ($tab === 'backups') {
                // Backups are server-level: owned only if every contained domain
                // is this account's (same guard as restore).
                $acctDomains = panelica_acctDomainNames($dr);
                $owned = false;
                foreach (($api->listBackups()['data'] ?? array()) as $b) {
                    if (($b['filename'] ?? $b['name'] ?? '') === $id) { $owned = panelica_backupOwnedByAccount($b, $acctDomains); break; }
                }
                if (!$owned) { throw new Exception('Backup not found.'); }
            } else {
                if ($id === '' || !in_array($id, panelica_ownedIds($api, $tab, $aid, $did), true)) {
                    throw new Exception('Item not found.');
                }
            }
            $m = $map[$tab]; $api->$m($id);
            echo json_encode(array('ok' => true)); exit;
        }
        if ($op === 'dashboard') {
            $stats = $api->getAccountStats($aid);
            $disk = $api->getDiskUsage($aid);
            $me = $api->me();
            $scopes = isset($me['data']['scopes']) && is_array($me['data']['scopes']) ? $me['data']['scopes'] : array();
            $caps = panelica_selfServiceCaps($scopes);
            $usedMb = isset($disk['data']['used_mb']) ? (int) $disk['data']['used_mb'] : 0;
            $quotaMb = isset($disk['data']['quota_mb']) ? (int) $disk['data']['quota_mb'] : 0;
            $d0 = isset($dr['data'][0]) && is_array($dr['data'][0]) ? $dr['data'][0] : array();
            $dname = !empty($d0['domain_name']) ? $d0['domain_name'] : (!empty($d0['name']) ? $d0['name'] : (!empty($d0['domain']) ? $d0['domain'] : ''));
            echo json_encode(array('ok' => true, 'caps' => $caps, 'domain_name' => $dname,
                'disk_used' => $usedMb, 'disk_quota' => panelica_formatMb($quotaMb),
                'disk_pct' => ($quotaMb > 0 ? min(100, (int) round($usedMb * 100 / $quotaMb)) : 0),
                'bandwidth' => isset($stats['data']['bandwidth_mb']) ? (int) $stats['data']['bandwidth_mb'] : 0,
                'domain_count' => isset($stats['data']['domain_count']) ? (int) $stats['data']['domain_count'] : 0,
                'email_count' => isset($stats['data']['email_count']) ? (int) $stats['data']['email_count'] : 0,
                'database_count' => isset($stats['data']['database_count']) ? (int) $stats['data']['database_count'] : 0,
                'ftp_count' => isset($stats['data']['ftp_count']) ? (int) $stats['data']['ftp_count'] : 0));
            exit;
        }
        if ($op === 'restore') {
            // Guard: only restore a backup that contains solely this account's
            // domains. Restoring a server-wide backup would overwrite every
            // account on the host — never allow that from the client area.
            $fn = $P('pnl_id');
            $acctDomains = panelica_acctDomainNames($dr);
            $owned = false;
            foreach (($api->listBackups()['data'] ?? array()) as $b) {
                if (($b['filename'] ?? $b['name'] ?? '') === $fn) { $owned = panelica_backupOwnedByAccount($b, $acctDomains); break; }
            }
            if (!$owned) { throw new Exception('Backup not found.'); }
            $api->restoreBackup($fn); echo json_encode(array('ok' => true)); exit;
        }
        if ($op === 'ssl_issue') { if ($did === '') { throw new Exception('No website yet.'); } $api->issueSsl($did); echo json_encode(array('ok' => true)); exit; }
        if ($op === 'wp_login') {
            $r = $api->wpAutoLogin($aid, $P('pnl_id'));
            echo json_encode(array('ok' => true, 'url' => $r['data']['login_url'] ?? '')); exit;
        }
        if ($op === 'wp_plugins') {
            $r = $api->wpUpdatePlugins($aid, $P('pnl_id'));
            echo json_encode(array('ok' => true, 'msg' => $r['data']['result'] ?? 'Plugins updated.')); exit;
        }
        if ($op === 'wp_core') {
            $api->wpUpdateCore($aid, $P('pnl_id'));
            echo json_encode(array('ok' => true, 'msg' => 'WordPress core updated.')); exit;
        }
        if ($op === 'wp_backups') {
            $r = $api->wpListBackups($aid, $P('pnl_id'));
            echo json_encode(array('ok' => true, 'backups' => $r['data']['backups'] ?? array())); exit;
        }
        if ($op === 'wp_backup') {
            $r = $api->wpCreateBackup($aid, $P('pnl_id'));
            echo json_encode(array('ok' => true, 'msg' => 'Backup started.', 'backup' => $r['data']['backup'] ?? null)); exit;
        }
        if ($op === 'wp_restore') {
            $api->wpRestoreBackup($aid, $P('pnl_id'), $P('backup_id'));
            echo json_encode(array('ok' => true, 'msg' => 'Restore completed.')); exit;
        }
        if ($op === 'dkim_enable') {
            if ($did === '') { throw new Exception('No website yet.'); }
            $r = $api->enableDkim($did);
            echo json_encode(array('ok' => true, 'msg' => 'DKIM signing enabled.', 'record' => $r['data'] ?? null)); exit;
        }
        if ($op === 'php_save') {
            if ($did === '') { throw new Exception('No website yet.'); }
            // Only the PHP VERSION is user-editable. Memory/exec/upload limits are
            // governed by the hosting plan — we preserve the current values so the
            // client can never raise them past their plan.
            $cur = $api->getDomainPhp($did)['data'] ?? array();
            $api->updateDomainPhp($did, array(
                'php_version' => $P('php_version'),
                'memory_limit' => isset($cur['memory_limit']) ? $cur['memory_limit'] : '',
                'max_execution_time' => isset($cur['max_execution_time']) ? (int) $cur['max_execution_time'] : 0,
                'upload_max_filesize' => isset($cur['upload_max_filesize']) ? $cur['upload_max_filesize'] : '',
                'post_max_size' => isset($cur['post_max_size']) ? $cur['post_max_size'] : '',
            ));
            echo json_encode(array('ok' => true)); exit;
        }

        if ($op === 'create') {
            if ($tab === 'email') { $api->createEmail($did, trim($P('email_user')), $P('email_pass'), (int) $P('email_quota', 0)); }
            elseif ($tab === 'forwarders') { $api->createForwarder($did, trim($P('fwd_source')), trim($P('fwd_dest'))); }
            elseif ($tab === 'autoresponders') { $api->createAutoresponder($did, $P('ar_email_id'), trim($P('ar_subject')), trim($P('ar_message'))); }
            elseif ($tab === 'ftp') { $api->createFtp($aid, $did, trim($P('ftp_user')), $P('ftp_pass'), trim($P('ftp_dir'))); }
            elseif ($tab === 'subdomains') { $api->createSubdomain($did, trim($P('sub_name'))); }
            elseif ($tab === 'dns') { $api->createDnsRecord($did, strtoupper(trim($P('dns_type'))), trim($P('dns_name')), trim($P('dns_content')), (int) $P('dns_ttl', 3600)); }
            elseif ($tab === 'cron') { $c = trim($P('cron_command')); if ($c === '') { throw new Exception('Command is required.'); } $api->createCron($did, $P('cron_name', 'cron'), $c, $P('cron_minute', '*'), $P('cron_hour', '*'), $P('cron_dom', '*'), $P('cron_month', '*'), $P('cron_dow', '*')); }
            elseif ($tab === 'redirects') { $api->createRedirect($did, trim($P('rdr_source')), trim($P('rdr_dest')), $P('rdr_type', '301')); }
            elseif ($tab === 'mysql') { $u = trim($P('db_user')); $pw = $P('db_pass'); if ($u === '' || strlen($pw) < 8) { throw new Exception('Username and 8+ char password required.'); } $api->createMysqlUser($did, $u, $pw); }
            elseif ($tab === 'backups') {
                // Scope the backup to THIS account's domains only. Passing an empty
                // domain list makes the panel create a server-wide backup (all
                // accounts) — a client must never be able to do that.
                $bdids = array();
                foreach (($dr['data'] ?? array()) as $d) { if (is_array($d) && !empty($d['id'])) { $bdids[] = $d['id']; } }
                if (empty($bdids)) { throw new Exception('No domains available to back up.'); }
                $api->createBackup($bdids, trim($P('backup_name')));
            }
            else { throw new Exception('Unknown item type.'); }
            echo json_encode(array('ok' => true)); exit;
        }

        // op === 'list'
        $rows = array(); $extra = array();
        if ($tab === 'email') {
            foreach (($api->listAccountEmails($aid)['data'] ?? array()) as $e) {
                $addr = $e['email'] ?? $e['email_address'] ?? '—';
                $rows[] = array('id' => $e['id'] ?? '', 'label' => $addr, 'cells' => array($addr, (($e['quota_mb'] ?? 0) > 0 ? ($e['quota_mb'] . ' MB') : 'Unlimited')));
            }
        } elseif ($tab === 'forwarders' && $did !== '') {
            foreach (($api->listForwarders($did)['data'] ?? array()) as $f) {
                $src = $f['source_email'] ?? $f['source'] ?? $f['source_address'] ?? '—';
                $dst = (isset($f['destination_emails']) && is_array($f['destination_emails'])) ? implode(', ', $f['destination_emails']) : ($f['destination'] ?? '—');
                $rows[] = array('id' => $f['id'] ?? '', 'cells' => array($src, $dst));
            }
        } elseif ($tab === 'autoresponders' && $did !== '') {
            foreach (($api->listAutoresponders($did)['data'] ?? array()) as $a) {
                // The mailbox address lives on the nested email_account object; the
                // autoresponder row itself has no top-level "email" field.
                $mbx = $a['email'] ?? $a['email_address'] ?? (isset($a['email_account']['email']) ? $a['email_account']['email'] : '—');
                $rows[] = array('id' => $a['id'] ?? '', 'cells' => array($mbx, $a['subject'] ?? '—'));
            }
        } elseif ($tab === 'ftp') {
            foreach (($api->listFtp()['data'] ?? array()) as $f) {
                if ((isset($f['user_id']) && $f['user_id'] === $aid) || (isset($f['domain_id']) && $f['domain_id'] === $did)) {
                    $rows[] = array('id' => $f['id'] ?? '', 'cells' => array($f['ftp_username'] ?? $f['username'] ?? '—', $f['directory'] ?? $f['home_directory'] ?? '/'));
                }
            }
        } elseif ($tab === 'subdomains' && $did !== '') {
            foreach (($api->listSubdomains($did)['data'] ?? array()) as $s) {
                $rows[] = array('id' => $s['id'] ?? '', 'cells' => array($s['subdomain_name'] ?? $s['name'] ?? $s['fqdn'] ?? '—'));
            }
        } elseif ($tab === 'dns' && $did !== '') {
            foreach (($api->listDnsRecords($did)['data'] ?? array()) as $r) {
                $rows[] = array('id' => $r['id'] ?? '', 'cells' => array($r['type'] ?? '', $r['name'] ?? '', $r['content'] ?? '', (string) ($r['ttl'] ?? '')));
            }
        } elseif ($tab === 'cron') {
            foreach (($api->listCron()['data'] ?? array()) as $j) {
                if ((isset($j['user_id']) && $j['user_id'] === $aid) || (isset($j['domain_id']) && $j['domain_id'] === $did) || (!isset($j['user_id']) && !isset($j['domain_id']))) {
                    $sched = ($j['minute'] ?? '*') . ' ' . ($j['hour'] ?? '*') . ' ' . ($j['day_of_month'] ?? '*') . ' ' . ($j['month'] ?? '*') . ' ' . ($j['day_of_week'] ?? '*');
                    $rows[] = array('id' => $j['id'] ?? '', 'cells' => array($j['task_name'] ?? $j['name'] ?? '—', $j['command'] ?? '—', $sched));
                }
            }
        } elseif ($tab === 'redirects' && $did !== '') {
            foreach (($api->listRedirects($did)['data'] ?? array()) as $r) {
                $rows[] = array('id' => $r['id'] ?? '', 'cells' => array($r['source_path'] ?? $r['source'] ?? '—', $r['destination_url'] ?? $r['destination'] ?? '—', $r['redirect_type'] ?? '301'));
            }
        } elseif ($tab === 'mysql') {
            foreach (($api->listMysqlUsers()['data'] ?? array()) as $u) {
                if ((isset($u['user_id']) && $u['user_id'] === $aid) || (isset($u['domain_id']) && $u['domain_id'] === $did) || (!isset($u['user_id']) && !isset($u['domain_id']))) {
                    $rows[] = array('id' => $u['id'] ?? '', 'cells' => array($u['username'] ?? $u['name'] ?? '—'));
                }
            }
        } elseif ($tab === 'backups') {
            // Only show backups whose contents are limited to this account's own
            // domains (hides server-wide + other accounts' backups).
            $acctDomains = panelica_acctDomainNames($dr);
            foreach (($api->listBackups()['data'] ?? array()) as $b) {
                if (!panelica_backupOwnedByAccount($b, $acctDomains)) { continue; }
                $fn = $b['filename'] ?? $b['name'] ?? '';
                $sz = isset($b['size_mb']) ? panelica_formatMb((int) round($b['size_mb'])) : ($b['size_formatted'] ?? $b['size'] ?? '—');
                $rows[] = array('id' => $fn, 'cells' => array($fn, $sz, $b['created_at'] ?? $b['date'] ?? ''));
            }
        } elseif ($tab === 'ssl' && $did !== '') {
            $s = $api->getSsl($did)['data'] ?? array();
            $extra['ssl'] = array('has_ssl' => !empty($s['has_ssl']), 'domain_name' => $s['domain_name'] ?? '');
        } elseif ($tab === 'settings' && $did !== '') {
            $extra['settings'] = array(
                'php'      => $api->getDomainPhp($did)['data'] ?? array(),
                'versions' => $api->listPhpVersions()['data'] ?? array(),
            );
        } elseif ($tab === 'wordpress') {
            foreach (($api->listWordPress($aid)['data'] ?? array()) as $w) {
                $rows[] = array('id' => $w['id'] ?? '', 'cells' => array(
                    $w['site_url'] ?? $w['url'] ?? '—',
                    $w['php_version'] ?? '—',
                    $w['status'] ?? '—',
                ));
            }
        } elseif ($tab === 'deliverability' && $did !== '') {
            // Single round-trip: SPF + DKIM in one call (was 2 sequential calls).
            $dv = $api->getDeliverability($did)['data'] ?? array();
            $dk = isset($dv['dkim']) && is_array($dv['dkim']) ? $dv['dkim'] : array();
            $sp = isset($dv['spf']) && is_array($dv['spf']) ? $dv['spf'] : array();
            $dn = !empty($dv['domain_name']) ? $dv['domain_name'] : (isset($dr['data'][0]['domain_name']) ? $dr['data'][0]['domain_name'] : '');
            $extra['deliverability'] = array(
                'domain_name' => $dn,
                'dkim' => array('enabled' => !empty($dk['enabled']), 'public_key' => isset($dk['public_key']) ? $dk['public_key'] : ''),
                'spf'  => array('enabled' => !empty($sp['enabled']), 'record' => isset($sp['record']) ? $sp['record'] : ''),
            );
        }
        echo json_encode(array('ok' => true, 'rows' => $rows) + $extra); exit;
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'error' => $e->getMessage())); exit;
    }
}

/**
 * AJAX file-manager backend: returns JSON and exits (no full page reload).
 * Protected by the WHMCS client session (the customer must own this service).
 * ops: list | read | mkdir | newfile | save | delete
 */
function panelica_FmAjax(array $params)
{
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    // Release the session write-lock so concurrent file-manager requests don't
    // serialize behind it (see panelica_Api for the full rationale).
    if (function_exists('session_write_close')) { @session_write_close(); }
    try {
        $api = panelica_getApi($params);
        $account = panelica_requireAccount($api, $params);
        $uid = $account['id'];

        // Resolve + enforce the accessible root — never operate outside it.
        $adr = $api->accessibleDirectories($uid);
        $roots = isset($adr['data']['directories']) && is_array($adr['data']['directories']) ? $adr['data']['directories'] : array();
        $root = !empty($roots[0]) ? rtrim($roots[0], '/') : '';
        $confine = function ($path) use ($root) {
            $path = (string) $path;
            if ($root !== '' && strpos($path, $root) !== 0) {
                return $root;
            }
            return rtrim($path, '/') !== '' ? rtrim($path, '/') : $root;
        };

        $op = isset($_REQUEST['fm_op']) ? $_REQUEST['fm_op'] : 'list';

        if ($op === 'list') {
            $path = $confine(isset($_REQUEST['fm_path']) ? $_REQUEST['fm_path'] : $root);
            $flr = $api->listFiles($uid, $path);
            $files = isset($flr['data']['files']) && is_array($flr['data']['files']) ? $flr['data']['files'] : array();
            $parent = ($path !== $root && strrpos($path, '/') !== false) ? substr($path, 0, strrpos($path, '/')) : '';
            if ($parent !== '' && $root !== '' && strpos($parent, $root) !== 0) { $parent = $root; }
            $crumbs = array();
            if ($root !== '' && strpos($path, $root) === 0) {
                $acc = $root; $crumbs[] = array('name' => basename($root), 'path' => $root);
                $rel = trim(substr($path, strlen($root)), '/');
                if ($rel !== '') { foreach (explode('/', $rel) as $seg) { $acc .= '/' . $seg; $crumbs[] = array('name' => $seg, 'path' => $acc); } }
            }
            echo json_encode(array('ok' => true, 'root' => $root, 'path' => $path, 'parent' => $parent, 'crumbs' => $crumbs, 'files' => $files));
        } elseif ($op === 'read') {
            $path = $confine(isset($_REQUEST['fm_path']) ? $_REQUEST['fm_path'] : '');
            $cr = $api->readFileContent($uid, $path);
            echo json_encode(array('ok' => true, 'path' => $path, 'content' => isset($cr['data']['content']) ? $cr['data']['content'] : ''));
        } elseif ($op === 'mkdir' || $op === 'newfile') {
            $path = $confine(isset($_POST['fm_path']) ? $_POST['fm_path'] : $root);
            $name = isset($_POST['fm_name']) ? trim($_POST['fm_name']) : '';
            if ($name === '') { throw new Exception('Name is required.'); }
            $api->createFile($uid, $path, $name, $op === 'mkdir' ? 'folder' : 'file');
            echo json_encode(array('ok' => true));
        } elseif ($op === 'save') {
            $path = $confine(isset($_POST['fm_file']) ? $_POST['fm_file'] : '');
            $content = isset($_POST['fm_content']) ? (string) $_POST['fm_content'] : '';
            $api->writeFileContent($uid, $path, $content);
            echo json_encode(array('ok' => true));
        } elseif ($op === 'delete') {
            $path = $confine(isset($_POST['fm_target']) ? $_POST['fm_target'] : '');
            if ($path === $root) { throw new Exception('Cannot delete the root directory.'); }
            $api->deleteFiles($uid, array($path), false);
            echo json_encode(array('ok' => true));
        } else {
            echo json_encode(array('ok' => false, 'error' => 'Unknown operation.'));
        }
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
    }
    exit;
}

/**
 * Nightly usage sync (WHMCS "Usage Update" cron for the whole server).
 * Fills disk/bandwidth usage + limits on every Panelica service of this server.
 */
function panelica_UsageUpdate(array $params)
{
    try {
        return panelica_usageUpdateWith(panelica_getApi($params), isset($params['serverid']) ? $params['serverid'] : 0);
    } catch (Exception $e) {
        panelica_log(__FUNCTION__, $params, $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * The work behind panelica_UsageUpdate, with the API client handed in.
 *
 * Kept separate so the sync can be exercised against a stubbed panel: this is
 * the one job that writes to every service row on the server, and a mistake
 * here is silent.
 *
 * @param PanelicaAPI $api      talking to the panel
 * @param int|string  $serverId WHMCS server id whose services are synced
 * @return string 'success' or an error message for WHMCS
 */
function panelica_usageUpdateWith(PanelicaAPI $api, $serverId)
{
    try {
        // Map plan UUID -> monthly bandwidth quota (for bwlimit).
        $planBwMb = array();
        try {
            $plansResp = $api->listPlans();
            foreach ((isset($plansResp['data']) ? $plansResp['data'] : array()) as $plan) {
                if (!empty($plan['id'])) {
                    $planBwMb[$plan['id']] = isset($plan['monthly_bandwidth_mb']) ? (int) $plan['monthly_bandwidth_mb'] : 0;
                }
            }
        } catch (Exception $e) {
            // Plans unavailable — bwlimit will simply not be updated.
        }

        // Map username -> panel account.
        $accountsResp = $api->listAccounts();
        $byUsername = array();
        foreach ((isset($accountsResp['data']) ? $accountsResp['data'] : array()) as $account) {
            if (!empty($account['username'])) {
                $byUsername[strtolower($account['username'])] = $account;
            }
        }

        $services = Capsule::table('tblhosting')
            ->where('server', $serverId)
            ->whereIn('domainstatus', array('Active', 'Suspended'))
            ->get(array('id', 'username'));

        $updated = 0;
        foreach ($services as $service) {
            $uname = strtolower(trim((string) $service->username));
            if ($uname === '' || !isset($byUsername[$uname])) {
                continue;
            }
            $account = $byUsername[$uname];

            // Stamped only once there is something to stamp. WHMCS shows
            // "last updated" on the service and an operator reads it as "these
            // figures are from last night", so writing it after every call had
            // failed dressed month-old numbers up as fresh ones.
            $row = array();

            try {
                $disk = $api->getDiskUsage($account['id']);
                if (isset($disk['data'])) {
                    $row['diskusage'] = (int) $disk['data']['used_mb'];
                    $row['disklimit'] = (int) $disk['data']['quota_mb'];
                }
            } catch (Exception $e) {
                // skip disk fields for this account
            }

            try {
                $bw = $api->getAccountBandwidth($account['id']);
                if (isset($bw['data']['bytes_used'])) {
                    $row['bwusage'] = (int) round(((int) $bw['data']['bytes_used']) / 1048576);
                }
            } catch (Exception $e) {
                // skip bandwidth fields for this account
            }

            if (!empty($account['plan_id']) && isset($planBwMb[$account['plan_id']])) {
                $row['bwlimit'] = $planBwMb[$account['plan_id']];
            }

            if (empty($row)) {
                continue;
            }

            $row['lastupdate'] = date('Y-m-d H:i:s');
            Capsule::table('tblhosting')->where('id', $service->id)->update($row);
            $updated++;
        }

        panelica_log('panelica_UsageUpdate', array('serverid' => $serverId), 'Updated usage for ' . $updated . ' service(s)');
        return 'success';
    } catch (Exception $e) {
        panelica_log('panelica_UsageUpdate', array('serverid' => $serverId), $e->getMessage());
        return $e->getMessage();
    }
}
