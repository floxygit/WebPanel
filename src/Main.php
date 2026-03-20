<?php

declare(strict_types=1);

namespace floxygit\WebPanel;

use pocketmine\plugin\PluginBase;

class Main extends PluginBase {

    private $socket;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $port = (int)$this->getConfig()->get("appPort", 7800);

        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        if (@socket_bind($this->socket, "0.0.0.0", $port) && @socket_listen($this->socket)) {
            socket_set_nonblock($this->socket);
            $this->getScheduler()->scheduleRepeatingTask(new class($this->socket) extends \pocketmine\scheduler\Task {
                private $s;
                public function __construct($s) { $this->s = $s; }
                public function onRun(): void {
                    if (($client = @socket_accept($this->s)) !== false) {
                        $content = "Hello World";
                        $res = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($content) . "\r\n\r\n" . $content;
                        @socket_write($client, $res, strlen($res));
                        @socket_close($client);
                    }
                }
            }, 1);
            $this->getLogger()->info("WebPanel läuft auf Port " . $port);
        }
    }

    protected function onDisable(): void {
        if ($this->socket) {
            @socket_close($this->socket);
        }
    }
}
