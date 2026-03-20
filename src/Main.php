<?php

declare(strict_types=1);

namespace floxygit\WebPanel;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;

class Main extends PluginBase {

    private $socket;
    private Config $panelData;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->panelData = new Config($this->getDataFolder() . "panelData.json", Config::JSON, ["lang" => "en"]);
        
        $port = (int)$this->getConfig()->get("appPort", 7800);
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($this->socket, "0.0.0.0", $port) && @socket_listen($this->socket)) {
            socket_set_nonblock($this->socket);
            $this->getScheduler()->scheduleRepeatingTask(new class($this->socket, $this->panelData) extends Task {
                private $s;
                private $data;
                public function __construct($s, $data) { $this->s = $s; $this->data = $data; }
                
                public function onRun(): void {
                    if (($client = @socket_accept($this->s)) !== false) {
                        $buffer = socket_read($client, 2048);
                        
                        if (str_contains($buffer, "GET /?setlang=de")) {
                            $this->data->set("lang", "de");
                            $this->data->save();
                        } elseif (str_contains($buffer, "GET /?setlang=en")) {
                            $this->data->set("lang", "en");
                            $this->data->save();
                        }

                        $html = $this->render($this->data->get("lang", "en"));
                        $res = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($html) . "\r\n\r\n" . $html;
                        @socket_write($client, $res, strlen($res));
                        @socket_close($client);
                    }
                }

                private function render(string $lang): string {
                    $isDe = $lang === "de";
                    $title = $isDe ? "Einrichtung" : "Setup";
                    $welcome = $isDe ? "Willkommen beim WebPanel" : "Welcome to WebPanel";
                    $select = $isDe ? "Wähle deine Sprache:" : "Select your language:";
                    
                    return "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>WebPanel</title>
                        <style>
                            body { background: #121212; color: white; font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                            .card { background: #1e1e1e; padding: 2rem; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); text-align: center; width: 300px; }
                            .btn { display: inline-block; margin: 10px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; transition: 0.3s; }
                            .btn:hover { background: #2980b9; }
                            h1 { color: #3498db; }
                            .coming-soon { margin-top: 20px; font-style: italic; color: #888; }
                        </style>
                    </head>
                    <body>
                        <div class='card'>
                            <h1>$welcome</h1>
                            <p>$select</p>
                            <a href='/?setlang=en' class='btn'>English</a>
                            <a href='/?setlang=de' class='btn'>Deutsch</a>
                            <div class='coming-soon'>Coming Soon...</div>
                        </div>
                    </body>
                    </html>";
                }
            }, 1);
        }
    }

    protected function onDisable(): void {
        if ($this->socket) @socket_close($this->socket);
    }
}
