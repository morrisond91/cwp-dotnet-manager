<?php
/**
 * DotNetManager Nginx Config Generator
 * Generates nginx vhost configs from template files
 */
class DotNetManagerNginx {
    private $serverIP;
    private $nginxVhostsDir;
    private $templateDir;

    public function __construct($nginxVhostsDir = '/etc/nginx/conf.d/vhosts', $templateDir = '/usr/local/cwp/.conf/dotnetmanager/templates') {
        $this->nginxVhostsDir = $nginxVhostsDir;
        $this->templateDir = $templateDir;
        // Get primary server IP
        $ip = shell_exec("hostname -I 2>/dev/null | awk '{print \$1}' | tr -d '\\n'");
        $this->serverIP = trim($ip);
        if (empty($this->serverIP)) {
            $this->serverIP = '0.0.0.0';
        }
    }

    public function getServerIP() {
        return $this->serverIP;
    }

    private function renderTemplate($templateFile, $vars) {
        if (!file_exists($templateFile)) {
            throw new Exception("Nginx template not found: $templateFile");
        }
        $content = file_get_contents($templateFile);
        foreach ($vars as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }
        return $content;
    }

    public function generateNginxConfig($domain, $port, $docRoot) {
        if (!is_dir($this->nginxVhostsDir)) {
            mkdir($this->nginxVhostsDir, 0755, true);
        }

        $domainSafe = escapeshellarg($domain);
        $confFile = $this->nginxVhostsDir . '/' . $domain . '.conf';
        $sslConfFile = $this->nginxVhostsDir . '/' . $domain . '.ssl.conf';

        // Check if SSL cert exists
        $sslCert = '/etc/pki/tls/certs/' . $domain . '.bundle';
        $sslKey = '/etc/pki/tls/private/' . $domain . '.key';
        $hasSSL = file_exists($sslCert) && file_exists($sslKey);

        // Common template variables
        $vars = [
            'SERVERIP' => $this->serverIP,
            'DOMAIN'   => $domain,
            'PORT'     => (int)$port,
            'DOCROOT'  => $docRoot
        ];

        // Render HTTP config
        $httpTemplate = $this->templateDir . '/nginx_http.template';
        $httpConfig = $this->renderTemplate($httpTemplate, $vars);
        file_put_contents($confFile, $httpConfig);

        // Render SSL config
        if ($hasSSL) {
            $sslTemplate = $this->templateDir . '/nginx_ssl.template';
            $sslVars = array_merge($vars, [
                'SSL_CERT' => $sslCert,
                'SSL_KEY'  => $sslKey
            ]);
            $sslConfig = $this->renderTemplate($sslTemplate, $sslVars);
            file_put_contents($sslConfFile, $sslConfig);
        } else {
            // Remove stale SSL config if cert no longer exists
            if (file_exists($sslConfFile)) {
                unlink($sslConfFile);
            }
        }

        return ['http' => $confFile, 'ssl' => $hasSSL ? $sslConfFile : null];
    }

    public function removeNginxConfig($domain) {
        $confFile = $this->nginxVhostsDir . '/' . $domain . '.conf';
        $sslConfFile = $this->nginxVhostsDir . '/' . $domain . '.ssl.conf';
        if (file_exists($confFile)) unlink($confFile);
        if (file_exists($sslConfFile)) unlink($sslConfFile);
    }

    public function reloadNginx() {
        shell_exec('nginx -t 2>&1 && systemctl reload nginx 2>&1');
    }
}
