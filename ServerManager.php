<?php

class ServerManager {
    private $baseUrl;
    private $token;

    public function connect($ip, $port, $user, $pass) {
        $daemonPort = ($port == 22 || !$port) ? 8080 : (int)$port;
        $this->baseUrl = "http://{$ip}:{$daemonPort}";
        $this->token = $pass;
        return true;
    }

    private function request($method, $endpoint, $data = null) {
        $ch = curl_init($this->baseUrl . $endpoint);
        $headers = [
            "Authorization: Bearer " . $this->token,
            "Content-Type: application/json"
        ];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? []));
        }
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new Exception("Springs Daemon unreachable ({$this->baseUrl}): {$err}");
        }
        return json_decode($resp, true) ?: [];
    }

    public function exec($command) {
        if (strpos($command, 'rm -rf ') === 0) {
            $path = trim(str_replace('rm -rf ', '', $command), "'\" ");
            $this->request('POST', '/api/servers/wipe', ['install_path' => $path]);
        }
        return '';
    }

    public function isRunning($screenName) {
        $res = $this->request('POST', '/api/servers/status', ['screen_name' => $screenName]);
        return !empty($res['running']);
    }

    public function installServer($game, $installPath, $screenName) {
        $this->request('POST', '/api/servers/install', [
            'game' => $game,
            'install_path' => $installPath,
            'screen_name' => $screenName
        ]);
        return true;
    }

    public function startServer($server) {
        if ($this->isRunning($server['screen_name'])) {
            return "Container is already running.";
        }
        $this->request('POST', '/api/servers/start', $server);
        return "Springs Docker container started.";
    }

    public function stopServer($server) {
        $this->request('POST', '/api/servers/stop', ['screen_name' => $server['screen_name']]);
        return "Springs Docker container stopped.";
    }

    public function sendCommand($screenName, $command) {
        $this->request('POST', '/api/servers/command', [
            'screen_name' => $screenName,
            'command' => $command
        ]);
        return "Command sent to container.";
    }

    public function getConsoleLog($installPath, $lines = 35, $screenName = '') {
        global $selectedServer, $server;
        $sName = $screenName ?: ($selectedServer['screen_name'] ?? ($server['screen_name'] ?? ''));
        $res = $this->request('POST', '/api/servers/console', [
            'screen_name' => $sName,
            'install_path' => $installPath,
            'lines' => (int)$lines
        ]);
        return $res['log'] ?? '[Springs] No console output.';
    }

    public function getSystemStats() {
        $res = $this->request('GET', '/api/stats');
        return $res['stats'] ?? null;
    }
}